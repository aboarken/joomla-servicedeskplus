<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Tests\Support;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\ClientConfig;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpClient;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Transport\TransportResponse;

/** An SdpClient talking to a FakeTransport. */
abstract class SdpTestCase extends TestCase
{
    protected const KEY = 'ABCDEF01-2345-6789-ABCD-EF0123456789';

    protected FakeTransport $transport;

    /**
     * @param  \Closure(string, string, ?string): TransportResponse|null  $handler
     */
    protected function client(?\Closure $handler = null): SdpClient
    {
        $this->transport = new FakeTransport($handler);

        return new SdpClient($this->transport, new ClientConfig('https://sdp.example.com:8080'), self::KEY, new NullLogger());
    }

    /**
     * /api/v3/users answering with these users (id => e-mail), and /api/v3/users/{id} with one.
     *
     * @param  array<string, string>  $users
     */
    protected static function usersHandler(array $users): \Closure
    {
        return static function (string $method, string $url) use ($users): TransportResponse {
            $path = (string) parse_url($url, PHP_URL_PATH);

            if (preg_match('#/api/v3/users/([0-9]+)$#', $path, $m)) {
                return isset($users[$m[1]])
                    ? FakeTransport::json(['user' => ['id' => $m[1], 'email_id' => $users[$m[1]]]])
                    : FakeTransport::json(['response_status' => ['status' => 'failed', 'status_code' => 4000, 'messages' => [['status_code' => 4007, 'type' => 'failed', 'message' => 'Invalid URL']]]], 404);
            }

            if ($path === '/api/v3/users') {
                $rows = [];

                foreach ($users as $id => $email) {
                    $rows[] = ['id' => (string) $id, 'name' => 'User ' . $id, 'email_id' => $email];
                }

                return FakeTransport::json(['users' => $rows, 'list_info' => ['has_more_rows' => false]]);
            }

            return FakeTransport::json(['response_status' => ['status' => 'failed']], 404);
        };
    }
}
