<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Audit;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Psr\Log\LoggerInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Redactor;

final class DbAuditLog implements AuditLogInterface, AuditCounterInterface, AuditHistoryInterface
{
    /** Audit rows are kept this long (approval decisions always). */
    public const KEEP_DAYS = 365;

    /** Actions never pruned. */
    private const KEEP_ACTIONS = ['approval.approve', 'approval.reject'];

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly LoggerInterface $logger
    ) {
    }

    public function recent(int $userId, array $actions, int $limit): array
    {
        if ($actions === []) {
            return [];
        }

        $query = $this->db->createQuery()
            ->select($this->db->quoteName(['created', 'action', 'result', 'sdp_request_id', 'approval_id', 'module', 'subject', 'detail']))
            ->from($this->db->quoteName('#__sdp_audit'))
            ->where($this->db->quoteName('user_id') . ' = :uid')
            ->whereIn($this->db->quoteName('action'), $actions, ParameterType::STRING)
            ->bind(':uid', $userId, ParameterType::INTEGER)
            ->order($this->db->quoteName('id') . ' DESC');

        return $this->db->setQuery($query, 0, max(1, min(200, $limit)))->loadAssocList() ?: [];
    }

    public function countSince(int $userId, string $action, \DateTimeImmutable $since, array $results): int
    {
        if ($results === []) {
            return 0;
        }

        $sinceUtc = $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $query = $this->db->createQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__sdp_audit'))
            ->where($this->db->quoteName('user_id') . ' = :uid')
            ->where($this->db->quoteName('action') . ' = :action')
            ->where($this->db->quoteName('created') . ' >= :since')
            ->whereIn($this->db->quoteName('result'), $results, ParameterType::STRING)
            ->bind(':uid', $userId, ParameterType::INTEGER)
            ->bind(':action', $action)
            ->bind(':since', $sinceUtc);

        return (int) $this->db->setQuery($query)->loadResult();
    }

    public function record(
        int $userId,
        string $action,
        string $result,
        ?string $sdpRequestId = null,
        ?string $approvalId = null,
        string $detail = '',
        int $actorId = 0,
        string $module = '',
        string $subject = ''
    ): void {
        $row = (object) [
            'user_id'        => max(0, $userId),
            'actor_id'       => max(0, $actorId),
            'action'         => substr($action, 0, 50),
            'sdp_request_id' => $sdpRequestId,
            'approval_id'    => $approvalId,
            'module'         => substr(preg_replace('/[^a-z_]/', '', $module) ?? '', 0, 20),
            'subject'        => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject) ?? ''), 0, 255),
            'result'         => substr($result, 0, 20),
            'detail'         => Redactor::scrub($detail, [], 250),
            'created'        => gmdate('Y-m-d H:i:s'),
        ];

        try {
            $this->db->insertObject('#__sdp_audit', $row);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Audit write failed for action %s, user %d', $row->action, $row->user_id));

            return;
        }

        // Now and then, remove rows older than KEEP_DAYS so the table does not grow without end.
        // Approval decisions are kept: My Approvals shows them as history and the Dashboard
        // counts them. The rate limits only look at the last hour.
        if (random_int(1, 200) === 1) {
            $this->prune();
        }
    }

    /**
     * Delete rows older than KEEP_DAYS, except approval decisions.
     */
    public function prune(): void
    {
        $before = gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400);

        try {
            $query = $this->db->createQuery()
                ->delete($this->db->quoteName('#__sdp_audit'))
                ->where($this->db->quoteName('created') . ' < :before')
                ->whereNotIn($this->db->quoteName('action'), self::KEEP_ACTIONS, ParameterType::STRING)
                ->bind(':before', $before);
            $this->db->setQuery($query)->execute();
        } catch (\Throwable) {
            $this->logger->warning('Old audit rows could not be removed');
        }
    }
}
