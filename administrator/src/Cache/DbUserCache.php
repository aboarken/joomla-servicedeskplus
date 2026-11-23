<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Cache;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Psr\Log\LoggerInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\Identity;

/**
 * Per-user cache in #__sdp_cache, one row per entry. Unlike the session it survives logout and
 * session expiry, and requests running at the same time never overwrite each other's entries.
 *
 * An entry is fresh for the cache time (Integration Settings). On a normal page load an older
 * entry, up to STALE_SECONDS old, may be served while the page is refreshed in the background
 * (allowStale()); staleSince() then tells when the oldest one served was read from ServiceDesk Plus.
 * Keys always include the Joomla and SDP user ids, so a changed mapping never sees old entries.
 */
final class DbUserCache implements UserCacheInterface
{
    public const TABLE = '#__sdp_cache';

    /** Oldest entry ever shown (while it is being refreshed). Older rows are deleted. */
    public const STALE_SECONDS = 7 * 86400;

    /** Values larger than this (JSON bytes) are not cached. */
    private const MAX_BYTES = 4_000_000;

    /** Most entries kept per user; the oldest are removed (many different searches, pages, ...). */
    public const MAX_ROWS_PER_USER = 150;

    private bool $allowStale = false;

    private ?int $staleSince = null;

    /** @var array<string, array{0: int, 1: array<mixed>}|false> Rows read in this request */
    private array $memo = [];

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly int $ttl,
        private readonly LoggerInterface $logger,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function allowStale(bool $allow): void
    {
        $this->allowStale = $allow;
    }

    public function staleSince(): ?int
    {
        return $this->staleSince;
    }

    public function get(Identity $identity, string $key): ?array
    {
        if (!$identity->isMatched()) {
            return null;
        }

        // Caching switched off: nothing is kept between pages, but within one page a result is
        // read from ServiceDesk Plus only once.
        if ($this->ttl <= 0) {
            return $this->memo[$identity->joomlaUserId . '|' . $this->key($identity, $key)][1] ?? null;
        }

        $row = $this->read($identity->joomlaUserId, $this->key($identity, $key));

        if ($row === false) {
            return null;
        }

        [$created, $value] = $row;
        $age               = ($this->clock)() - $created;

        if ($age < $this->ttl) {
            return $value;
        }

        if ($this->allowStale && $age < self::STALE_SECONDS) {
            $this->staleSince = min($this->staleSince ?? $created, $created);

            return $value;
        }

        return null;
    }

    public function set(Identity $identity, string $key, array $value): void
    {
        if (!$identity->isMatched()) {
            return;
        }

        if ($this->ttl <= 0) {
            $this->memo[$identity->joomlaUserId . '|' . $this->key($identity, $key)] = [($this->clock)(), $value];

            return;
        }

        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException) {
            return;
        }

        if (\strlen($json) > self::MAX_BYTES) {
            return;
        }

        $userId  = $identity->joomlaUserId;
        $dbKey   = $this->key($identity, $key);
        $now     = ($this->clock)();
        $created = gmdate('Y-m-d H:i:s', $now);

        try {
            $update = $this->db->createQuery()
                ->update($this->db->quoteName(self::TABLE))
                ->set($this->db->quoteName('value') . ' = :value')
                ->set($this->db->quoteName('created') . ' = :created')
                ->where($this->db->quoteName('user_id') . ' = :user')
                ->where($this->db->quoteName('cache_key') . ' = :key')
                ->bind(':value', $json)
                ->bind(':created', $created)
                ->bind(':user', $userId, ParameterType::INTEGER)
                ->bind(':key', $dbKey);
            $this->db->setQuery($update)->execute();

            if ($this->db->getAffectedRows() === 0) {
                $row = (object) ['user_id' => $userId, 'cache_key' => $dbKey, 'value' => $json, 'created' => $created];

                try {
                    $this->db->insertObject(self::TABLE, $row);
                } catch (\RuntimeException) {
                    // Another request inserted it first; its value is just as new.
                }
            }

            // Now and then, remove what is too old to be shown and keep this user's rows bounded.
            if (random_int(1, 50) === 1) {
                $this->purge($now);
            }

            if (random_int(1, 10) === 1) {
                $this->trim($userId);
            }
        } catch (\RuntimeException $e) {
            // The cache is an optimisation: a failed write never breaks the page.
            $this->logger->warning('Cache write failed: ' . $e->getMessage());

            return;
        }

