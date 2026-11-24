<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Security;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * One decision at a time per approval level. A second decision on the same level (a double
 * submit, a second tab, another approver of the level) waits until the first is finished, then
 * reads the level again and finds it decided. Uses a lock file in Joomla's tmp folder; the lock
 * ends with the request at the latest.
 */
final class DecisionLock
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Run $work while holding the lock for $key. Without a usable lock folder the work runs
     * unlocked (logged by the caller's own checks): a decision is never refused for that.
     *
     * @template T
     * @param  \Closure(): T  $work
     * @return T
     */
    public function run(string $key, \Closure $work): mixed
    {
        $file   = rtrim($this->directory, '/\\') . \DIRECTORY_SEPARATOR . 'sdp-decide-' . hash('sha256', $key) . '.lock';
        $handle = @fopen($file, 'c');

        if ($handle === false) {
            return $work();
        }

        try {
            flock($handle, LOCK_EX);

            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Claim $key once: true the first time, false for every later (or parallel) call, also from
     * another PHP process on this server. Used for one-time form tokens: the session copy of a
     * token is read when a request starts, so two requests sent at the same moment would both
     * find it unused. Without a usable folder the claim succeeds (the session check still applies).
     */
    public function claim(string $key): bool
    {
        $dir  = rtrim($this->directory, '/\\') . \DIRECTORY_SEPARATOR;
        $file = $dir . 'sdp-once-' . hash('sha256', $key) . '.lock';

        if (!is_dir($dir) || !is_writable($dir)) {
            return true;
        }

        // Now and then, remove claims older than a day (tokens live two hours).
        if (random_int(1, 50) === 1) {
            foreach (glob($dir . 'sdp-once-*.lock') ?: [] as $old) {
                if ((int) @filemtime($old) < time() - 86400) {
                    @unlink($old);
                }
            }
        }

        // "x" creates the file and fails if it exists, in one step.
        $handle = @fopen($file, 'x');

        if ($handle === false) {
            return !is_file($file);
        }

        fclose($handle);

        return true;
    }
}
