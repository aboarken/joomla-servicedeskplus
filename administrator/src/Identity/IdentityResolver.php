<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Psr\Log\LoggerInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpClient;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpException;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Audit\AuditLogInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Cache\UserCacheInterface;

/**
 * Maps the logged-in Joomla user to exactly one SDP user by email.
 *
 * Rules:
 *  - Emails are compared after trim + lowercase, and must be equal (not "contains").
 *  - Zero matches means "not matched". Several SDP users with the email means "ambiguous": an
 *    admin chooses.
 *  - A Joomla e-mail that differs from the one stored with any mapping row (active, pending,
 *    unmatched or ambiguous) suspends it: a changed e-mail is never matched automatically.
 *  - A new match by e-mail is active at once (Integration Settings → New users: Approve
 *    automatically, the default). With "Wait for administrator approval" it is stored as
 *    "pending": the user sees nothing of that SDP user until an administrator confirms it in User
 *    Mappings. Mappings made or confirmed by an administrator, and those already active, are not
 *    held. A pending row is approved on a later visit once automatic approval is on again, after
 *    a fresh check that SDP still has the address.
 *  - One SDP user can only be mapped to one Joomla user. If the chosen SDP user is already mapped
 *    to another Joomla user, the result is "ambiguous" and an admin must decide.
 *  - If the Joomla email no longer equals the email stored with an active mapping, the mapping is
 *    suspended until an admin re-confirms it. It is never re-matched automatically.
 *  - Automatic active mappings are re-verified against SDP once a day; if the SDP user is gone or
 *    its email changed, the mapping is suspended.
 *  - Unmatched/ambiguous results are retried at most every RECHECK_SECONDS.
 *  - The API technician's e-mail (Integration Settings → Approvals) is never matched, and an
 *    active mapping with that e-mail is suspended.
 *  - A mapping whose Joomla account was deleted is removed when its ServiceDesk Plus user is
 *    matched again, so a new account of the same person can be linked.
 */
final class IdentityResolver
{
    public const RECHECK_SECONDS  = 900;
    public const REVERIFY_SECONDS = 86400;
    /** After a failed daily re-check, the next try comes this much later. */
    public const RETRY_SECONDS    = 900;