        $this->memo[$userId . '|' . $dbKey] = [$now, $value];
    }

    public function clear(Identity $identity, string $prefix = ''): void
    {
        if (!$identity->isMatched()) {
            return;
        }

        $userId = $identity->joomlaUserId;
        // "!" as escape character works the same on MySQL, PostgreSQL and SQLite.
        $like   = strtr($this->key($identity, $prefix), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';

        try {
            $query = $this->db->createQuery()
                ->delete($this->db->quoteName(self::TABLE))
                ->where($this->db->quoteName('user_id') . ' = :user')
                ->where($this->db->quoteName('cache_key') . " LIKE :prefix ESCAPE '!'")
                ->bind(':user', $userId, ParameterType::INTEGER)
                ->bind(':prefix', $like);
            $this->db->setQuery($query)->execute();
        } catch (\RuntimeException $e) {
            $this->logger->warning('Cache clear failed: ' . $e->getMessage());
        }

        $start = $userId . '|' . $this->key($identity, $prefix);

        foreach (array_keys($this->memo) as $memoKey) {
            if (str_starts_with($memoKey, $start)) {
                unset($this->memo[$memoKey]);
            }
        }
    }

    public function clearUser(int $joomlaUserId): void
    {
        try {
            $query = $this->db->createQuery()
                ->delete($this->db->quoteName(self::TABLE))
                ->where($this->db->quoteName('user_id') . ' = :user')
                ->bind(':user', $joomlaUserId, ParameterType::INTEGER);
            $this->db->setQuery($query)->execute();
        } catch (\RuntimeException $e) {
            $this->logger->warning('Cache clear failed: ' . $e->getMessage());
        }

        $this->memo = [];
    }

    public function clearAll(): void
    {
        try {
            $this->db->setQuery($this->db->createQuery()->delete($this->db->quoteName(self::TABLE)))->execute();
        } catch (\RuntimeException $e) {
            $this->logger->warning('Cache clear failed: ' . $e->getMessage());
        }

        $this->memo = [];
    }

    /**
     * Keep the newest MAX_ROWS_PER_USER rows of one user.
     */
    private function trim(int $userId): void
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName('created'))
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('user_id') . ' = :user')
            ->order($this->db->quoteName('created') . ' DESC')
            ->bind(':user', $userId, ParameterType::INTEGER);
        $cutoff = $this->db->setQuery($query, self::MAX_ROWS_PER_USER, 1)->loadResult();

        if (!\is_string($cutoff) || $cutoff === '') {
            return;
        }

        $delete = $this->db->createQuery()
            ->delete($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('user_id') . ' = :user')
            ->where($this->db->quoteName('created') . ' < :cutoff')
            ->bind(':user', $userId, ParameterType::INTEGER)
            ->bind(':cutoff', $cutoff);
        $this->db->setQuery($delete)->execute();
    }

    /**
     * @return array{0: int, 1: array<mixed>}|false  [created (Unix time), value]
     */
    private function read(int $userId, string $dbKey): array|false
    {
        $memoKey = $userId . '|' . $dbKey;

        if (\array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }

        try {
            $query = $this->db->createQuery()
                ->select($this->db->quoteName(['value', 'created']))
                ->from($this->db->quoteName(self::TABLE))
                ->where($this->db->quoteName('user_id') . ' = :user')
                ->where($this->db->quoteName('cache_key') . ' = :key')
                ->bind(':user', $userId, ParameterType::INTEGER)
                ->bind(':key', $dbKey);
            $row = $this->db->setQuery($query)->loadAssoc();
        } catch (\RuntimeException $e) {
            $this->logger->warning('Cache read failed: ' . $e->getMessage());
            $row = null;
        }

        $value   = \is_array($row) ? json_decode((string) $row['value'], true) : null;
        $created = \is_array($row) ? strtotime((string) $row['created'] . ' UTC') : false;

        return $this->memo[$memoKey] = \is_array($value) && $created !== false ? [$created, $value] : false;
    }

    private function purge(int $now): void
    {
        $before = gmdate('Y-m-d H:i:s', $now - self::STALE_SECONDS);
        $query  = $this->db->createQuery()
            ->delete($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('created') . ' < :before')
            ->bind(':before', $before);
        $this->db->setQuery($query)->execute();
    }

    /**
     * "<SDP user id>|<key>", at most 190 characters (longer keys are hashed, same prefix kept).
     */
    private function key(Identity $identity, string $key): string
    {
        $full = $identity->sdpUserId() . '|' . $key;

        return \strlen($full) <= 190 ? $full : substr($full, 0, 120) . '#' . hash('sha256', $full);
    }
}
