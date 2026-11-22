<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Exception\RouteNotFoundException;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\ListInfo;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpException;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Audit\AuditLogInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Discovery\Diagnostics;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Discovery\EndpointCatalog;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Discovery\FieldInspector;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Extension\ServiceDeskPlusComponent;

/**
 * Find Endpoints: check which known v3 endpoints answer, check one endpoint (field paths and
 * kinds only, plus status texts), and run Diagnostics. Every task needs POST, a valid CSRF token
 * and core.admin; only GET requests are sent to ServiceDesk Plus. Results are kept in the
 * administrator's session only.
 */
final class EndpointsController extends BaseController
{

    /**
     * Pages are shown only through DisplayController, which requires core.admin. Joomla also
     * offers every controller's inherited display() as a task (task={name}.display), which would
     * skip that check for users who may only open the component, so it is refused here.
     */
    public function display($cachable = false, $urlparams = [])
    {
        throw new RouteNotFoundException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
    }
    public const STATE_CATALOG = 'com_servicedeskplus.endpoints.catalog';
    public const STATE_CHECK   = 'com_servicedeskplus.endpoints.check';
    public const STATE_DIAG    = 'com_servicedeskplus.endpoints.diagnostics';

    private const URL = 'index.php?option=com_servicedeskplus&view=endpoints';

    /** Availability runs, endpoint checks and Diagnostics per administrator and hour. */
    private const PER_HOUR = 60;

    private const ACTION = 'settings.explore';

    /**
     * Read one row of every known endpoint: answers, total, or why not.
     */
    public function catalog(): void
    {
        $this->guard();

        if (!$this->allowed()) {
            return;
        }

        try {
            $client = $this->component()->getSdpClientFactory()->createServiceClient();
        } catch (SdpException $e) {
            $this->app->enqueueMessage(Text::_($e->getLanguageKey()), 'warning');
            $this->setRedirect(Route::_(self::URL, false));

            return;
        }

        $results = [];
        $ok      = 0;

        foreach (EndpointCatalog::all() as $ep) {
            try {
                $probe = $client->probe($ep['path'], new ListInfo(rowCount: 1, getTotalCount: true));
                $results[$ep['path']] = ['ok' => true, 'kind' => '', 'http' => 0, 'total' => $probe->totalCount, 'key' => $probe->key];
                $ok++;
            } catch (SdpException $e) {
                $results[$ep['path']] = ['ok' => false, 'kind' => $e->getKind(), 'http' => $e->getHttpStatus(), 'total' => null, 'key' => ''];

                if (\in_array($e->getKind(), [SdpException::NETWORK, SdpException::AUTH, SdpException::NOT_CONFIGURED], true)) {
                    // Every other path would fail the same way.
                    $this->app->setUserState(self::STATE_CATALOG, ['results' => $results, 'at' => time()]);
                    $this->audit(AuditLogInterface::RESULT_ERROR, 'catalog ' . $e->getKind());
                    $this->app->enqueueMessage(Text::_($e->getLanguageKey()) . $this->serverSaid($e), 'warning');
                    $this->setRedirect(Route::_(self::URL, false));

                    return;
                }
            }
        }

        $this->app->setUserState(self::STATE_CATALOG, ['results' => $results, 'at' => time()]);
        $this->audit(AuditLogInterface::RESULT_OK, 'catalog ' . $ok . '/' . \count($results));
        $this->app->enqueueMessage(Text::sprintf('COM_SERVICEDESKPLUS_ENDPOINTS_READ', $ok, \count($results)), 'success');
        $this->setRedirect(Route::_(self::URL, false));
    }

