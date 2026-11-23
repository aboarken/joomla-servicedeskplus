<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Requests;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


use Psr\Log\LoggerInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpClient;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpException;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\ApprovalModule;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Cache\UserCacheInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\Identity;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Support\HtmlText;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Support\SdpFields;

/**
 * The approval levels of a request and each approver's status, for the request page
 * (GET /api/v3/requests/{id}/approval_levels and .../approval_levels/{level_id}/approvals).
 *
 * The caller must have checked that the user may see the request. Requests without approvals
 * give an empty list.
 */
final class RequestApprovalsReader
{
    /** Levels read per request; each needs one extra call for its approvers. */
    public const MAX_LEVELS = 10;

    /**
     * @param  \Closure(): SdpClient  $clientFactory
     */
    public function __construct(
        private readonly \Closure $clientFactory,
        private readonly UserCacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * By level, then in the order ServiceDesk Plus lists the approvers. Problems reading the
     * approvals are logged and give what could be read.
     *
     * @return list<RequestApproval>
     *
     * @throws SdpException  network, authentication and configuration problems
     */
    public function forRequest(Identity $identity, string $requestId): array
    {
        $requestId = SdpClient::id($requestId);
        $cacheKey  = 'request_approvals:' . $requestId;
        $cached    = $this->cache->get($identity, $cacheKey);

        if ($cached !== null) {
            return array_map(static fn (array $a) => RequestApproval::fromArray($a), $cached);
        }

        $client = ($this->clientFactory)();
        $fatal  = [SdpException::NETWORK, SdpException::AUTH, SdpException::NOT_CONFIGURED];

        try {
            $levels = $client->getApprovalLevels($requestId, ApprovalModule::REQUEST);
        } catch (SdpException $e) {
            if (\in_array($e->getKind(), $fatal, true)) {
                throw $e;
            }

            // Requests without approvals may answer "not found" instead of an empty list.
            if ($e->getKind() !== SdpException::NOT_FOUND) {
                $this->logger->warning(\sprintf('Approvals of request %s could not be read (%s)', $requestId, $e->getKind()));
            }

            return [];
        }

        usort($levels, static fn (array $a, array $b): int => (int) ($a['level'] ?? 0) <=> (int) ($b['level'] ?? 0));

        $levels = array_filter(\array_slice($levels, 0, self::MAX_LEVELS), static fn (array $l): bool => !self::isDeleted($l));
        $ids    = array_values(array_filter(
            array_map(static fn (array $l): string => SdpFields::string($l, 'id'), $levels),
            static fn (string $id): bool => (bool) preg_match('/^[1-9][0-9]{0,19}$/', $id)
        ));

        // The approvals of every level, read at once.
        $byLevel = $client->getApprovalsOfLevels($requestId, $ids, ApprovalModule::REQUEST);
        $entries = [];

        foreach ($levels as $index => $level) {
            $levelId   = SdpFields::string($level, 'id');
            $approvals = $byLevel[$levelId] ?? null;

            if ($approvals instanceof SdpException) {
                if (\in_array($approvals->getKind(), $fatal, true)) {
                    throw $approvals;
                }

                $this->logger->warning(\sprintf('Approvals of request %s, level %s could not be read (%s)', $requestId, $levelId, $approvals->getKind()));

                continue;
            }

            if (!\is_array($approvals)) {
                continue;
            }

            $number = (int) ($level['level'] ?? 0) ?: $index + 1;

            foreach ($approvals as $approval) {
                if (self::isDeleted($approval)) {
                    continue;
                }

                $entries[] = new RequestApproval(
                    $number,
                    SdpFields::string($level, 'name'),
                    SdpFields::name($level, 'status'),
                    filter_var($level['is_current'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    SdpFields::name($approval, 'approver'),
                    SdpFields::name($approval, 'status'),
                    SdpFields::timestamp($approval, 'sent_on'),
                    SdpFields::timestamp($approval, 'action_taken_on'),
                    HtmlText::toPlain(SdpFields::string($approval, 'comments'))
                );
            }
        }

        $this->cache->set($identity, $cacheKey, array_map(static fn (RequestApproval $a) => $a->toArray(), $entries));

        return $entries;
    }

    /** @param array<string, mixed> $item */
    private static function isDeleted(array $item): bool
    {
        return filter_var($item['deleted'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
