<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Tests\Unit\Identity;

use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\MappingException;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\MappingManager;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\UserMapping;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\ArrayCache;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\InMemoryUserMaps;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\RecordingAudit;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\SdpTestCase;

final class MappingManagerTest extends SdpTestCase
{
    private InMemoryUserMaps $maps;
    private RecordingAudit $audit;

    private function manager(string $apiAccountEmail = ''): MappingManager
    {
        $client      = $this->client(self::usersHandler(['501' => 'a@example.com', '900' => 'api@example.com']));
        $this->maps  = new InMemoryUserMaps();
        $this->audit = new RecordingAudit();

        return new MappingManager($this->maps, static fn () => $client, $this->audit, null, new ArrayCache(), $apiAccountEmail);
    }

    public function testAssignLinksAnExistingSdpUser(): void
    {
        $manager = $this->manager();
        $manager->assign(7, 'a@example.com', '501', 1);

        self::assertSame('501', $this->maps->find(7)?->sdpUserId);
        self::assertSame(UserMapping::SOURCE_ADMIN, $this->maps->find(7)?->source);
    }

    public function testAssignRefusesTheApiTechnician(): void
    {
        $manager = $this->manager('api@example.com');

        try {
            $manager->assign(7, 'someone@example.com', '900', 1);
            self::fail('The API technician was linked');
        } catch (MappingException $e) {
            self::assertSame('COM_SERVICEDESKPLUS_MAPPING_ERR_API_ACCOUNT', $e->getMessage());
        }

        self::assertNull($this->maps->find(7));
        self::assertContains('mapping.assign denied api_account sdp_user:900', $this->audit->lines());
    }

    public function testAssignRefusesAnSdpUserLinkedToAnotherJoomlaUser(): void
    {
        $manager = $this->manager();
        $manager->assign(7, 'a@example.com', '501', 1);

        $this->expectException(MappingException::class);
        $manager->assign(8, 'b@example.com', '501', 1);
    }

    public function testAssignRefusesIdsThatAreNotNumbers(): void
    {
        $this->expectException(MappingException::class);
        $this->manager()->assign(7, 'a@example.com', '501/../users', 1);
    }

    public function testConfirmNeedsTheEmailsToMatch(): void
    {
        $manager = $this->manager();
        $now     = new \DateTimeImmutable();
        $this->maps->save(new UserMapping(7, '501', 'a@example.com', UserMapping::STATUS_PENDING, UserMapping::SOURCE_AUTO, null, $now));

        $this->expectException(MappingException::class);
        $manager->confirm(7, 'someone-else@example.com', 1);
    }
}
