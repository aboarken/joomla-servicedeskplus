<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Tests\Unit\Api;

use Psr\Log\AbstractLogger;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\ClientConfig;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\ListInfo;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpClient;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpException;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Transport\TransportException;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Transport\TransportResponse;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\FakeTransport;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\SdpTestCase;

final class SdpClientTest extends SdpTestCase
{
    public function testSendsTheKeyAndTheV3AcceptHeader(): void
    {
        $this->client()->get('/api/v3/requests');

        $call = $this->transport->sent[0];
        self::assertSame('GET', $call['method']);
        self::assertSame('https://sdp.example.com:8080/api/v3/requests', $call['url']);
        self::assertSame(self::KEY, $call['headers']['authtoken']);
        self::assertSame('application/vnd.manageengine.sdp.v3+json', $call['headers']['Accept']);
    }

    public function testGetSendsInputDataInTheQueryAndPostInAFormBody(): void
    {
        $client = $this->client();
        $client->get('/api/v3/requests', ['list_info' => ['row_count' => 5]]);
        $client->post('/api/v3/requests', ['request' => ['subject' => 'Printer & toner']]);

        self::assertSame(['list_info' => ['row_count' => 5]], FakeTransport::inputData($this->transport->sent[0]));
        self::assertSame(self::KEY, $this->transport->sent[1]['headers']['authtoken']);
        self::assertSame('application/x-www-form-urlencoded', $this->transport->sent[1]['headers']['Content-Type']);
        self::assertSame(['request' => ['subject' => 'Printer & toner']], FakeTransport::inputData($this->transport->sent[1]));
    }

