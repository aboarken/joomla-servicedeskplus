<?php

/**
 * Installer script for com_servicedeskplus.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class () implements InstallerScriptInterface {
                private const MIN_PHP    = '8.3.0';
                private const MIN_JOOMLA = '6.0.0';

                /**
                 * Files and folders shipped by older versions that no longer exist.
                 * Joomla's upgrade does not delete them, so they are removed here.
                 * Paths are relative to the component's site / administrator folder.
                 */
                private const OBSOLETE_SITE = [
                    // 1.13.0: links between portal pages (removed in 1.14.0)
                    'layouts/nav.php',
                    // 1.0: "My approval key" page (removed in 1.1.0)
                    'tmpl/approvalkey',
                    'src/View/Approvalkey',
                    'src/Model/ApprovalkeyModel.php',
                    'src/Controller/ApprovalkeyController.php',
                ];

                private const OBSOLETE_ADMIN = [
                    // 1.21.0: the cache moved from the session to the database
                    'src/Cache/StoredUserCache.php',
                    // 1.11.0: views renamed to integrationsettings and usermappings
                    'src/View/Settings',
                    'src/View/Usermaps',
                    'tmpl/settings',
                    'tmpl/usermaps',
                    'src/Model/SettingsModel.php',
                    'src/Model/UsermapsModel.php',
                    // 1.0: per-user approver keys (removed in 1.1.0)
                    'src/Keys',
                    'src/Api/ApproverKey.php',
                ];

                private const OBSOLETE_MEDIA = [
                    // 1.32.2: Copy report replaced by Download report
                    'js/admin.js',
                ];

                /** Settings rows of removed options (loan rule settings, removed in 1.2.0). */
                private const OBSOLETE_SETTINGS = [
                    'asset_user_field',
                    'loan_match_mode',
                    'loan_match_value',
                    'loan_custom_field',
                    'asset_assigned_field',
                    'loan_start_field',
                    'loan_due_field',
                    'loan_returned_field',
                    // Approval scan settings (removed in 1.6.0; built-in defaults are used).
                    'approval_pending_status',
                    'approval_scan_limit',
                ];

                /** Front-end views that no longer exist; menu items pointing to them are trashed. */
                private const OBSOLETE_VIEWS = ['approvalkey'];

                public function install(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function update(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function uninstall(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'uninstall') {
                        return true;
                    }

                    $app = Factory::getApplication();

                    if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
                        $app->enqueueMessage(Text::sprintf('COM_SERVICEDESKPLUS_INSTALL_MIN_PHP', self::MIN_PHP), 'error');

                        return false;
                    }

                    if (version_compare(JVERSION, self::MIN_JOOMLA, '<')) {
                        $app->enqueueMessage(Text::sprintf('COM_SERVICEDESKPLUS_INSTALL_MIN_JOOMLA', self::MIN_JOOMLA), 'error');

                        return false;
                    }

                    if (!\extension_loaded('openssl') || !\in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
                        $app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_INSTALL_NO_OPENSSL'), 'error');

                        return false;
                    }

                    // Every call to ServiceDesk Plus is made with cURL.
                    if (!\extension_loaded('curl')) {
                        $app->enqueueMessage(Text::_('COM_SERVICEDESKPLUS_INSTALL_NO_CURL'), 'error');

                        return false;
                    }

                    return true;
                }

                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type !== 'update') {
                        return true;
                    }

                    $removed = $this->removeObsoleteFiles(JPATH_SITE . '/components/com_servicedeskplus', self::OBSOLETE_SITE)
                        + $this->removeObsoleteFiles(JPATH_ADMINISTRATOR . '/components/com_servicedeskplus', self::OBSOLETE_ADMIN)
                        + $this->removeObsoleteFiles(JPATH_ROOT . '/media/com_servicedeskplus', self::OBSOLETE_MEDIA);

                    $db = Factory::getContainer()->get(DatabaseInterface::class);

                    $trashed = $this->trashObsoleteMenuItems($db);
                    $this->deleteObsoleteSettings($db);

                    $app = Factory::getApplication();

                    if ($removed > 0) {
                        $app->enqueueMessage(Text::sprintf('COM_SERVICEDESKPLUS_INSTALL_CLEANED_FILES', $removed), 'info');
                    }

                    if ($trashed > 0) {
                        $app->enqueueMessage(Text::sprintf('COM_SERVICEDESKPLUS_INSTALL_TRASHED_MENU', $trashed), 'warning');
                    }

                    return true;
                }

                /**
                 * @param  list<string>  $paths
                 */
                private function removeObsoleteFiles(string $base, array $paths): int
                {
                    $count = 0;

                    foreach ($paths as $relative) {
                        $path = $base . '/' . $relative;

                        try {
                            if (is_dir($path)) {
                                $count += Folder::delete($path) ? 1 : 0;
                            } elseif (is_file($path)) {
                                $count += File::delete($path) ? 1 : 0;
                            }
                        } catch (\Throwable) {
                            // Leave it; the files are unused and harmless.
                        }
                    }

                    return $count;
                }

                /**
                 * Menu items for removed views would lead to an error page. They are moved to the
                 * trash (not deleted), so an admin can still restore or review them.
                 */
                private function trashObsoleteMenuItems(DatabaseInterface $db): int
                {
                    $count = 0;

                    foreach (self::OBSOLETE_VIEWS as $view) {
                        $link  = 'index.php?option=com_servicedeskplus&view=' . $view;
                        $like  = $link . '&%';
                        $query = $db->createQuery()
                            ->update($db->quoteName('#__menu'))
                            ->set($db->quoteName('published') . ' = -2')
                            ->where($db->quoteName('client_id') . ' = 0')
                            ->where($db->quoteName('published') . ' <> -2')
                            ->where('(' . $db->quoteName('link') . ' = :link OR ' . $db->quoteName('link') . ' LIKE :like)')
                            ->bind(':link', $link)
                            ->bind(':like', $like);

                        try {
                            $db->setQuery($query)->execute();
                            $count += (int) $db->getAffectedRows();
                        } catch (\Throwable) {
                            // Not critical.
                        }
                    }

                    return $count;
                }

                private function deleteObsoleteSettings(DatabaseInterface $db): void
                {
                    $query = $db->createQuery()
                        ->delete($db->quoteName('#__sdp_settings'))
                        ->whereIn($db->quoteName('name'), self::OBSOLETE_SETTINGS, ParameterType::STRING);

                    try {
                        $db->setQuery($query)->execute();
                    } catch (\Throwable) {
                        // Not critical: unknown settings rows are ignored by the component.
                    }
                }
            }
        );
    }
};
