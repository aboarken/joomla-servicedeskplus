<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Tests\Support;

use SdpPortal\Component\ServiceDeskPlus\Administrator\Audit\AuditCounterInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Audit\AuditHistoryInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Audit\AuditLogInterface;

/** The audit log in memory: records rows, counts them for the rate limiter. */
final class RecordingAudit implements AuditLogInterface, AuditCounterInterface, AuditHistoryInterface
{
    /** @var list<array{user: int, action: string, result: string, request: ?string, approval: ?string, detail: string, actor: int, at: \DateTimeImmutable}> */
    public array $rows = [];

    public function record(int $userId, string $action, string $result, ?string $sdpRequestId = null, ?string $approvalId = null, string $detail = '', int $actorId = 0, string $module = '', string $subject = ''): void
    {
        $this->rows[] = ['user' => $userId, 'action' => $action, 'result' => $result, 'request' => $sdpRequestId, 'approval' => $approvalId, 'detail' => $detail, 'actor' => $actorId, 'at' => new \DateTimeImmutable()];
    }

    public function countSince(int $userId, string $action, \DateTimeImmutable $since, array $results): int
    {
        return \count(array_filter($this->rows, static fn (array $r): bool => $r['user'] === $userId && $r['action'] === $action && $r['at'] >= $since && \in_array($r['result'], $results, true)));
    }

    public function recent(int $userId, array $actions, int $limit): array
    {
        return [];
    }

    /** @return list<string> "action result detail" of every row */
    public function lines(): array
    {
        return array_map(static fn (array $r): string => trim($r['action'] . ' ' . $r['result'] . ' ' . $r['detail']), $this->rows);
    }
}
