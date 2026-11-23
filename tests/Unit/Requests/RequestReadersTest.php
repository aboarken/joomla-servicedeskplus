<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Tests\Unit\Requests;

use Psr\Log\NullLogger;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Transport\TransportResponse;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\Identity;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\ConversationReader;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\RequestApprovalsReader;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\ArrayCache;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\FakeTransport;
use SdpPortal\Component\ServiceDeskPlus\Tests\Support\SdpTestCase;

final class RequestReadersTest extends SdpTestCase
{
    /** @return list<string> paths of the recorded calls */
    private function paths(): array
    {
        return array_map(static fn (array $c): string => (string) parse_url($c['url'], PHP_URL_PATH), $this->transport->sent);
    }

    public function testTheConversationShowsOnlyWhatIsMeantForTheRequester(): void
    {
        $base   = 'https://sdp.example.com:8080/api/v3/requests/100/';
        $client = $this->client(static function (string $method, string $url) use ($base): TransportResponse {
            return match ((string) parse_url($url, PHP_URL_PATH)) {
                '/api/v3/requests/100/conversations' => FakeTransport::json(['conversations' => [
                    ['type' => 'notes', 'show_to_requester' => true, 'content_url' => $base . 'notes/1'],
                    ['type' => 'notes', 'show_to_requester' => false, 'content_url' => $base . 'notes/2'],
                    ['type' => 'notification', 'show_to_requester' => true, 'content_url' => $base . 'notifications/3'],
                    // A content_url of another request is never followed.
                    ['type' => 'notes', 'show_to_requester' => true, 'content_url' => 'https://sdp.example.com:8080/api/v3/requests/999/notes/4'],
                    // The full note says it is internal after all.
                    ['type' => 'notes', 'show_to_requester' => true, 'content_url' => $base . 'notes/5'],
                ]]),
                '/api/v3/requests/100/notes/1'         => FakeTransport::json(['note' => ['description' => '<p>Visible note</p>', 'added_time' => ['value' => '1700000200000']]]),
                '/api/v3/requests/100/notifications/3' => FakeTransport::json(['notification' => ['subject' => 'Re: printer', 'description' => 'Mail text', 'sent_time' => ['value' => '1700000100000']]]),
                '/api/v3/requests/100/notes/5'         => FakeTransport::json(['note' => ['description' => 'Internal', 'show_to_requester' => false]]),
                default                                => FakeTransport::json(['response_status' => ['status' => 'failed']], 404),
            };
        });

        $entries = (new ConversationReader(static fn () => $client, new ArrayCache(), new NullLogger()))->forRequest(Identity::matched(7, '501'), '100');

        self::assertSame(['Mail text', 'Visible note'], array_map(static fn ($e): string => $e->text, $entries));
        self::assertNotContains('/api/v3/requests/100/notes/2', $this->paths());
        self::assertNotContains('/api/v3/requests/999/notes/4', $this->paths());
    }

    public function testApprovalsOfEveryLevelAreReadAndOrdered(): void
    {
        $client = $this->client(static function (string $method, string $url): TransportResponse {
            return match ((string) parse_url($url, PHP_URL_PATH)) {
                '/api/v3/requests/100/approval_levels' => FakeTransport::json(['approval_levels' => [
                    ['id' => '22', 'level' => 2, 'name' => 'Finance'],
                    ['id' => '21', 'level' => 1, 'name' => 'Manager'],
                    ['id' => '23', 'level' => 3, 'deleted' => true],
                ]]),
                '/api/v3/requests/100/approval_levels/21/approvals' => FakeTransport::json(['approvals' => [['approver' => ['name' => 'Mona'], 'status' => ['name' => 'Approved'], 'comments' => '&lt;ok&gt;']]]),
                '/api/v3/requests/100/approval_levels/22/approvals' => FakeTransport::json(['approvals' => [['approver' => ['name' => 'Sami'], 'status' => ['name' => 'Pending Approval']]]]),
                default => FakeTransport::json(['response_status' => ['status' => 'failed']], 404),
            };
        });

        $rows = (new RequestApprovalsReader(static fn () => $client, new ArrayCache(), new NullLogger()))->forRequest(Identity::matched(7, '501'), '100');

        self::assertSame(['Manager:Mona', 'Finance:Sami'], array_map(static fn ($r): string => $r->levelName . ':' . $r->approver, $rows));
        self::assertSame('<ok>', $rows[0]->comments);
        self::assertNotContains('/api/v3/requests/100/approval_levels/23/approvals', $this->paths());
    }
}
