<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Tests\Unit\Discovery;

use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpException;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Transport\TransportResponse;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Discovery\Diagnostics;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Discovery\EndpointCatalog;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\FakeTransport;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\SdpTestCase;

final class FindEndpointsTest extends SdpTestCase
{
    public function testTypedPathsAreNormalisedOrRefused(): void
    {
        self::assertSame('/api/v3/requests', EndpointCatalog::normalize('requests'));
        self::assertSame('/api/v3/requests/12/notes', EndpointCatalog::normalize(' /API/v3/requests/12/notes/ '));
        self::assertNull(EndpointCatalog::normalize('/api/v1/requests'));
        self::assertNull(EndpointCatalog::normalize('../../etc/passwd'));
        self::assertNull(EndpointCatalog::normalize('https://evil.example.com/api/v3/requests'));
    }

    public function testEveryPortalEndpointIsInTheCatalog(): void
    {
        $paths = EndpointCatalog::portalPaths();

        foreach (['/api/v3/requests', '/api/v3/approvals', '/api/v3/assets', '/api/v3/asset_loans', '/api/v3/announcements', '/api/v3/users', '/api/v3/categories'] as $path) {
            self::assertContains($path, $paths);
        }

        foreach (EndpointCatalog::all() as $ep) {
            self::assertMatchesRegularExpression(EndpointCatalog::PATH, $ep['path']);
        }
    }

    public function testRefusedAndMissingEndpointsAreTold(): void
    {
        self::assertSame(Diagnostics::DENIED, Diagnostics::status(new SdpException(SdpException::PERMISSION, 'x')));
        self::assertSame(Diagnostics::MISSING, Diagnostics::status(new SdpException(SdpException::NOT_FOUND, 'x')));
        self::assertSame(Diagnostics::MISSING, Diagnostics::status(new SdpException(SdpException::BAD_RESPONSE, 'x')));
        self::assertSame(Diagnostics::ERROR, Diagnostics::status(new SdpException(SdpException::SERVER, 'x')));
    }

    public function testARunReadsOnlyAndTheReportHoldsNoPersonalValues(): void
    {
        $client = $this->client(static function (string $method, string $url): TransportResponse {
            $path = (string) parse_url($url, PHP_URL_PATH);

            if ($path === '/api/v3/purchase_orders') {
                return FakeTransport::json(['response_status' => ['status' => 'failed', 'messages' => [['status_code' => 4002, 'message' => 'Not allowed']]]], 403);
            }

            if ($path === '/api/v3/users') {
                return FakeTransport::json(['users' => [['id' => '501', 'name' => 'Secret Person', 'email_id' => 'secret.person@example.com']], 'list_info' => ['total_count' => 1]]);
            }

            return FakeTransport::json(['requests' => [['id' => '77', 'subject' => 'Salary of Secret Person', 'status' => ['name' => 'Open']]], 'list_info' => ['total_count' => 1]]);
        });

        $result = Diagnostics::collect($client, 'secret.person@example.com');
        $report = Diagnostics::report($result, 'secret.person@example.com', ['Component version' => 'test']);

        self::assertTrue($result['connection']['ok']);
        self::assertSame(Diagnostics::DENIED, array_column($result['portal'], 'status', 'path')['/api/v3/purchase_orders']);
        self::assertSame(['GET'], array_values(array_unique(array_column($this->transport->sent, 'method'))));

        foreach (['Secret Person', 'secret.person@example.com', 'Salary', '501'] as $private) {
            self::assertStringNotContainsString($private, $report);
        }

        self::assertStringContainsString('status.name (text): Open', $report);
    }

    public function testAFailedConnectionStopsTheRun(): void
    {
        $client = $this->client(static fn (): TransportResponse => FakeTransport::json(['response_status' => ['status' => 'failed', 'messages' => [['status_code' => 4022, 'message' => 'Invalid key']]]], 401));

        $result = Diagnostics::collect($client, null);

        self::assertFalse($result['connection']['ok']);
        self::assertSame(SdpException::AUTH, $result['connection']['kind']);
        self::assertCount(1, $this->transport->sent);
    }
}
