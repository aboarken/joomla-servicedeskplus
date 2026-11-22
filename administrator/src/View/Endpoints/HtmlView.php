<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\View\Endpoints;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Controller\EndpointsController;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Discovery\Diagnostics;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Discovery\EndpointCatalog;

/**
 * Find Endpoints. Shows the last run (warnings, Server and API key cards, the endpoints the portal
 * uses, the requester check, the report, and under "Advanced" the other known endpoints) and the
 * last endpoint check, all kept in the administrator's session only.
 */
final class HtmlView extends BaseHtmlView
{
    /** @var array<string, mixed>|null */
    public ?array $run = null;

    /** @var array<string, mixed>|null */
    public ?array $check = null;

    /** Path chosen with "Select" under Advanced (pre-fills Check an Endpoint). */
    public string $selected = '';

    public function display($tpl = null): void
    {
        $app = Factory::getApplication();

        $run            = $app->getUserState(EndpointsController::STATE_RUN);
        $check          = $app->getUserState(EndpointsController::STATE_CHECK);
        $this->run      = \is_array($run) && isset($run['result']) ? $run : null;
        $this->check    = \is_array($check) ? $check : null;
        $this->selected = mb_substr(trim($app->getInput()->getString('use', '')), 0, 300);

        ToolbarHelper::title(Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_TITLE'), 'search');

        $toolbar = $this->getDocument()->getToolbar();
        $toolbar->standardButton('run', 'COM_SERVICEDESKPLUS_ENDPOINTS_BUTTON')
            ->task('endpoints.run')
            ->icon('icon-search')
            ->listCheck(false);
        $toolbar->linkButton('settings', 'COM_SERVICEDESKPLUS_SUBMENU_SETTINGS')
            ->url('index.php?option=com_servicedeskplus&view=integrationsettings')
            ->icon('icon-cog');
        $toolbar->linkButton('close', 'JTOOLBAR_CLOSE')
            ->url('index.php')
            ->icon('icon-times');
        $toolbar->preferences('com_servicedeskplus');

        $assets = $this->getDocument()->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_servicedeskplus');
        $assets->useStyle('com_servicedeskplus.admin');

        parent::display($tpl);
    }

    /**
     * Known endpoints not used by the portal, with the last run's result when there is one.
     *
     * @return list<array<string, mixed>>
     */
    public function others(): array
    {
        $results = [];

        foreach ($this->run['result']['others'] ?? [] as $row) {
            $results[$row['path']] = $row;
        }

        $out = [];

        foreach (EndpointCatalog::all() as $ep) {
            if ($ep['portal'] === '') {
                $out[] = $results[$ep['path']] ?? $ep + ['status' => null];
            }
        }

        return $out;
    }

    /**
     * The last run's warnings that this version still knows. A run kept in the session from an
     * older version can hold a warning that no longer exists; it is not shown.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_values(array_intersect((array) ($this->run['warnings'] ?? []), EndpointsController::WARNINGS));
    }

    /** Bootstrap badge class for a result status. */
    public static function badge(?string $status): string
    {
        return match ($status) {
            Diagnostics::OK      => 'bg-success',
            Diagnostics::DENIED  => 'bg-danger',
            Diagnostics::MISSING => 'bg-secondary',
            Diagnostics::ERROR   => 'bg-warning text-dark',
            default              => 'bg-light text-dark border',
        };
    }
}