    public function check(): void
    {
        $this->guard();

        if (!$this->allowed()) {
            return;
        }

        $in     = $this->input->post;
        $typed  = mb_substr(trim($in->getString('path', '')), 0, 300);
        $field  = mb_substr(trim($in->getString('search_field', '')), 0, 200);
        $value  = mb_substr(trim($in->getString('search_value', '')), 0, 100);
        $rows   = max(1, min(50, $in->getInt('rows', 10)));
        $path   = EndpointCatalog::normalize($typed);
        $form   = ['path' => $path ?? $typed, 'search_field' => $field, 'search_value' => $value, 'rows' => $rows];

        if ($path === null) {
            $this->failCheck($form, 'COM_SERVICEDESKPLUS_ENDPOINTS_ERR_PATH');

            return;
        }

        $criteria = null;

        if ($field !== '' || $value !== '') {
            if (!preg_match('/^[a-z0-9_]{1,60}(\.[a-z0-9_]{1,60}){0,3}$/', $field) || $value === '') {
                $this->failCheck($form, 'COM_SERVICEDESKPLUS_ENDPOINTS_ERR_SEARCH');

                return;
            }

            $criteria = [['field' => $field, 'condition' => 'is', 'value' => $value]];
        }

        try {
            $probe = $this->component()->getSdpClientFactory()->createServiceClient()
                ->probe($path, new ListInfo(rowCount: $rows, searchCriteria: $criteria, getTotalCount: true));
        } catch (SdpException $e) {
            $this->audit(AuditLogInterface::RESULT_ERROR, 'check ' . $path . ' ' . $e->getKind());

            $key = match ($e->getKind()) {
                SdpException::BAD_RESPONSE => 'COM_SERVICEDESKPLUS_ENDPOINTS_ERR_NOT_DATA',
                SdpException::NOT_FOUND    => 'COM_SERVICEDESKPLUS_ENDPOINTS_ERR_NO_PATH',
                SdpException::VALIDATION   => 'COM_SERVICEDESKPLUS_ENDPOINTS_ERR_REFUSED',
                default                    => $e->getLanguageKey(),
            };

            $this->failCheck($form, $key, $e->getKind(), $this->serverSaid($e));

            return;
        } catch (\InvalidArgumentException) {
            $this->failCheck($form, 'COM_SERVICEDESKPLUS_ENDPOINTS_ERR_PATH');

            return;
        }

        $fields = FieldInspector::inspect($probe->items);

        $this->app->setUserState(self::STATE_CHECK, $form + [
            'key'    => $probe->key,
            'single' => $probe->single,
            'count'  => \count($probe->items),
            'total'  => $probe->totalCount,
            'more'   => $probe->hasMoreRows,
            'fields' => $fields,
            'error'  => null,
        ]);

        $this->audit(AuditLogInterface::RESULT_OK, 'check ' . $path . ' rows ' . \count($probe->items));
        $this->app->enqueueMessage(Text::sprintf('COM_SERVICEDESKPLUS_ENDPOINTS_CHECKED', \count($probe->items), \count($fields)), $probe->items !== [] ? 'success' : 'warning');
        $this->setRedirect(Route::_(self::URL . '#sdp-check', false));
    }

    /**
     * Diagnostics: every check in one run, as a text report to copy (paths, results, field paths
     * and kinds only).
     */
    public function diagnose(): void
    {
        $this->guard();

        if (!$this->allowed()) {
            return;
        }

        $email = mb_substr(trim($this->input->post->getString('email', '')), 0, 320);

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_DIAG_ERR_EMAIL'), 'warning');
            $this->setRedirect(Route::_(self::URL . '#sdp-diag', false));

            return;
        }

        $component = $this->component();
        $settings  = $component->getSettingsRepository()->load();
        $tls       = $settings->sslPin() !== '' ? 'certificate pinned' : ($settings->verifySsl() ? 'verified' : 'NOT verified');

