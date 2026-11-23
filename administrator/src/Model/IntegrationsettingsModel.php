<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\FormModel;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\ClientConfig;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\ApprovalModule;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SslPin;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Extension\ServiceDeskPlusComponent;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\KeyVault;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\KeyVaultException;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Settings\Settings;

final class IntegrationsettingsModel extends FormModel
{
    /** Characters accepted in an SDP authtoken. */
    public const KEY_PATTERN = '/^[A-Za-z0-9._-]{16,128}$/';

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_servicedeskplus.settings',
            'settings',
            ['control' => 'jform', 'load_data' => $loadData]
        );

        return $form ?: false;
    }

    protected function loadFormData()
    {
        // Values of a rejected save are shown once, right after it; later visits show the stored settings.
        $app   = Factory::getApplication();
        $state = $app->getUserState('com_servicedeskplus.edit.settings.data', []);
        $app->setUserState('com_servicedeskplus.edit.settings.data', null);
        $data  = \is_array($state) && $state !== [] ? $state : $this->settings()->toFormData();

        // The key field is always empty: a stored key is never sent back to the browser.
        $data['service_key'] = '';

        return $data;
    }

    public function settings(): Settings
    {
        return $this->component()->getSettingsRepository()->load();
    }

    public function secretSource(): string
    {
        return $this->component()->getSdpClientFactory()->secretSource();
    }

    /**
     * Validate, encrypt the key if a new one was entered, and store.
     *
     * @param  array<string, mixed>  $data  Raw posted jform data
     *
     * @throws ValidationFailed
     */
    public function save(array $data): void
    {
        $form = $this->getForm($data, false);

        if (!$form instanceof Form) {
            throw new ValidationFailed([Text::_('JLIB_FORM_ERROR_FORM_NOT_LOADED')]);
        }

        $filtered = $form->filter($data);

        if ($form->validate($filtered) !== true) {
            $messages = [];

            foreach ($form->getErrors() as $error) {
                $messages[] = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
            }

            throw new ValidationFailed($messages ?: [Text::_('JLIB_APPLICATION_ERROR_SAVE_FAILED')]);
        }

        try {
            $baseUrl = ClientConfig::normalizeBaseUrl((string) ($filtered['base_url'] ?? ''));
        } catch (\InvalidArgumentException) {
            throw new ValidationFailed([Text::_('COM_SERVICEDESKPLUS_SETTINGS_ERR_BASE_URL')]);
        }

        $timeout        = (int) ($filtered['timeout'] ?? 20);
        $connectTimeout = (int) ($filtered['connect_timeout'] ?? 5);

        if ($connectTimeout > $timeout) {
            throw new ValidationFailed([Text::_('COM_SERVICEDESKPLUS_SETTINGS_ERR_TIMEOUTS')]);
        }

        $caBundle = trim((string) ($filtered['ca_bundle'] ?? ''));

        if ($caBundle !== '' && (preg_match('/[\x00-\x1F\x7F]/', $caBundle) || !is_file($caBundle) || !is_readable($caBundle))) {
            throw new ValidationFailed([Text::_('COM_SERVICEDESKPLUS_SETTINGS_ERR_CA_BUNDLE')]);
        }

        $values = [
            'base_url'             => $baseUrl,
            'timeout'              => (string) $timeout,
            'connect_timeout'      => (string) $connectTimeout,
            'verify_ssl'           => ((string) ($filtered['verify_ssl'] ?? '1')) === '0' ? '0' : '1',
            'ca_bundle'            => $caBundle,
            'cache_minutes'        => (string) max(0, min(Settings::MAX_CACHE_MINUTES, (int) ($filtered['cache_minutes'] ?? Settings::DEFAULT_CACHE_MINUTES))),
            'parallel_requests'    => (string) max(1, min(Settings::MAX_PARALLEL_REQUESTS, (int) ($filtered['parallel_requests'] ?? 6))),
            'rate_create_per_hour' => (string) max(1, min(1000, (int) ($filtered['rate_create_per_hour'] ?? 10))),
            'rate_decide_per_hour' => (string) max(1, min(1000, (int) ($filtered['rate_decide_per_hour'] ?? 30))),
            'request_statuses'     => implode(',', Settings::parseList((string) ($filtered['request_statuses'] ?? ''))),
            'auto_approve_matches' => ((string) ($filtered['auto_approve_matches'] ?? '1')) === '0' ? '0' : '1',
        ];

        $approvalEmail = trim((string) ($filtered['approval_account_email'] ?? ''));

        if ($approvalEmail !== '' && (\strlen($approvalEmail) > 320 || filter_var($approvalEmail, FILTER_VALIDATE_EMAIL) === false)) {
            throw new ValidationFailed([Text::_('COM_SERVICEDESKPLUS_SETTINGS_ERR_APPROVAL_EMAIL')]);
        }

        $values['approval_account_email'] = $approvalEmail;

        // Checkboxes: only known modules, in a fixed order. None ticked shows no approvals.
        $modules = array_map('strval', \is_array($data['approval_modules'] ?? null) ? $data['approval_modules'] : []);
        $values['approval_modules'] = implode(',', array_values(array_filter(
            ApprovalModule::ALL,
            static fn (string $m): bool => \in_array($m, $modules, true)
        )));

        try {
            $values['ssl_pin'] = SslPin::normalize((string) ($data['ssl_pin'] ?? ''));
        } catch (\InvalidArgumentException) {
            throw new ValidationFailed([Text::_('COM_SERVICEDESKPLUS_SETTINGS_ERR_PIN')]);
        }

        try {
            $basePort              = (int) (parse_url($baseUrl, PHP_URL_PORT) ?: (str_starts_with($baseUrl, 'https://') ? 443 : 80));
            $values['connect_via'] = ClientConfig::normalizeConnectVia((string) ($filtered['connect_via'] ?? ''), $basePort);
        } catch (\InvalidArgumentException) {
            throw new ValidationFailed([Text::_('COM_SERVICEDESKPLUS_SETTINGS_ERR_CONNECT_VIA')]);
        }

        if ($values['ssl_pin'] !== '' && !str_starts_with($baseUrl, 'https://')) {
            throw new ValidationFailed([Text::_('COM_SERVICEDESKPLUS_SETTINGS_ERR_PIN_HTTPS')]);
        }


        // The key is read from the raw post so no filter can alter it; it is validated by pattern.
        $key = trim((string) ($data['service_key'] ?? ''));

        if ($key !== '') {
            if (!preg_match(self::KEY_PATTERN, $key)) {
                throw new ValidationFailed([Text::_('COM_SERVICEDESKPLUS_SETTINGS_ERR_KEY_FORMAT')]);
            }

            try {
                $values['service_key_enc'] = $this->component()->getSdpClientFactory()->vault()
                    ->encrypt($key, KeyVault::CONTEXT_SERVICE_KEY);
            } catch (KeyVaultException) {
                throw new ValidationFailed([Text::_('COM_SERVICEDESKPLUS_SETTINGS_ERR_NO_SECRET')]);
            }
        } elseif (!$this->settings()->hasServiceKey()) {
            throw new ValidationFailed([Text::_('COM_SERVICEDESKPLUS_SETTINGS_ERR_KEY_REQUIRED')]);
        } elseif ($this->connectionChanged($values)) {
            // The stored key is sent to the address in these settings. Whoever points it elsewhere
            // (or weakens the certificate check) must know the key, so it cannot be redirected.
            throw new ValidationFailed([Text::_('COM_SERVICEDESKPLUS_SETTINGS_ERR_KEY_REENTER')]);
        }

        $stored    = $this->settings();
        $newServer = $values['base_url'] !== $stored->baseUrl();

        // Approval lists depend on these: a switched-off module or another API technician must
        // not keep showing in lists cached before the change.
        $newApprovals = $values['approval_account_email'] !== $stored->approvalAccountEmail()
            || $values['approval_modules'] !== implode(',', $stored->approvalModules());

        $this->component()->getSettingsRepository()->save($values);

        // Results cached from another ServiceDesk Plus server must never be shown again.
        if ($newServer || $newApprovals) {
            $this->component()->getUserCache()->clearAll();
        }
    }

    /**
     * @throws \SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpException
     */
    public function testConnection(): void
    {
        $this->component()->getSdpClientFactory()->createServiceClient()->testConnection();
    }

    /**
     * Read the certificate the saved base URL presents (no verification) so the admin can compare
     * its pin with the value computed on the SDP server.
     *
     * @return array{pin: string, subject: string, issuer: string, expires: ?int}
     *
     * @throws \RuntimeException
     */
    public function readServerPin(): array
    {
        $settings = $this->settings();

        if ($settings->baseUrl() === '') {
            throw new \RuntimeException('No base URL saved');
        }

        return SslPin::readFromServer($settings->baseUrl(), $settings->connectTimeout(), $settings->connectVia());
    }

    /**
     * True when the saved key would go to another server, or with a weaker certificate check:
     * another address or "connect via", certificate check turned off, or another pin or CA file.
     *
     * @param  array<string, string>  $values
     */
    private function connectionChanged(array $values): bool
    {
        $stored = $this->settings();

        return $values['base_url'] !== $stored->baseUrl()
            || $values['connect_via'] !== $stored->connectVia()
            || ($values['verify_ssl'] === '0' && $stored->verifySsl())
            || $values['ssl_pin'] !== $stored->sslPin()
            || $values['ca_bundle'] !== $stored->caBundle();
    }

    private function component(): ServiceDeskPlusComponent
    {
        $component = Factory::getApplication()->bootComponent('com_servicedeskplus');

        if (!$component instanceof ServiceDeskPlusComponent) {
            throw new \LogicException('Unexpected component class');
        }

        return $component;
    }
}