    /** @return iterable<string, array{string}> */
    public static function badPaths(): iterable
    {
        yield 'other host'       => ['https://evil.example.com/api/v3/requests'];
        yield 'traversal'        => ['/api/v3/../admin'];
        yield 'query string'     => ['/api/v3/requests?x=1'];
        yield 'not v3'           => ['/api/v1/requests'];
        yield 'upper case'       => ['/api/v3/Requests'];
        yield 'encoded slash'    => ['/api/v3/requests%2F1'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPaths')]
    public function testRefusesPathsOutsideTheApi(string $path): void
    {
        $client = $this->client();

        try {
            $client->get($path);
            self::fail('Path accepted: ' . $path);
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->transport->sent);
        }
    }

    public function testIdsMustBeDigits(): void
    {
        self::assertSame('123', SdpClient::id(123));
        $this->expectException(\InvalidArgumentException::class);
        SdpClient::id('12/../1');
    }

    /** @return iterable<string, array{int, ?int, string}> */
    public static function errors(): iterable
    {
        yield 'key rejected'      => [401, 4022, SdpException::AUTH];
        yield 'no permission'     => [403, 4002, SdpException::PERMISSION];
        yield 'not the approver'  => [400, 4201, SdpException::PERMISSION];
        yield 'not found'         => [404, 4007, SdpException::NOT_FOUND];
        yield 'rate limited'      => [429, null, SdpException::RATE_LIMITED];
        yield 'server error'      => [500, null, SdpException::SERVER];
        yield 'invalid input'     => [400, 4012, SdpException::VALIDATION];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testMapsServiceDeskPlusErrors(int $http, ?int $code, string $kind): void
    {
        $body   = ['response_status' => ['status' => 'failed', 'messages' => [['type' => 'failed', 'status_code' => $code, 'message' => 'Something failed']]]];
        $client = $this->client(static fn (): TransportResponse => FakeTransport::json($body, $http));

        try {
            $client->get('/api/v3/requests');
            self::fail('No exception');
        } catch (SdpException $e) {
            self::assertSame($kind, $e->getKind());
        }
    }

    public function testAFailedStatusWithHttp200IsAnError(): void
    {
        $client = $this->client(static fn (): TransportResponse => FakeTransport::json(['response_status' => [['status' => 'failed', 'status_code' => 4000, 'messages' => [['status_code' => 4002, 'message' => 'Not allowed']]]]]));

        $this->expectException(SdpException::class);
        $client->get('/api/v3/requests');
    }

    public function testAWebPageInsteadOfJsonIsABadResponse(): void
    {
        $client = $this->client(static fn (): TransportResponse => new TransportResponse(200, '<html><body>Login</body></html>'));

        try {
            $client->get('/api/v3/requests');
            self::fail('No exception');
        } catch (SdpException $e) {
            self::assertSame(SdpException::BAD_RESPONSE, $e->getKind());
        }
    }

    public function testTheKeyNeverReachesTheLogOrTheErrorMessage(): void
    {
        $logger = new class () extends AbstractLogger {
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };
        $transport = new FakeTransport(static fn (): TransportResponse => FakeTransport::json(['response_status' => ['status' => 'failed', 'messages' => [['status_code' => 4000, 'message' => 'Bad authtoken ' . self::KEY]]]], 400));
        $client    = new SdpClient($transport, new ClientConfig('https://sdp.example.com'), self::KEY, $logger);

        try {
            $client->get('/api/v3/requests');
            self::fail('No exception');
        } catch (SdpException $e) {
            self::assertStringNotContainsString(self::KEY, $e->getSdpMessage());
        }

        self::assertNotEmpty($logger->lines);
        self::assertStringNotContainsString(self::KEY, implode("\n", $logger->lines));
        self::assertStringNotContainsString(self::KEY, print_r($client, true));
    }

    public function testAReadThatCannotConnectIsTriedOnceMore(): void
    {
        $calls  = 0;
        $client = $this->client(static function () use (&$calls) {
            return ++$calls === 1 ? new TransportException('Connection reset') : FakeTransport::json(['requests' => []]);
        });

        $client->get('/api/v3/requests');
        self::assertCount(2, $this->transport->sent);
    }

    public function testAWriteIsNeverRepeated(): void
    {
        $client = $this->client(static fn () => new TransportException('Connection reset'));

        try {
            $client->post('/api/v3/requests', ['request' => ['subject' => 'x']]);
            self::fail('No exception');
        } catch (SdpException $e) {
            self::assertSame(SdpException::NETWORK, $e->getKind());
        }

        self::assertCount(1, $this->transport->sent);
    }

    public function testListAllFollowsPagesUntilNoMoreRows(): void
    {
        $client = $this->client(static function (string $method, string $url) {
            $input = FakeTransport::inputData(['method' => 'GET', 'url' => $url, 'body' => null]);
            $start = $input['list_info']['start_index'];
            $rows  = array_map(static fn (int $i): array => ['id' => (string) ($start + $i)], range(0, 99));

            return FakeTransport::json(['requests' => $start > 150 ? \array_slice($rows, 0, 10) : $rows, 'list_info' => ['has_more_rows' => $start <= 150]]);
        });

        $all = $client->listAll('/api/v3/requests', 'requests', new ListInfo(), 1000);

        self::assertCount(210, $all);
        self::assertSame([1, 101, 201], array_map(static fn (array $c): int => FakeTransport::inputData($c)['list_info']['start_index'], $this->transport->sent));
    }

    public function testApprovalCommentsAreSentAsText(): void
    {
        self::assertSame('Approved by &lt;b&gt;A&amp;B&lt;/b&gt;', SdpClient::encodeComment('Approved by <b>A&B</b>', 250));
        // Cut between characters, never inside an entity.
        self::assertSame(str_repeat('a', 248), SdpClient::encodeComment(str_repeat('a', 248) . '&x', 250));
        self::assertSame('عربي &lt;x&gt;', SdpClient::encodeComment('عربي <x>', 250));
    }

    public function testDecisionsGoToTheApprovalOfTheRecord(): void
    {
        $client = $this->client(static fn () => FakeTransport::json(['approval' => ['id' => '3']]));
        $client->decideApproval('100', '20', '3', 'approve', 'OK <ok>');

        $call = $this->transport->sent[0];
        self::assertSame('PUT', $call['method']);
        self::assertStringStartsWith('https://sdp.example.com:8080/api/v3/requests/100/approval_levels/20/approvals/3/', $call['url']);
        self::assertSame('OK &lt;ok&gt;', FakeTransport::inputData($call)['approval']['comments']);
    }

    public function testDecisionsRefuseOtherActions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->decideApproval('100', '20', '3', 'delete', '');
    }
}
