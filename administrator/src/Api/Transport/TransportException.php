<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Transport;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

final class TransportException extends \RuntimeException
{
    /**
     * The request was sent but no complete answer came in time ("Could not reach ServiceDesk Plus
     * (timeout: ...)"). Sending it again would only wait as long once more.
     */
    public function isTimeout(): bool
    {
        return str_contains($this->getMessage(), '(timeout:');
    }
}
