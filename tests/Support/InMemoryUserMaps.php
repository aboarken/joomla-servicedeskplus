<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Tests\Support;

use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\UserMapping;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\UserMapRepositoryInterface;

/** #__sdp_user_map in memory, with the unique index on sdp_user_id. */
final class InMemoryUserMaps implements UserMapRepositoryInterface
{
    /** @var array<int, UserMapping> */
    public array $rows = [];

    public function find(int $joomlaUserId): ?UserMapping
    {
        return $this->rows[$joomlaUserId] ?? null;
    }

    public function findBySdpUserId(string $sdpUserId): ?UserMapping
    {
        foreach ($this->rows as $row) {
            if ($row->sdpUserId === $sdpUserId) {
                return $row;
            }
        }

        return null;
    }

    public function save(UserMapping $mapping): void
    {
        $owner = $mapping->sdpUserId !== null ? $this->findBySdpUserId($mapping->sdpUserId) : null;

        if ($owner !== null && $owner->joomlaUserId !== $mapping->joomlaUserId) {
            throw new \RuntimeException('Duplicate sdp_user_id');
        }

        $this->rows[$mapping->joomlaUserId] = $mapping;
    }

    public function delete(int $joomlaUserId): void
    {
        unset($this->rows[$joomlaUserId]);
    }
}
