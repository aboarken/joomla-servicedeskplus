<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Log;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Log\Log;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * PSR-3 logger writing to administrator/logs/com_servicedeskplus.php through the Joomla Log API.
 *
 * Callers must pass messages that are already free of secrets (SdpClient scrubs them).
 * Context arrays are deliberately ignored so request data is never dumped by accident.
 */
final class JoomlaLogger extends AbstractLogger
{
    public const CATEGORY = 'com_servicedeskplus';

    private static bool $registered = false;

    public function __construct()
    {
        if (!self::$registered) {
            Log::addLogger(
                ['text_file' => 'com_servicedeskplus.php', 'text_entry_format' => '{DATETIME}	{PRIORITY}	{MESSAGE}'],
                // Every ServiceDesk Plus read is logged at DEBUG: only with System → Global
                // Configuration → Debug System on, so the file does not grow with every page view.
                \defined('JDEBUG') && JDEBUG ? Log::ALL : Log::ALL & ~Log::DEBUG,
                [self::CATEGORY]
            );
            self::$registered = true;
        }
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $priority = match ($level) {
            LogLevel::EMERGENCY => Log::EMERGENCY,
            LogLevel::ALERT     => Log::ALERT,
            LogLevel::CRITICAL  => Log::CRITICAL,
            LogLevel::ERROR     => Log::ERROR,
            LogLevel::WARNING   => Log::WARNING,
            LogLevel::NOTICE    => Log::NOTICE,
            LogLevel::DEBUG     => Log::DEBUG,
            default             => Log::INFO,
        };

        Log::add((string) $message, $priority, self::CATEGORY);
    }
}
