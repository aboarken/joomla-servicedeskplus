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

    /**
     * Whether sending the same read again can help: not after a timeout (it would wait as long
     * once more) and not after an answer that was cut off for its size (it would be as large).
     */
    public function canRetry(): bool
    {
        return !$this->isTimeout() && !str_contains($this->getMessage(), '(answer too large:');
    }
}
