<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Tests\Unit\Identity;

use Psr\Log\NullLogger;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\IdentityResolver;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\UserMapping;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\ArrayCache;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\InMemoryUserMaps;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\RecordingAudit;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\SdpTestCase;

final class IdentityResolverTest extends SdpTestCase
{
    private InMemoryUserMaps $maps;
    private RecordingAudit $audit;
    private ArrayCache $cache;

    /** @param array<string, string> $users */
    private function resolver(array $users, bool $autoApprove = true, string $apiAccountEmail = ''): IdentityResolver
    {
        $client      = $this->client(self::usersHandler($users));
        $this->maps  ??= new InMemoryUserMaps();
        $this->audit = new RecordingAudit();
        $this->cache = new ArrayCache();

        return new IdentityResolver($this->maps, static fn () => $client, $this->audit, new NullLogger(), null, $this->cache, $autoApprove, $apiAccountEmail);
    }

    protected function setUp(): void
    {
        $this->maps = new InMemoryUserMaps();
    }

    public function testMatchesExactlyOneUserByEmailCaseInsensitively(): void
    {
        $identity = $this->resolver(['501' => 'Ali@Example.com'])->resolve(7, ' ali@example.COM ');

        self::assertTrue($identity->isMatched());
        self::assertSame('501', $identity->sdpUserId());
        self::assertSame(UserMapping::STATUS_ACTIVE, $this->maps->find(7)?->status);
    }

    public function testPartialMatchesFromServiceDeskPlusAreIgnored(): void
    {
        // SDP's search may return users whose address only contains the searched text.
        $identity = $this->resolver(['501' => 'ali@example.com.evil', '502' => 'xali@example.com'])->resolve(7, 'ali@example.com');

        self::assertFalse($identity->isMatched());
        self::assertSame(UserMapping::STATUS_UNMATCHED, $identity->status);
    }

    public function testSeveralUsersWithTheSameEmailAreAmbiguous(): void
    {
        $identity = $this->resolver(['501' => 'a@example.com', '502' => 'a@example.com'])->resolve(7, 'a@example.com');

        self::assertSame(UserMapping::STATUS_AMBIGUOUS, $identity->status);
    }

    public function testWaitForAdministratorKeepsTheMatchPending(): void
    {
        $identity = $this->resolver(['501' => 'a@example.com'], autoApprove: false)->resolve(7, 'a@example.com');

        self::assertFalse($identity->isMatched());
        self::assertSame(UserMapping::STATUS_PENDING, $this->maps->find(7)?->status);
    }

    public function testAnSdpUserLinkedToSomeoneElseIsNotTakenOver(): void
    {
        $this->resolver(['501' => 'a@example.com'])->resolve(7, 'a@example.com');
        $identity = $this->resolver(['501' => 'a@example.com'])->resolve(8, 'a@example.com');

        self::assertFalse($identity->isMatched());
        self::assertSame('7', (string) $this->maps->findBySdpUserId('501')?->joomlaUserId);
    }

    public function testAChangedJoomlaEmailSuspendsAnActiveMapping(): void
    {
        $this->resolver(['501' => 'a@example.com', '502' => 'b@example.com'])->resolve(7, 'a@example.com');
        $identity = $this->resolver(['501' => 'a@example.com', '502' => 'b@example.com'])->resolve(7, 'b@example.com');

        self::assertFalse($identity->isMatched());
        self::assertSame(UserMapping::STATUS_SUSPENDED, $this->maps->find(7)?->status);
        self::assertContains(7, $this->cache->clearedUsers);
    }

    public function testTheApiTechnicianIsNeverMatchedByEmail(): void
    {
        $identity = $this->resolver(['900' => 'api@example.com'], apiAccountEmail: 'API@example.com')->resolve(7, 'api@example.com');

        self::assertFalse($identity->isMatched());
        self::assertSame(0, $this->transportCalls());
        self::assertContains('identity.unmatched denied api_account', $this->audit->lines());
    }

    public function testAnExistingLinkToTheApiTechnicianIsSuspended(): void
    {
        $now = new \DateTimeImmutable();
        $this->maps->save(new UserMapping(7, '900', 'api@example.com', UserMapping::STATUS_ACTIVE, UserMapping::SOURCE_ADMIN, $now, $now));

        $identity = $this->resolver(['900' => 'api@example.com'], apiAccountEmail: 'api@example.com')->resolve(7, 'api@example.com');

        self::assertFalse($identity->isMatched());
        self::assertSame(UserMapping::STATUS_SUSPENDED, $this->maps->find(7)?->status);
    }

    public function testGuestsAreNeverMatched(): void
    {
        self::assertFalse($this->resolver(['501' => 'a@example.com'])->resolve(0, 'a@example.com')->isMatched());
        self::assertSame(0, $this->transportCalls());
    }

    private function transportCalls(): int
    {
        return \count($this->transport->sent);
    }
}
