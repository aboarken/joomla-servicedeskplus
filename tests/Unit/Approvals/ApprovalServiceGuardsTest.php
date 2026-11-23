<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Tests\Unit\Approvals;

use Psr\Log\NullLogger;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\ApprovalModule;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\ApprovalNotFound;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\ApprovalService;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\PendingApproval;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\Identity;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\RateLimiter;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\RateLimitExceeded;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\ArrayCache;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\RecordingAudit;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\SdpTestCase;

final class ApprovalServiceGuardsTest extends SdpTestCase
{
    private ArrayCache $cache;
    private RecordingAudit $audit;

    /** @param list<string> $modules */
    private function service(array $modules = ApprovalModule::ALL, string $accountEmail = ''): ApprovalService
    {
        $client      = $this->client(self::usersHandler(['900' => 'api@example.com', '501' => 'mona@example.com']));
        $this->cache ??= new ArrayCache();
        $this->audit = new RecordingAudit();

        return new ApprovalService(
            static fn () => $client,
            $this->cache,
            $this->audit,
            $this->audit,
            new RateLimiter($this->audit),
            new NullLogger(),
            30,
            approvalAccountEmail: $accountEmail,
            modules: $modules,
            sleep: static function (): void {
            }
        );
    }

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
    }

    public function testASwitchedOffModuleCannotBeOpenedByAddress(): void
    {
        $service = $this->service([ApprovalModule::REQUEST]);

        $this->expectException(ApprovalNotFound::class);

        try {
            $service->getForUser(Identity::matched(7, '501'), '100', '20', '3', ApprovalModule::PURCHASE_ORDER);
        } finally {
            self::assertSame([], $this->transport->sent);
        }
    }

    public function testCachedListsDropASwitchedOffModule(): void
    {
        $identity = Identity::matched(7, '501');
        $this->cache->set($identity, 'approvals:pending', [
            (new PendingApproval('100', '20', '3', 'Laptop', 'Ali', null, 'L1', ApprovalModule::REQUEST))->toArray(),
            (new PendingApproval('200', '30', '4', 'PO 34501', 'Ali', null, 'L1', ApprovalModule::PURCHASE_ORDER))->toArray(),
        ]);

        $list = $this->service([ApprovalModule::REQUEST])->listPending($identity);

        self::assertSame(['100'], array_map(static fn (PendingApproval $p): string => $p->requestId, $list));
    }

    public function testAUserLinkedToTheApiTechnicianSeesAndOpensNothing(): void
    {
        $service  = $this->service(accountEmail: 'api@example.com');
        $identity = Identity::matched(7, '900');

        self::assertSame([], $service->listPending($identity));

        try {
            $service->getForUser($identity, '100', '20', '3');
            self::fail('The API technician\'s approval was opened');
        } catch (ApprovalNotFound) {
            self::assertContains('approval.view denied api_account', $this->audit->lines());
        }
    }

    public function testIdsWithLeadingZerosAreRefused(): void
    {
        $this->expectException(ApprovalNotFound::class);
        $this->service()->getForUser(Identity::matched(7, '501'), '0100', '20', '3');
    }

    public function testAttachmentDownloadsAreRateLimited(): void
    {
        $service = $this->service();

        for ($i = 0; $i < ApprovalService::ATTACHMENTS_PER_HOUR; $i++) {
            $this->audit->record(7, 'approval.attachment', 'ok');
        }

        $this->expectException(RateLimitExceeded::class);
        $service->attachmentForUser(Identity::matched(7, '501'), '100', '20', '3', ApprovalModule::REQUEST, '5');
    }
}