        try {
            $client = $component->getSdpClientFactory()->createServiceClient();
            $report = Diagnostics::run($client, $email !== '' ? $email : null, [
                'Component version'       => $this->version(),
                'Joomla / PHP'            => JVERSION . ' / ' . PHP_VERSION,
                'Address uses HTTPS'      => str_starts_with($settings->baseUrl(), 'https://') ? 'yes' : 'no',
                'Certificate'             => $tls,
                'Connect via set'         => $settings->connectVia() !== '' ? 'yes' : 'no',
                'Timeout / connect (s)'   => $settings->timeout() . ' / ' . $settings->connectTimeout(),
                'Cache (minutes)'         => (string) $settings->cacheMinutes(),
                'Approval modules'        => implode(', ', $settings->approvalModules()) ?: '(none)',
                'API technician e-mail set' => $settings->approvalAccountEmail() !== '' ? 'yes' : 'no',
                'New users matched by e-mail' => $settings->autoApproveMatches() ? 'approved automatically' : 'wait for an administrator',
            ]);
        } catch (SdpException $e) {
            $report = 'ServiceDesk Plus portal diagnostics' . "\n" . 'FAILED before the first request: ' . Diagnostics::error($e);
        }

        $this->app->setUserState(self::STATE_DIAG, ['report' => $report, 'email' => $email]);
        $this->audit(AuditLogInterface::RESULT_OK, 'diagnostics');
        $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_DIAG_DONE'), 'success');
        $this->setRedirect(Route::_(self::URL . '#sdp-diag', false));
    }

    private function version(): string
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->createQuery()
            ->select($db->quoteName('manifest_cache'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_servicedeskplus'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'));
        $cache = json_decode((string) $db->setQuery($query)->loadResult(), true);

        return \is_array($cache) && \is_string($cache['version'] ?? null) ? $cache['version'] : '—';
    }

    /**
     * @param array<string, mixed> $form
     */
    private function failCheck(array $form, string $key, ?string $kind = null, string $detail = ''): void
    {
        $this->app->setUserState(self::STATE_CHECK, $form + ['key' => '', 'single' => false, 'count' => 0, 'total' => null, 'more' => false, 'fields' => [], 'error' => $kind ?? 'input']);
        $this->app->enqueueMessage(Text::_($key) . $detail, 'warning');
        $this->setRedirect(Route::_(self::URL . '#sdp-check', false));
    }

    /**
     * " (ServiceDesk Plus answered HTTP 400, code 4012: <its message>)" for the administrator,
     * escaped for the message area. The client already removed the key from the message.
     */
    private function serverSaid(SdpException $e): string
    {
        if ($e->getHttpStatus() === 0 && $e->getSdpMessage() === '') {
            return '';
        }

        return ' ' . htmlspecialchars(Text::sprintf(
            'COM_SERVICEDESKPLUS_ENDPOINTS_SERVER_SAID',
            $e->getHttpStatus() > 0 ? (string) $e->getHttpStatus() : '—',
            $e->getSdpCode() !== null ? (string) $e->getSdpCode() : '—',
            $e->getSdpMessage() !== '' ? mb_substr($e->getSdpMessage(), 0, 300) : '—'
        ), ENT_QUOTES, 'UTF-8');
    }

    private function allowed(): bool
    {
        if ($this->component()->getRateLimiter()->allow($this->actorId(), self::ACTION, self::PER_HOUR)) {
            return true;
        }

        $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_TOO_MANY'), 'warning');
        $this->setRedirect(Route::_(self::URL, false));

        return false;
    }

    private function audit(string $result, string $detail): void
    {
        $this->component()->getAuditLog()->record($this->actorId(), self::ACTION, $result, null, null, mb_substr($detail, 0, 255), $this->actorId());
    }

    private function guard(): void
    {
        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_servicedeskplus')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    private function actorId(): int
    {
        return (int) $this->app->getIdentity()->id;
    }

    private function component(): ServiceDeskPlusComponent
    {
        $component = $this->app->bootComponent('com_servicedeskplus');

        if (!$component instanceof ServiceDeskPlusComponent) {
            throw new \LogicException('Unexpected component class');
        }

        return $component;
    }
}
