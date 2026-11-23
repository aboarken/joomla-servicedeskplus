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
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\AttachmentValidator;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\InvalidRequestInput;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\RequestService;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\RateLimitExceeded;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\SubmissionGuard;
use SdpPortal\Component\ServiceDeskPlus\Site\Model\RequestformModel;

/**
 * Feature 1: create a request. POST only, CSRF token, ACL, one-time submission token, rate limit.
 * The requester is always the logged-in user's mapped SDP user.
 */
final class RequestController extends BaseController
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

    private const FORM_URL = 'index.php?option=com_servicedeskplus&view=requestform';

    public function create(): void
    {
        if (!$this->requireLogin()) {
            return;
        }

        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('sdp.request.create', 'com_servicedeskplus')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        // Raw on purpose: the text is validated by RequestService and escaped before it is sent
        // to SDP (HtmlText::fromPlain) and when shown. Joomla's string filter would strip "<...>".
        $subject     = (string) $this->input->post->getRaw('subject', '');
        $description = (string) $this->input->post->getRaw('description', '');
        $token       = $this->input->post->getAlnum('submission', '');
        $guard       = $this->sdpComponent()->getSubmissionGuard();

        $this->setRedirect(Route::_(self::FORM_URL, false));

        try {
            $identity = $this->currentIdentity();
        } catch (SdpException) {
            $this->keepInput($subject, $description);
            $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_UNAVAILABLE'), 'error');

            return;
        }

        if (!$identity->isMatched()) {
            $this->setRedirect(Route::_('index.php?option=com_servicedeskplus&view=requests', false));

            return;
        }

        switch ($guard->consume($token)) {
            case SubmissionGuard::USED:
                $previous = $guard->resultFor($token);

                if ($previous !== null) {
                    $this->app->setUserState(RequestformModel::CREATED_KEY, $previous);
                }

                $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_REQUEST_ALREADY_SUBMITTED'), 'warning');

                return;

            case SubmissionGuard::UNKNOWN:
                $this->keepInput($subject, $description);
                $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_REQUEST_FORM_EXPIRED'), 'warning');

                return;
        }

        try {
            // Files are checked here (type by content, size, count) before anything is sent to SDP.
            $files       = $this->input->files->get('attachments', [], 'raw');
            $attachments = (new AttachmentValidator())->validate(\is_array($files) ? array_values($files) : []);

            $created = $this->sdpComponent()->getRequestService()->createForUser($identity, $subject, $description, $attachments);
        } catch (InvalidRequestInput $e) {
            $this->keepInput($subject, $description);

            foreach ($e->getKeys() as $key) {
                $message = str_starts_with($key, 'COM_SERVICEDESKPLUS_ATTACH_')
                    ? Text::sprintf($key, ...AttachmentValidator::limitsForMessages())
                    : Text::_($key);
                $this->app->enqueueMessage($message, 'error');
            }

            if (array_filter($e->getKeys(), static fn (string $k) => str_starts_with($k, 'COM_SERVICEDESKPLUS_ATTACH_')) !== []) {
                $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_ATTACH_REATTACH'), 'info');
            }

            return;
        } catch (RateLimitExceeded) {
            $this->keepInput($subject, $description);
            $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_REQUEST_RATE_LIMITED'), 'error');

            return;
        } catch (SdpException $e) {
            $this->keepInput($subject, $description);

            // A timeout on create may still have created the request in SDP: say so, never retry.
            $key = $e->getKind() === SdpException::NETWORK ? 'COM_SERVICEDESKPLUS_REQUEST_CREATE_UNCERTAIN' : $e->getLanguageKey();
            $this->app->enqueueMessage(Text::_($key), 'error');

            return;
        }

        $guard->recordResult($token, $created->id);
        $this->app->setUserState(RequestformModel::CREATED_KEY, $created->id);
        $this->app->setUserState(RequestformModel::INPUT_KEY, null);

        if ($created->failedAttachments !== []) {
            $names = implode(', ', array_map(
                static fn (string $n) => htmlspecialchars($n, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $created->failedAttachments
            ));
            $this->app->enqueueMessage(Text::sprintf('COM_SERVICEDESKPLUS_ATTACH_FAILED', $names), 'warning');
        }
    }

    private function keepInput(string $subject, string $description): void
    {
        // Kept in the session to fill the form again; never more than the form accepts (a little
        // over, so the "too long" message still applies), whatever size was posted.
        $this->app->setUserState(RequestformModel::INPUT_KEY, [
            'subject'     => mb_substr($subject, 0, RequestService::SUBJECT_MAX + 1),
            'description' => mb_substr($description, 0, RequestService::DESCRIPTION_MAX + 1),
        ]);
    }
}
