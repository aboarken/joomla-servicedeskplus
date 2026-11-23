<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Tests\Support;

use SdpPortal\Component\ServiceDeskPlus\Administrator\Cache\UserCacheInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\Identity;

/** The per-user cache in memory, keyed like DbUserCache (Joomla user, SDP user, key). */
final class ArrayCache implements UserCacheInterface
{
    /** @var array<string, array<mixed>> */
    public array $data = [];

    public array $clearedUsers = [];

    private static function id(Identity $identity, string $key): string
    {
        return $identity->joomlaUserId . '|' . ($identity->isMatched() ? $identity->sdpUserId() : '-') . '|' . $key;
    }

    public function get(Identity $identity, string $key): ?array
    {
        return $this->data[self::id($identity, $key)] ?? null;
    }

    public function set(Identity $identity, string $key, array $value): void
    {
        $this->data[self::id($identity, $key)] = $value;
    }

    public function clear(Identity $identity, string $prefix = ''): void
    {
        $start = self::id($identity, $prefix);

        foreach (array_keys($this->data) as $id) {
            if (str_starts_with($id, $start)) {
                unset($this->data[$id]);
            }
        }
    }

    public function allowStale(bool $allow): void
    {
    }

    public function staleSince(): ?int
    {
        return null;
    }

    public function clearUser(int $joomlaUserId): void
    {
        $this->clearedUsers[] = $joomlaUserId;

        foreach (array_keys($this->data) as $id) {
            if (str_starts_with($id, $joomlaUserId . '|')) {
                unset($this->data[$id]);
            }
        }
    }

    public function clearAll(): void
    {
        $this->data = [];
    }
}
