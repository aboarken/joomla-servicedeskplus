<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Tests\Support;

use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Transport\TransportException;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Transport\TransportInterface;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Transport\TransportResponse;

/**
 * Stands in for ServiceDesk Plus: answers each call from a handler and records what was sent.
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $sent = [];

    /** @var \Closure(string, string, ?string): (TransportResponse|TransportException) */
    private \Closure $handler;

    /**
     * @param  \Closure(string $method, string $url, ?string $body): (TransportResponse|TransportException)|null  $handler
     */
    public function __construct(?\Closure $handler = null)
    {
        $this->handler = $handler ?? static fn (): TransportResponse => self::json(['response_status' => ['status' => 'success']]);
    }

    public function send(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $result       = ($this->handler)($method, $url, $body);

        if ($result instanceof TransportException) {
            throw $result;
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): TransportResponse
    {
        return new TransportResponse($status, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** The decoded input_data of a recorded call (GET: query string, other methods: body). */
    public static function inputData(array $call): ?array
    {
        $query = parse_url($call['url'], PHP_URL_QUERY);
        parse_str($call['method'] === 'GET' ? (string) $query : (string) $call['body'], $params);

        return isset($params['input_data']) ? json_decode((string) $params['input_data'], true, 64, JSON_THROW_ON_ERROR) : null;
    }
}
