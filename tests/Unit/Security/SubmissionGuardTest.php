<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\RateLimiter;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\SubmissionGuard;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\RecordingAudit;

final class SubmissionGuardTest extends TestCase
{
    private array $session = [];
    private int $now       = 1_000_000;

    private function guard(): SubmissionGuard
    {
        return new SubmissionGuard(fn (): array => $this->session, function (array $data): void {
            $this->session = $data;
        }, fn (): int => $this->now);
    }

    public function testATokenCanBeUsedOnlyOnce(): void
    {
        $guard = $this->guard();
        $token = $guard->issue();

        self::assertSame(SubmissionGuard::OK, $guard->consume($token));
        self::assertSame(SubmissionGuard::USED, $guard->consume($token));
    }

    public function testUnknownMalformedAndExpiredTokensAreRefused(): void
    {
        $guard = $this->guard();

        self::assertSame(SubmissionGuard::UNKNOWN, $guard->consume(str_repeat('a', 32)));
        self::assertSame(SubmissionGuard::UNKNOWN, $guard->consume('../../etc'));

        $token     = $guard->issue();
        $this->now += 7201;
        self::assertSame(SubmissionGuard::UNKNOWN, $guard->consume($token));
    }

    public function testTheResultOfASubmissionIsRemembered(): void
    {
        $guard = $this->guard();
        $token = $guard->issue();
        $guard->consume($token);
        $guard->recordResult($token, 'created:123');

        self::assertSame('created:123', $guard->resultFor($token));
    }

    public function testRateLimiterCountsOnlyThisUsersActionsInTheLastHour(): void
    {
        $audit   = new RecordingAudit();
        $limiter = new RateLimiter($audit);

        for ($i = 0; $i < 3; $i++) {
            $audit->record(7, 'approval.decide', 'ok');
        }

        $audit->record(8, 'approval.decide', 'ok');

        self::assertFalse($limiter->allow(7, 'approval.decide', 3));
        self::assertTrue($limiter->allow(8, 'approval.decide', 3));
        self::assertTrue($limiter->allow(7, 'request.create', 3));
    }
}
