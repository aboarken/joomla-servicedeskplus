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
 * Find Endpoints: one run of every check (run), the run's report as a file (download), and
 * checking one endpoint (field paths and kinds only, plus status texts). Every task needs POST, a valid CSRF token
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
    /** Warnings a run can give (COM_SERVICEDESKPLUS_ENDPOINTS_WARN_<name>). */
    public const WARNINGS = ['HTTP', 'TLS_OFF', 'NO_ACCOUNT'];

    public const STATE_RUN   = 'com_servicedeskplus.endpoints.run';
    public const STATE_CHECK = 'com_servicedeskplus.endpoints.check';

    private const URL = 'index.php?option=com_servicedeskplus&view=endpoints';

    /** Runs and endpoint checks per administrator and hour. */
    private const PER_HOUR = 60;

    private const ACTION = 'settings.explore';

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
     * Find Endpoints: every check in one run (connection, the endpoints the portal reads, an
     * optional requester, the other known endpoints). The page shows the result as cards and
     * tables; the same result is kept as a text report to download (paths, results, field paths and
     * kinds only).
     */
    public function run(): void
    {
        $this->guard();

        $email = mb_substr(trim($this->input->post->getString('email', '')), 0, 320);

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_DIAG_ERR_EMAIL'), 'warning');
            $this->setRedirect(Route::_(self::URL, false));

            return;
        }

        if (!$this->allowed()) {
            return;
        }

        $component = $this->component();
        $settings  = $component->getSettingsRepository()->load();
        $https     = str_starts_with($settings->baseUrl(), 'https://');
        $tls       = $settings->sslPin() !== '' ? 'pinned' : ($settings->verifySsl() ? 'verified' : 'off');

        // Shown on the page (cards) and at the head of the report.
        $server = [
            'url'     => $settings->baseUrl(),
            'https'   => $https,
            'tls'     => $tls,
            'via'     => $settings->connectVia(),
            'timeout' => $settings->timeout(),
            'connect' => $settings->connectTimeout(),
        ];
        $portal = [
            'account'  => $settings->approvalAccountEmail() !== '',
            'modules'  => $settings->approvalModules(),
            'auto'     => $settings->autoApproveMatches(),
            'cache'    => $settings->cacheMinutes(),
        ];

        $warnings = [];

        if ($settings->baseUrl() !== '' && !$https) {
            $warnings[] = 'HTTP';
        }

        if ($https && $tls === 'off') {
            $warnings[] = 'TLS_OFF';
        }

        if (!$portal['account'] && $portal['modules'] !== []) {
            $warnings[] = 'NO_ACCOUNT';
        }

        $context = [
            'Component version'           => $this->version(),
            'Joomla / PHP'                => JVERSION . ' / ' . PHP_VERSION,
            'Address uses HTTPS'          => $https ? 'yes' : 'no',
            'Certificate'                 => ['pinned' => 'certificate pinned', 'verified' => 'verified', 'off' => 'NOT verified'][$tls],
            'Connect via set'             => $server['via'] !== '' ? 'yes' : 'no',
            'Timeout / connect (s)'       => $server['timeout'] . ' / ' . $server['connect'],
            'Cache (minutes)'             => (string) $portal['cache'],
            'Approval modules'            => implode(', ', $portal['modules']) ?: '(none)',
            'API technician e-mail set'   => $portal['account'] ? 'yes' : 'no',
            'New users matched by e-mail' => $portal['auto'] ? 'approved automatically' : 'wait for an administrator',
        ];
        $warningTexts = array_map(static fn (string $w): string => Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_WARN_' . $w), $warnings);

        try {
            $client = $component->getSdpClientFactory()->createServiceClient();
            $result = Diagnostics::collect($client, $email !== '' ? $email : null);
        } catch (SdpException $e) {
            $result = [
                'connection' => ['ok' => false, 'ms' => 0, 'total' => null, 'kind' => $e->getKind(), 'error' => Diagnostics::error($e)],
                'timing'     => [],
                'portal'     => [],
                'requester'  => null,
                'others'     => [],
                'stopped'    => true,
            ];
        }

        $this->app->setUserState(self::STATE_RUN, [
            'at'       => time(),
            'email'    => $email,
            'server'   => $server,
            'portal'   => $portal,
            'warnings' => $warnings,
            'result'   => $result,
            'report'   => Diagnostics::report($result, $email !== '' ? $email : null, $context, $warningTexts),
        ]);

        $failed = \count(array_filter($result['portal'], static fn (array $r): bool => $r['status'] !== Diagnostics::OK));
        $this->audit($result['connection']['ok'] ? AuditLogInterface::RESULT_OK : AuditLogInterface::RESULT_ERROR, 'run ' . ($result['connection']['ok'] ? 'portal failed ' . $failed : $result['connection']['kind']));

        if (!$result['connection']['ok']) {
            $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_RUN_FAILED'), 'error');
        } elseif ($failed > 0) {
            $this->app->enqueueMessage(Text::plural('COM_SERVICEDESKPLUS_ENDPOINTS_RUN_N_FAILED', $failed), 'warning');
        } else {
            $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_RUN_DONE'), 'success');
        }

        $this->setRedirect(Route::_(self::URL, false));
    }

    /**
     * The last run's report as a text file. Nothing is sent to ServiceDesk Plus.
     */
    public function download(): void
    {
        $this->guard();

        $run    = $this->app->getUserState(self::STATE_RUN);
        $report = \is_array($run) && \is_string($run['report'] ?? null) ? $run['report'] : '';

        if ($report === '') {
            $this->app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_DIAG_NONE'), 'warning');
            $this->setRedirect(Route::_(self::URL, false));

            return;
        }

        $name = 'servicedeskplus-diagnostics-' . gmdate('Y-m-d-Hi', (int) ($run['at'] ?? time())) . '.txt';
        $body = str_replace("\n", "\r\n", $report) . "\r\n";

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $this->app->clearHeaders();
        $this->app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $this->app->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"', true);
        $this->app->setHeader('Content-Length', (string) \strlen($body), true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->app->setHeader('Cache-Control', 'private, no-store', true);
        $this->app->sendHeaders();

        echo $body;

        $this->app->close();
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
