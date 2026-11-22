<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Site\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Exception\RouteNotFoundException;
use Joomla\CMS\Router\Route;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpException;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\AlreadyDecided;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\AutoDecided;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\ApprovalModule;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\ApprovalNotFound;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\ApprovalService;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\AttachmentPreview;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\InvalidDecision;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\NotRecordable;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\RateLimitExceeded;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\SubmissionGuard;

/**
 * Feature 4: approve or reject. POST, CSRF token, ACL, one-time token, rate limit.
 * The approval ids come from the form but are re-checked against SDP (approver must be the
 * logged-in user's SDP user) before the decision is sent with the service key.
 */
final class ApprovalController extends BaseController
{
    use IdentityGuardTrait;

    /**
     * Pages are shown only through DisplayController, which checks login, the view's permission
     * and the identity. Joomla also offers every controller's inherited display() as a task
     * (e.g. task={name}.display), which would skip those checks, so it is refused here.
     */
    public function display($cachable = false, $urlparams = [])
    {
        throw new RouteNotFoundException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
    }

    /** Types sent with their own Content-Type; everything else is application/octet-stream. */
    private const DOWNLOAD_TYPES = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'txt'  => 'text/plain; charset=utf-8',
        'csv'  => 'text/csv; charset=utf-8',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip'  => 'application/zip',
    ];

    /**
     * Download one attachment of a record waiting for (or decided by) the user's approval.
     * GET, read only: login, sdp.approval.view, a matched identity, and ApprovalService checks
     * in ServiceDesk Plus that the user is an approver of that record and that the file belongs
     * to it. The file is always sent as a download (never shown inside the site), with nosniff
     * and a sandbox policy, so a file cannot run as a page of this site.
     */
    public function attachment(): void
    {
        if (!$this->requireLogin()) {
            return;
        }

        if (!$this->app->getIdentity()->authorise('sdp.approval.view', 'com_servicedeskplus')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $requestId    = $this->input->getString('request_id', '');
        $levelId      = $this->input->getString('level_id', '');
        $approvalId   = $this->input->getString('id', '');
        $attachmentId = $this->input->getString('file', '');
        $module       = ApprovalModule::normalize($this->input->getCmd('module', '')) ?? ApprovalModule::REQUEST;

        foreach ([$requestId, $levelId, $approvalId, $attachmentId] as $id) {
            if (!preg_match('/^[0-9]{1,20}$/', $id)) {
                throw new RouteNotFoundException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
            }
        }

        $detailUrl = 'index.php?option=com_servicedeskplus&view=approval&id=' . $approvalId
            . '&request_id=' . $requestId . '&level_id=' . $levelId
            . ($module !== ApprovalModule::REQUEST ? '&module=' . $module : '');

        try {
            $identity = $this->currentIdentity();
        } catch (SdpException) {
            $identity = null;
        }

        if ($identity === null || !$identity->isMatched()) {
            $this->setRedirect(Route::_($detailUrl, false));

            return;
        }

        try {
            $file = $this->sdpComponent()->getApprovalService()
                ->attachmentForUser($identity, $requestId, $levelId, $approvalId, $module, $attachmentId);
        } catch (ApprovalNotFound) {
            throw new RouteNotFoundException(Text::_('COM_SERVICEDESKPLUS_APPROVAL_ATTACHMENT_NOT_FOUND'), 404);
        } catch (SdpException $e) {
            $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_APPROVAL_ATTACHMENT_FAILED'), 'error');
            $this->setRedirect(Route::_($detailUrl, false));

            return;
        }

        $this->sendFile($file['name'], $file['content'], $this->input->getBool('inline', false));
    }

    /** The content starts as a file of the type its name says. */
    private static function contentIs(string $type, string $content): bool
    {
        return match ($type) {
            'application/pdf' => str_starts_with(ltrim(substr($content, 0, 1024)), '%PDF-'),
            'image/png'       => str_starts_with($content, "\x89PNG\r\n\x1a\n"),
            'image/jpeg'      => str_starts_with($content, "\xFF\xD8\xFF"),
            'image/gif'       => str_starts_with($content, 'GIF87a') || str_starts_with($content, 'GIF89a'),
            'image/webp'      => str_starts_with($content, 'RIFF') && substr($content, 8, 4) === 'WEBP',
            default           => false,
        };
    }

    private function sendFile(string $name, string $content, bool $inline = false): void
    {
        // A plain file name: no path, no control or quote characters.
        $name      = trim(preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|]+/u', '_', basename(str_replace('\\', '/', $name))) ?? '', " ._");
        $name      = $name !== '' ? $name : 'attachment';
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $ascii     = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'attachment';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Shown in the browser only when asked for, the type allows it and the content matches it.
        $inlineType = $inline ? (AttachmentPreview::INLINE_TYPES[$extension] ?? null) : null;
        $inline     = $inlineType !== null && self::contentIs($inlineType, $content);

        $this->app->clearHeaders();
        $this->app->setHeader('Content-Type', $inline ? $inlineType : (self::DOWNLOAD_TYPES[$extension] ?? 'application/octet-stream'), true);
        $this->app->setHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name), true);
        $this->app->setHeader('Content-Length', (string) \strlen($content), true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        // Nothing in the file may load or run anything. A PDF is left to the browser's own viewer,
        // which a CSP sandbox would block in Chrome and Firefox; those viewers already keep a
        // PDF's own scripts away from this site. It still may not submit forms, change the base
        // address, open this site's pages as its opener or be embedded by another site.
        $this->app->setHeader(
            'Content-Security-Policy',
            $inline && $inlineType === 'application/pdf'
                ? "default-src 'none'; object-src 'self'; style-src 'unsafe-inline'; form-action 'none'; base-uri 'none'; frame-ancestors 'self'"
                : "default-src 'none'; sandbox" . ($inline ? "; frame-ancestors 'self'" : ''),
            true
        );
        $this->app->setHeader('Cross-Origin-Resource-Policy', 'same-origin', true);
        $this->app->setHeader('Cross-Origin-Opener-Policy', 'same-origin', true);
        $this->app->setHeader('Cache-Control', 'private, no-store', true);
        $this->app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $this->app->sendHeaders();

        echo $content;

        $this->app->close();
    }

    public function decide(): void
    {
        if (!$this->requireLogin()) {
            return;
        }

        $this->checkToken();

        $user = $this->app->getIdentity();

        if (!$user->authorise('sdp.approval.view', 'com_servicedeskplus') || !$user->authorise('sdp.approval.decide', 'com_servicedeskplus')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $post       = $this->input->post;
        $requestId  = $post->getString('request_id', '');
        $levelId    = $post->getString('level_id', '');
        $approvalId = $post->getString('approval_id', '');
        $module     = ApprovalModule::normalize($post->getCmd('module', '')) ?? ApprovalModule::REQUEST;
        $decision   = $post->getCmd('decision', '');
        $comment    = (string) $post->getRaw('comment', '');
        $token      = $post->getAlnum('submission', '');

        $detailUrl = 'index.php?option=com_servicedeskplus&view=approval&id=' . rawurlencode($approvalId)
            . '&request_id=' . rawurlencode($requestId) . '&level_id=' . rawurlencode($levelId)
            . ($module !== ApprovalModule::REQUEST ? '&module=' . $module : '');
        $listUrl   = 'index.php?option=com_servicedeskplus&view=approvals';

        $validIds  = array_filter([$approvalId, $requestId, $levelId], static fn (string $id): bool => (bool) preg_match('/^[0-9]{1,20}$/', $id));

        $this->setRedirect(Route::_(\count($validIds) === 3 ? $detailUrl : $listUrl, false));

        try {
            $identity = $this->currentIdentity();
        } catch (SdpException) {
            $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_UNAVAILABLE'), 'error');

            return;
        }

        if (!$identity->isMatched()) {
            $this->setRedirect(Route::_($listUrl, false));

            return;
        }

        $guard = $this->sdpComponent()->getSubmissionGuard();

        $check = $guard->consume($token);

        if ($check !== SubmissionGuard::OK) {
            // UNKNOWN: the page's token expired or was replaced by newer ones; nothing was sent.
            $this->app->enqueueMessage(Text::_($check === SubmissionGuard::USED ? 'COM_SERVICEDESKPLUS_APPROVAL_ALREADY_SUBMITTED' : 'COM_SERVICEDESKPLUS_APPROVAL_FORM_EXPIRED'), 'warning');

            return;
        }

        try {
            $outcome = $this->sdpComponent()->getApprovalService()
                ->decide($identity, $requestId, $levelId, $approvalId, $decision, $comment, self::approverLabel((string) $user->username, (string) $user->name), $module);
        } catch (AutoDecided $e) {
            $this->app->enqueueMessage(Text::_($e->entryRemoved ? 'COM_SERVICEDESKPLUS_APPROVAL_ERR_AUTO_DECIDED' : 'COM_SERVICEDESKPLUS_APPROVAL_ERR_AUTO_DECIDED_LEFT'), 'error');

            return;
        } catch (NotRecordable $e) {
            $this->app->enqueueMessage(Text::_(match ($e->reason) {
                NotRecordable::REASON_CLOSED      => 'COM_SERVICEDESKPLUS_APPROVAL_ERR_NOT_RECORDABLE',
                NotRecordable::REASON_NOT_APPLIED => 'COM_SERVICEDESKPLUS_APPROVAL_ERR_NOT_APPLIED',
                default                           => 'COM_SERVICEDESKPLUS_APPROVAL_ERR_NOT_ALLOWED',
            }), 'error');

            return;
        } catch (AlreadyDecided) {
            $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_APPROVAL_ERR_ALREADY_DECIDED'), 'warning');

            return;
        } catch (ApprovalNotFound) {
            $this->setRedirect(Route::_($listUrl, false));
            $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_APPROVAL_NOT_FOUND'), 'error');

            return;
        } catch (InvalidDecision $e) {
            $this->app->enqueueMessage(Text::_($e->getMessage()), 'error');

            return;
        } catch (RateLimitExceeded) {
            $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_APPROVAL_RATE_LIMITED'), 'error');

            return;
        } catch (SdpException $e) {
            $key = match ($e->getKind()) {
                SdpException::PERMISSION => 'COM_SERVICEDESKPLUS_APPROVAL_ERR_PERMISSION',
                SdpException::NETWORK    => 'COM_SERVICEDESKPLUS_APPROVAL_ERR_UNCERTAIN',
                default                  => $e->getLanguageKey(),
            };
            $this->app->enqueueMessage(Text::_($key), 'error');

            return;
        }

        $message = $decision === 'approve' ? 'COM_SERVICEDESKPLUS_APPROVAL_APPROVED' : 'COM_SERVICEDESKPLUS_APPROVAL_REJECTED';
        $this->app->enqueueMessage(Text::_($message), 'success');

        $notice = match ($outcome) {
            ApprovalService::OUTCOME_UNCONFIRMED   => 'COM_SERVICEDESKPLUS_APPROVAL_UNCONFIRMED',
            ApprovalService::OUTCOME_LEVEL_PENDING => 'COM_SERVICEDESKPLUS_APPROVAL_LEVEL_PENDING',
            ApprovalService::OUTCOME_ORIGINAL_LEFT => 'COM_SERVICEDESKPLUS_APPROVAL_ORIGINAL_LEFT',
            default                                => null,
        };

        if ($notice !== null) {
            $this->app->enqueueMessage(Text::_($notice), 'info');
        }

        $this->setRedirect(Route::_($listUrl, false));
    }

    /**
     * How the user is named in the SDP comment and note: "(username) Full Name".
     */
    private static function approverLabel(string $username, string $name): string
    {
        $username = trim($username);
        $name     = trim($name);

        if ($username === '' || strcasecmp($username, $name) === 0) {
            return $name !== '' ? $name : $username;
        }

        return '(' . $username . ') ' . $name;
    }
}
