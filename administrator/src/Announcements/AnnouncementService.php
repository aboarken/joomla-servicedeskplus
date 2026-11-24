<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Announcements;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


use Psr\Log\LoggerInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\ListInfo;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpClient;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpException;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Cache\UserCacheInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\Identity;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Support\SdpFields;

/**
 * ServiceDesk Plus announcements the user may see (Announcements folder of the On-Premises
 * Postman collection: GET /api/v3/announcements and /api/v3/announcements/{id}).
 *
 * The service key sees every announcement, so the portal applies ServiceDesk Plus' own rules and
 * hides an announcement whenever it cannot be sure:
 *  - the display period (from_date / to_date) must include now;
 *  - technicians (is_technician on their ServiceDesk Plus user record) see every announcement,
 *    including internal ones ("is_public" false);
 *  - other users only see public announcements; one limited to user groups only when the user's
 *    ServiceDesk Plus record lists one of those groups.
 */
final class AnnouncementService
{
    /** Newest announcements read from ServiceDesk Plus. */
    public const MAX_ITEMS = 100;

    private const HIDDEN_PERIOD   = 'outside display period';
    private const HIDDEN_INTERNAL = 'internal (technicians only)';
    private const HIDDEN_GROUPS   = 'other user groups';

    /** @var array<string, array{technician: bool, groups: list<string>}> SDP user id => profile, per page load */
    private array $profiles = [];

    /**
     * @param  \Closure(): SdpClient  $clientFactory
     * @param  \Closure(): int|null  $clock
     */
    public function __construct(
        private readonly \Closure $clientFactory,
        private readonly UserCacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $clock = null
    ) {
    }

    /**
     * Newest first.
     *
     * @return list<Announcement>
     *
     * @throws SdpException
     */
    public function listForUser(Identity $identity): array
    {
        $cached = $this->cache->get($identity, 'announcements:list');

        if ($cached !== null) {
            return array_map(static fn (array $a) => Announcement::fromArray($a), $cached);
        }

        $client = ($this->clientFactory)();
        $page   = $client->listAnnouncements(new ListInfo(rowCount: self::MAX_ITEMS, sortField: 'id', sortOrder: 'desc'));
        $now    = $this->now();
        $result = [];
        $hidden = [];

        // The list may leave out the group list of a restricted announcement: the full ones that
        // decide what this user sees are read at once.
        $needFull = [];

        foreach ($page->items as $row) {
            $id = SdpFields::string($row, 'id');

            if (
                self::isRestricted($row) && !\is_array($row['user_groups'] ?? null)
                && self::inPeriod($row, $now) && self::isPublic($row)
                && preg_match('/^[1-9][0-9]{0,19}$/', $id)
                && !$this->profile($identity, $client)['technician']
            ) {
                $needFull[] = $id;
            }
        }

        $full = $client->getAnnouncements($needFull);

        foreach ($page->items as $row) {
            $read = $full[SdpFields::string($row, 'id')] ?? null;

            if ($read instanceof SdpException) {
                if (\in_array($read->getKind(), [SdpException::NETWORK, SdpException::AUTH], true)) {
                    throw $read;
                }
            } elseif (\is_array($read)) {
                $row = $read;
            }

            $reason = $this->hiddenReason($row, $identity, $client, $now);

            if ($reason === null) {
                $result[] = Announcement::fromSdp($row);
            } else {
                $hidden[$reason] = ($hidden[$reason] ?? 0) + 1;
            }
        }

        usort($result, static fn (Announcement $a, Announcement $b): int => [$b->date() ?? 0, (int) $b->id] <=> [$a->date() ?? 0, (int) $a->id]);

        // One line per load, so an administrator can see why announcements are not shown.
        $this->logger->info(\sprintf(
            'Announcements for ServiceDesk Plus user %s (%s): %d read, %d shown%s',
            $identity->sdpUserId(),
            ($this->profiles[$identity->sdpUserId()]['technician'] ?? false) ? 'technician' : 'requester',
            \count($page->items),
            \count($result),
            $hidden === [] ? '' : '; hidden: ' . implode(', ', array_map(static fn ($k, $v) => "$v $k", array_keys($hidden), $hidden))
        ));

        $this->cache->set($identity, 'announcements:list', array_map(static fn (Announcement $a) => $a->toArray(), $result));

        return $result;
    }