    /** @var array<int, Identity> */
    private array $memo = [];

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param  \Closure(): SdpClient                    $clientFactory  Lazy, so active mappings need no SDP call
     * @param  (\Closure(): \DateTimeImmutable)|null    $clock
     * @param  (\Closure(int): bool)|null               $userExists     Whether a Joomla user id still exists; null = not checked
     */
    public function __construct(
        private readonly UserMapRepositoryInterface $repository,
        private readonly \Closure $clientFactory,
        private readonly AuditLogInterface $audit,
        private readonly LoggerInterface $logger,
        ?\Closure $clock = null,
        private readonly ?UserCacheInterface $cache = null,
        private readonly bool $autoApprove = true,
        private readonly string $apiAccountEmail = '',
        private readonly ?\Closure $userExists = null
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @throws SdpException when SDP must be asked and cannot answer (nothing is stored in that case)
     */
    public function resolve(int $joomlaUserId, string $joomlaEmail): Identity
    {
        if ($joomlaUserId <= 0) {
            return Identity::notMatched(0, UserMapping::STATUS_UNMATCHED);
        }

        if (isset($this->memo[$joomlaUserId])) {
            return $this->memo[$joomlaUserId];
        }

        return $this->memo[$joomlaUserId] = $this->doResolve($joomlaUserId, EmailNormalizer::normalize($joomlaEmail));
    }

    private function doResolve(int $userId, string $email): Identity
    {
        $now     = ($this->clock)();
        $mapping = $this->repository->find($userId);

        if ($mapping !== null) {
            if ($mapping->status === UserMapping::STATUS_SUSPENDED) {
                return Identity::notMatched($userId, UserMapping::STATUS_SUSPENDED);
            }

            // Found by e-mail, waiting for an administrator. Another e-mail since then suspends it,
            // as for every other mapping. With automatic approval on, SDP is asked again (same
            // address, still exactly one SDP user) and the match becomes active; otherwise there
            // is no SDP call until an administrator confirms it.
            if ($mapping->status === UserMapping::STATUS_PENDING) {
                if ($mapping->email !== $email) {
                    $this->suspend($mapping, $now, 'joomla_email_changed');

                    return Identity::notMatched($userId, UserMapping::STATUS_SUSPENDED);
                }

                if ($this->autoApprove) {
                    return $this->lookup($userId, $email, $now);
                }

                return Identity::notMatched($userId, UserMapping::STATUS_PENDING);
            }

            if ($mapping->isActive()) {
                if ($mapping->email !== $email) {
                    $this->suspend($mapping, $now, 'joomla_email_changed');

                    return Identity::notMatched($userId, UserMapping::STATUS_SUSPENDED);
                }

                // Linked by e-mail to the API technician before this check existed.
                if ($this->isApiAccountEmail($email)) {
                    $this->suspend($mapping, $now, 'api_account');

                    return Identity::notMatched($userId, UserMapping::STATUS_SUSPENDED);
                }

                if ($mapping->source === UserMapping::SOURCE_AUTO && $this->age($mapping, $now) >= self::REVERIFY_SECONDS) {
                    return $this->reverify($mapping, $email, $now);
                }

                return Identity::matched($userId, (string) $mapping->sdpUserId);
            }

            // Unmatched or ambiguous with another e-mail: the user changed their Joomla e-mail.
            // Matching it automatically would let anyone take over an SDP user who has no
            // portal account yet by entering that person's address in their profile, so an
            // admin decides (User Mappings: Re-match or New).
            if ($mapping->email !== $email) {
                $this->suspend($mapping, $now, 'joomla_email_changed');

                return Identity::notMatched($userId, UserMapping::STATUS_SUSPENDED);
            }

            // Unmatched or ambiguous: reuse the recent result.
            if ($this->age($mapping, $now) < self::RECHECK_SECONDS) {
                return Identity::notMatched($userId, $mapping->status);
            }
        }

        return $this->lookup($userId, $email, $now);
    }

    private function lookup(int $userId, string $email, \DateTimeImmutable $now): Identity
    {
        if (!EmailNormalizer::isUsable($email)) {
            return $this->storeNotMatched($userId, $email, UserMapping::STATUS_UNMATCHED, $now, 'invalid_email');
        }

        // The API technician (Integration Settings → Approvals) decides on approvers' behalf and
        // may hold approval entries of its own. No portal user is ever linked to it by e-mail.
        if ($this->isApiAccountEmail($email)) {
            return $this->storeNotMatched($userId, $email, UserMapping::STATUS_UNMATCHED, $now, 'api_account');
        }

        $candidates = $this->client()->findUsersByEmail($email);
        $ids        = [];

        foreach ($candidates as $user) {
            $id = isset($user['id']) ? (string) $user['id'] : '';

            if (preg_match('/^[0-9]{1,20}$/', $id) && EmailNormalizer::normalize($user['email_id'] ?? '') === $email) {
                $ids[$id] = true;
            }
        }

        if (\count($ids) === 0) {
            return $this->storeNotMatched($userId, $email, UserMapping::STATUS_UNMATCHED, $now, 'no_sdp_user');
        }

        // Several SDP users with this e-mail: an admin chooses (User Mappings), so a newer
        // duplicate record never takes over someone's requests and approvals silently.
        if (\count($ids) > 1) {
            return $this->storeNotMatched($userId, $email, UserMapping::STATUS_AMBIGUOUS, $now, 'several_sdp_users:' . implode(',', array_map('strval', array_keys($ids))));
        }

        $sdpUserId = (string) array_key_first($ids);
        $detail    = 'sdp_user:' . $sdpUserId;
        $owner     = $this->repository->findBySdpUserId($sdpUserId);

        // The mapping of a Joomla account that was deleted no longer holds the ServiceDesk Plus
        // user: without this, a new account of the same person could never be matched.
        if ($owner !== null && $owner->joomlaUserId !== $userId && $this->userExists !== null && !($this->userExists)($owner->joomlaUserId)) {
            $this->repository->delete($owner->joomlaUserId);
            $this->cache?->clearUser($owner->joomlaUserId);
            $this->audit->record($owner->joomlaUserId, 'identity.orphan_removed', AuditLogInterface::RESULT_OK, null, null, 'joomla_user_deleted sdp_user:' . $sdpUserId);
            $owner = null;
        }

        if ($owner !== null && $owner->joomlaUserId !== $userId) {
            $this->logger->warning(\sprintf('SDP user %s is already mapped to Joomla user %d; not mapping user %d', $sdpUserId, $owner->joomlaUserId, $userId));

            return $this->storeNotMatched($userId, $email, UserMapping::STATUS_AMBIGUOUS, $now, 'sdp_user_already_mapped');
        }

        if ($this->autoApprove) {
            try {
                $this->repository->save(new UserMapping($userId, $sdpUserId, $email, UserMapping::STATUS_ACTIVE, UserMapping::SOURCE_AUTO, $now, $now));
            } catch (\RuntimeException) {
                // Another account was stored with this SDP user at the same moment (unique index).
                return $this->storeNotMatched($userId, $email, UserMapping::STATUS_AMBIGUOUS, $now, 'sdp_user_already_mapped');
            }

            $this->audit->record($userId, 'identity.matched', AuditLogInterface::RESULT_OK, null, null, $detail);

            return Identity::matched($userId, $sdpUserId);
        }

        // "Wait for administrator approval": Joomla lets users change their own e-mail without
        // verifying it, so an address alone does not prove who someone is. The SDP user found is
        // kept, and an administrator confirms it (User Mappings → Confirm) before the user sees
        // anything of that SDP user.
        try {
            $this->repository->save(new UserMapping($userId, $sdpUserId, $email, UserMapping::STATUS_PENDING, UserMapping::SOURCE_AUTO, null, $now));
        } catch (\RuntimeException) {
            // Another account was stored with this SDP user at the same moment (unique index).
            return $this->storeNotMatched($userId, $email, UserMapping::STATUS_AMBIGUOUS, $now, 'sdp_user_already_mapped');
        }

        $this->audit->record($userId, 'identity.pending', AuditLogInterface::RESULT_OK, null, null, $detail);

        return Identity::notMatched($userId, UserMapping::STATUS_PENDING);
    }

    private function reverify(UserMapping $mapping, string $email, \DateTimeImmutable $now): Identity
    {
        $userId = $mapping->joomlaUserId;

        try {
            $sdpUser = $this->client()->getUser((string) $mapping->sdpUserId);
        } catch (SdpException $e) {
            if ($e->getKind() === SdpException::NOT_FOUND) {
                $this->suspend($mapping, $now, 'sdp_user_not_found');

                return Identity::notMatched($userId, UserMapping::STATUS_SUSPENDED);
            }

            // SDP unavailable: keep the verified mapping and try again in RETRY_SECONDS, not on
            // every page (each try would first wait for ServiceDesk Plus to answer or time out).
            $this->logger->warning(\sprintf('Could not re-verify mapping for Joomla user %d (%s)', $userId, $e->getKind()));

            try {
                $this->repository->save($mapping->with(['checkedAt' => $now->modify('-' . (self::REVERIFY_SECONDS - self::RETRY_SECONDS) . ' seconds')]));
            } catch (\RuntimeException) {
                // Not saved: it is tried again on the next page, as before.
            }

            return Identity::matched($userId, (string) $mapping->sdpUserId);
        }

        if (EmailNormalizer::normalize($sdpUser['email_id'] ?? '') !== $email) {
            $this->suspend($mapping, $now, 'sdp_email_changed');

            return Identity::notMatched($userId, UserMapping::STATUS_SUSPENDED);
        }

        $this->repository->save($mapping->with(['checkedAt' => $now]));

        return Identity::matched($userId, (string) $mapping->sdpUserId);
    }

    private function suspend(UserMapping $mapping, \DateTimeImmutable $now, string $reason): void
    {
        $this->repository->save($mapping->with(['status' => UserMapping::STATUS_SUSPENDED, 'checkedAt' => $now]));
        $this->audit->record($mapping->joomlaUserId, 'identity.suspended', AuditLogInterface::RESULT_DENIED, null, null, $reason);
        $this->cache?->clearUser($mapping->joomlaUserId);
    }

    private function storeNotMatched(int $userId, string $email, string $status, \DateTimeImmutable $now, string $reason): Identity
    {
        $this->repository->save(new UserMapping($userId, null, $email, $status, UserMapping::SOURCE_AUTO, null, $now));
        $this->audit->record($userId, 'identity.' . $status, AuditLogInterface::RESULT_DENIED, null, null, mb_substr($reason, 0, 255));

        return Identity::notMatched($userId, $status);
    }

    private function isApiAccountEmail(string $email): bool
    {
        $account = EmailNormalizer::normalize($this->apiAccountEmail);

        return $account !== '' && $account === $email;
    }

    private function age(UserMapping $mapping, \DateTimeImmutable $now): int
    {
        return $now->getTimestamp() - $mapping->checkedAt->getTimestamp();
    }

    private function client(): SdpClient
    {
        return ($this->clientFactory)();
    }
}
