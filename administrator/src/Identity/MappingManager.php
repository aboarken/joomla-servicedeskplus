<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpClient;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpException;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Audit\AuditLogInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Cache\UserCacheInterface;

/**
 * Admin operations on #__sdp_user_map. Every change is audited with the admin as actor.
 */
final class MappingManager
{
    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param  \Closure(): SdpClient                  $clientFactory
     * @param  (\Closure(): \DateTimeImmutable)|null  $clock
     * @param  (\Closure(int): bool)|null             $userExists  Whether a Joomla user id still exists; null = not checked
     */
    public function __construct(
        private readonly UserMapRepositoryInterface $repository,
        private readonly \Closure $clientFactory,
        private readonly AuditLogInterface $audit,
        ?\Closure $clock = null,
        private readonly ?UserCacheInterface $cache = null,
        private readonly string $apiAccountEmail = '',
        private readonly ?\Closure $userExists = null
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Set or override the mapping. The SDP user must exist and must not be mapped to someone else.
     * The current Joomla email is stored, so a later Joomla email change suspends the mapping.
     *
     * @return array<string, mixed>  The SDP user record (for showing name/email to the admin)
     *
     * @throws MappingException|SdpException
     */
    public function assign(int $joomlaUserId, string $joomlaEmail, string $sdpUserId, int $actorId, string $source = UserMapping::SOURCE_ADMIN): array
    {
        if ($joomlaUserId <= 0) {
            throw new MappingException('COM_SERVICEDESKPLUS_MAPPING_ERR_USER');
        }

        try {
            $sdpUserId = SdpClient::id(trim($sdpUserId));
        } catch (\InvalidArgumentException) {
            throw new MappingException('COM_SERVICEDESKPLUS_MAPPING_ERR_SDP_ID');
        }

        $sdpUser = $this->fetchSdpUser($sdpUserId);

        // The API technician decides on approvers' behalf and may hold approval entries of its
        // own: a portal user linked to it would see and decide those.
        $account = EmailNormalizer::normalize($this->apiAccountEmail);

        if ($account !== '' && EmailNormalizer::normalize($sdpUser['email_id'] ?? '') === $account) {
            $this->audit->record($joomlaUserId, 'mapping.assign', AuditLogInterface::RESULT_DENIED, null, null, 'api_account sdp_user:' . $sdpUserId, $actorId);

            throw new MappingException('COM_SERVICEDESKPLUS_MAPPING_ERR_API_ACCOUNT');
        }

        $owner   = $this->repository->findBySdpUserId($sdpUserId);

        // The mapping of a deleted Joomla account no longer holds the ServiceDesk Plus user.
        if ($owner !== null && $owner->joomlaUserId !== $joomlaUserId && $this->userExists !== null && !($this->userExists)($owner->joomlaUserId)) {
            $this->repository->delete($owner->joomlaUserId);
            $this->cache?->clearUser($owner->joomlaUserId);
            $this->audit->record($owner->joomlaUserId, 'identity.orphan_removed', AuditLogInterface::RESULT_OK, null, null, 'joomla_user_deleted sdp_user:' . $sdpUserId, $actorId);
            $owner = null;
        }

        if ($owner !== null && $owner->joomlaUserId !== $joomlaUserId) {
            $this->audit->record($joomlaUserId, 'mapping.assign', AuditLogInterface::RESULT_DENIED, null, null, 'sdp_user_already_mapped:' . $owner->joomlaUserId, $actorId);

            throw new MappingException('COM_SERVICEDESKPLUS_MAPPING_ERR_TAKEN');
        }

        $now = ($this->clock)();

        try {
            $this->repository->save(new UserMapping(
                $joomlaUserId,
                $sdpUserId,
                EmailNormalizer::normalize($joomlaEmail),
                UserMapping::STATUS_ACTIVE,
                $source === UserMapping::SOURCE_AUTO ? UserMapping::SOURCE_AUTO : UserMapping::SOURCE_ADMIN,
                $now,
                $now,
                $actorId
            ));
        } catch (\RuntimeException $e) {
            // The unique index on sdp_user_id refused it: another request mapped this SDP user first.
            $owner = $this->repository->findBySdpUserId($sdpUserId);

            if ($owner === null || $owner->joomlaUserId === $joomlaUserId) {
                throw $e;
            }

            $this->audit->record($joomlaUserId, 'mapping.assign', AuditLogInterface::RESULT_DENIED, null, null, 'sdp_user_already_mapped:' . $owner->joomlaUserId, $actorId);

            throw new MappingException('COM_SERVICEDESKPLUS_MAPPING_ERR_TAKEN');
        }

        $this->audit->record($joomlaUserId, 'mapping.assign', AuditLogInterface::RESULT_OK, null, null, 'sdp_user:' . $sdpUserId, $actorId);
        $this->cache?->clearUser($joomlaUserId);

        return $sdpUser;
    }

    /**
     * Approve a mapping waiting for approval, or re-confirm a suspended one: keeps the SDP user, accepts the user's current Joomla email.
     *
     * @return array<string, mixed>
     *
     * @throws MappingException|SdpException
     */
    public function confirm(int $joomlaUserId, string $joomlaEmail, int $actorId): array
    {
        $mapping = $this->repository->find($joomlaUserId);

        if ($mapping === null || $mapping->sdpUserId === null) {
            throw new MappingException('COM_SERVICEDESKPLUS_MAPPING_ERR_NOTHING_TO_CONFIRM');
        }

        // Confirm only links what the e-mails support right now: the SDP user stored with the row
        // must still have the user's current Joomla e-mail. Otherwise someone could be found with
        // a colleague's address, change their e-mail back, and be confirmed with the colleague's
        // SDP user. A deliberate link of different addresses stays possible by editing the mapping.
        $sdpUser = $this->fetchSdpUser($mapping->sdpUserId);

        if (EmailNormalizer::normalize($sdpUser['email_id'] ?? '') !== EmailNormalizer::normalize($joomlaEmail)) {
            $this->audit->record($joomlaUserId, 'mapping.confirm', AuditLogInterface::RESULT_DENIED, null, null, 'email_differs sdp_user:' . $mapping->sdpUserId, $actorId);

            throw new MappingException('COM_SERVICEDESKPLUS_MAPPING_ERR_CONFIRM_EMAIL');
        }

        // The e-mails match, so it stays an e-mail match ("auto"): it is checked against
        // ServiceDesk Plus again once a day and suspended if the SDP user is gone or its e-mail
        // changed. A mapping set by hand (New / Edit) is "admin" and is not re-checked.
        return $this->assign($joomlaUserId, $joomlaEmail, $mapping->sdpUserId, $actorId, UserMapping::SOURCE_AUTO);
    }

    public function suspend(int $joomlaUserId, int $actorId): void
    {
        $mapping = $this->repository->find($joomlaUserId);

        if ($mapping === null) {
            throw new MappingException('COM_SERVICEDESKPLUS_MAPPING_ERR_NOT_FOUND');
        }

        $this->repository->save($mapping->with([
            'status'     => UserMapping::STATUS_SUSPENDED,
            'checkedAt'  => ($this->clock)(),
            'modifiedBy' => $actorId,
        ]));

        $this->audit->record($joomlaUserId, 'mapping.suspend', AuditLogInterface::RESULT_OK, null, null, '', $actorId);
        $this->cache?->clearUser($joomlaUserId);
    }

    /**
     * Delete the mapping so the next visit runs automatic matching again.
     */
    public function rematch(int $joomlaUserId, int $actorId): void
    {
        $this->repository->delete($joomlaUserId);
        $this->audit->record($joomlaUserId, 'mapping.rematch', AuditLogInterface::RESULT_OK, null, null, '', $actorId);
        $this->cache?->clearUser($joomlaUserId);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSdpUser(string $sdpUserId): array
    {
        try {
            return ($this->clientFactory)()->getUser($sdpUserId);
        } catch (SdpException $e) {
            if ($e->getKind() === SdpException::NOT_FOUND) {
                throw new MappingException('COM_SERVICEDESKPLUS_MAPPING_ERR_SDP_NOT_FOUND');
            }

            throw $e;
        }
    }
}