    /**
     * @throws AnnouncementNotFound  the announcement does not exist or is not shown to the user
     * @throws SdpException
     */
    public function getForUser(Identity $identity, string $id): Announcement
    {
        try {
            $id = SdpClient::id($id);
        } catch (\InvalidArgumentException) {
            throw new AnnouncementNotFound();
        }

        $client = ($this->clientFactory)();

        try {
            $row = $client->getAnnouncement($id);
        } catch (SdpException $e) {
            if ($e->getKind() === SdpException::NOT_FOUND) {
                throw new AnnouncementNotFound();
            }

            throw $e;
        }

        if (SdpFields::string($row, 'id') !== $id || $this->hiddenReason($row, $identity, $client, $this->now()) !== null) {
            throw new AnnouncementNotFound();
        }

        return Announcement::fromSdp($row);
    }

    /**
     * Why the user may not see the announcement, or null when they may.
     *
     * @param  array<string, mixed>  $row
     */
    private function hiddenReason(array $row, Identity $identity, SdpClient $client, int $now): ?string
    {
        if (!self::inPeriod($row, $now)) {
            return self::HIDDEN_PERIOD;
        }

        // Technicians see what ServiceDesk Plus shows them: every announcement.
        if ($this->profile($identity, $client)['technician']) {
            return null;
        }

        if (!self::isPublic($row)) {
            return self::HIDDEN_INTERNAL;
        }

        if (!self::isRestricted($row)) {
            return null;
        }

        $groups = self::ids($row['user_groups'] ?? null);

        return $groups !== [] && array_intersect($groups, $this->profile($identity, $client)['groups']) !== [] ? null : self::HIDDEN_GROUPS;
    }

    /** @param array<string, mixed> $row */
    private static function isPublic(array $row): bool
    {
        return filter_var($row['is_public'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** @param array<string, mixed> $row */
    private static function inPeriod(array $row, int $now): bool
    {
        $from = SdpFields::timestamp($row, 'from_date');
        $to   = SdpFields::timestamp($row, 'to_date');

        return ($from === null || $from <= $now) && ($to === null || $to >= $now);
    }

    /** @param array<string, mixed> $row */
    private static function isRestricted(array $row): bool
    {
        return filter_var($row['has_user_group'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || self::ids($row['user_groups'] ?? null) !== [];
    }

    /**
     * Whether the user is a technician, and their user group ids, from their ServiceDesk Plus
     * record (GET /api/v3/users/{id}: is_technician, type). Unreadable: a requester in no group.
     *
     * @return array{technician: bool, groups: list<string>}
     */
    private function profile(Identity $identity, SdpClient $client): array
    {
        $userId = $identity->sdpUserId();

        if (!isset($this->profiles[$userId])) {
            try {
                $user = $client->getUser($userId);
                $this->profiles[$userId] = [
                    'technician' => filter_var($user['is_technician'] ?? false, FILTER_VALIDATE_BOOLEAN)
                        || strcasecmp(SdpFields::string($user, 'type'), 'Technician') === 0,
                    'groups'     => self::ids($user['user_groups'] ?? null),
                ];
            } catch (SdpException $e) {
                if (\in_array($e->getKind(), [SdpException::NETWORK, SdpException::AUTH], true)) {
                    throw $e;
                }

                $this->logger->warning(\sprintf('ServiceDesk Plus user %s could not be read for announcements (%s)', $userId, $e->getKind()));
                $this->profiles[$userId] = ['technician' => false, 'groups' => []];
            }
        }

        return $this->profiles[$userId];
    }

    /**
     * @return list<string>
     */
    private static function ids(mixed $list): array
    {
        if (!\is_array($list)) {
            return [];
        }

        $ids = [];

        foreach ($list as $item) {
            $id = \is_array($item) ? ($item['id'] ?? null) : $item;

            if (\is_scalar($id) && preg_match('/^[0-9]{1,20}$/', (string) $id)) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
