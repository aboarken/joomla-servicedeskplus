<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Transport;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\Http\Http;
use Joomla\Http\HttpFactory;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\ClientConfig;

/**
 * HTTP transport over cURL.
 *
 * Reads (GET) are sent with cURL directly, one at a time or several at once, and share DNS
 * results, open connections and TLS sessions for the rest of the page load: only the first call
 * pays for the lookup, the connection and the handshake. Writes (POST, PUT, DELETE) go through
 * the Joomla Framework HTTP client as before. Both use the same cURL options: certificate check
 * (same CA file as the Joomla client), pinned key, "connect via" and timeouts.
 */
final class JoomlaHttpTransport implements ParallelTransportInterface
{
    /**
     * cURL share handles by connection settings, for this PHP request. Several SdpClient
     * instances are built during one page load; they all reuse the same connections.
     *
     * @var array<string, \CurlShareHandle>
     */
    private static array $shares = [];

    /**
     * Largest API answer (JSON) read from ServiceDesk Plus. A bigger or endless answer, e.g. from
     * a wrong or hijacked server, is cut off instead of filling PHP's memory (several reads may
     * run at once, and decoding JSON needs several times its size).
     */
    public const MAX_RESPONSE_BYTES = 16 * 1024 * 1024;

    /**
     * Largest file download: just above the largest attachment the portal downloads (30 MB).
     * Only a read that accepts any content type (SdpClient's attachment download) may be this big.
     */
    public const MAX_DOWNLOAD_BYTES = 32 * 1024 * 1024;

    private ?Http $http = null;

    public function __construct(private readonly ClientConfig $config)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        if ($method === 'GET' && \function_exists('curl_init')) {
            // The handle is freed when it goes out of scope (curl_close() does nothing since PHP 8
            // and is deprecated in PHP 8.5).
            $handle  = $this->handle($url, $headers);
            $content = curl_exec($handle);

            if ($content === false || curl_errno($handle) !== 0) {
                throw $this->failure(curl_error($handle));
            }

            return $this->response($handle, (string) $content);
        }

        return $this->sendWithJoomla($method, $url, $headers, $body);
    }

    public function getAll(array $requests, int $concurrency): array
    {
        if (!\function_exists('curl_multi_init')) {
            return $this->sendEach($requests);
        }

        $multi   = curl_multi_init();
        $queue   = $requests;
        $running = [];
        $out     = [];
        $limit   = max(1, $concurrency);

        try {
            while ($queue !== [] || $running !== []) {
                // Keep up to $limit transfers going.
                while ($queue !== [] && \count($running) < $limit) {
                    $key             = array_key_first($queue);
                    [$url, $headers] = $queue[$key];
                    unset($queue[$key]);

                    $handle = $this->handle($url, $headers);
                    curl_multi_add_handle($multi, $handle);
                    $running[spl_object_id($handle)] = [$key, $handle];
                }

                do {
                    $status = curl_multi_exec($multi, $active);
                } while ($status === CURLM_CALL_MULTI_PERFORM);

                if ($status !== CURLM_OK) {
                    break;
                }

                while (($info = curl_multi_info_read($multi)) !== false) {
                    $handle = $info['handle'];
                    $id     = spl_object_id($handle);

                    if (!isset($running[$id])) {
                        continue;
                    }

                    [$key] = $running[$id];
                    unset($running[$id]);

                    $out[$key] = $info['result'] === CURLE_OK
                        ? $this->response($handle, (string) curl_multi_getcontent($handle))
                        : $this->failure(curl_error($handle) ?: curl_strerror($info['result']));

                    curl_multi_remove_handle($multi, $handle);
                }

                if ($running !== [] && curl_multi_select($multi, 1.0) === -1) {
                    usleep(1000);
                }
            }
        } finally {
            foreach ($running as [$key, $handle]) {
                curl_multi_remove_handle($multi, $handle);
                $out[$key] ??= $this->failure('transfer not finished');
            }

            curl_multi_close($multi);
        }

        $ordered = [];

        foreach (array_keys($requests) as $key) {
            $ordered[$key] = $out[$key] ?? $this->failure('transfer not started');
        }

        return $ordered;
    }

    /**
     * @param  array<int|string, array{0: string, 1: array<string, string>}>  $requests
     *
     * @return array<int|string, TransportResponse|TransportException>
     */
    private function sendEach(array $requests): array
    {
        $out = [];

        foreach ($requests as $key => [$url, $headers]) {
            try {
                $out[$key] = $this->send('GET', $url, $headers, null);
            } catch (TransportException $e) {
                $out[$key] = $e;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function sendWithJoomla(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        $http    = $this->http();
        $timeout = $this->config->timeout;

        try {
            $response = match ($method) {
                'GET'    => $http->get($url, $headers, $timeout),
                'POST'   => $http->post($url, $body ?? '', $headers, $timeout),
                'PUT'    => $http->put($url, $body ?? '', $headers, $timeout),
                'DELETE' => $http->delete($url, $headers, $timeout, $body),
                default  => throw new \InvalidArgumentException('Unsupported HTTP method'),
            };
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Only the cURL adapter is used, and cURL error texts never contain request headers.
            // The text is still shortened and cleaned, and SdpClient removes secrets before logging.
            throw new TransportException('Could not reach ServiceDesk Plus (' . self::describe($e->getMessage()) . ': ' . self::detail($e->getMessage()) . ')', 0, $e);
        }

        return new TransportResponse($response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * One cURL GET handle with the shared options and this page load's share handle.
     *
     * @param  array<string, string>  $headers
     */
    private function handle(string $url, array $headers): \CurlHandle
    {
        $handle = curl_init();

        if ($handle === false) {
            throw $this->failure('cURL could not be initialised');
        }

        $lines = ['Expect:'];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $options = $this->curlOptions() + [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_TIMEOUT        => $this->config->timeout,
            CURLOPT_NOSIGNAL       => true,
        ];

        // A file download may be larger than an API answer.
        if (str_contains($headers['Accept'] ?? '', '*/*')) {
            $options = self::sizeLimit(self::MAX_DOWNLOAD_BYTES) + $options;
        }

        $this->setCa($options);

        $share = $this->share();

        if ($share !== null) {
            $options[CURLOPT_SHARE] = $share;
        }

        curl_setopt_array($handle, $options);

        return $handle;
    }

    private function response(\CurlHandle $handle, string $body): TransportResponse
    {
        $ms = static fn (int $info): int => (int) round(max(0.0, (float) curl_getinfo($handle, $info)) * 1000);

        $dns     = $ms(CURLINFO_NAMELOOKUP_TIME);
        $connect = $ms(CURLINFO_CONNECT_TIME);
        $tls     = $ms(CURLINFO_APPCONNECT_TIME);
        $first   = $ms(CURLINFO_STARTTRANSFER_TIME);
        $total   = $ms(CURLINFO_TOTAL_TIME);

        // No new connection was made: an open one was reused.
        $reused = (int) curl_getinfo($handle, CURLINFO_NUM_CONNECTS) === 0;

        return new TransportResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body, [
            'dns'     => $dns,
            'connect' => max(0, $connect - $dns),
            'tls'     => $tls > 0 ? max(0, $tls - $connect) : 0,
            'server'  => max(0, $first - max($tls, $connect)),
            'total'   => $total,
            'reused'  => $reused,
        ]);
    }

    private function failure(string $curlError): TransportException
    {
        return new TransportException('Could not reach ServiceDesk Plus (' . self::describe($curlError) . ': ' . self::detail($curlError) . ')');
    }

    /**
     * Shares DNS results, TLS sessions and open connections between the handles of this page
     * load that use the same connection settings. Null where cURL cannot share.
     */
    private function share(): ?\CurlShareHandle
    {
        if (!\function_exists('curl_share_init')) {
            return null;
        }

        $id = hash('sha256', serialize([
            $this->config->baseUrl,
            $this->config->verifySsl,
            $this->config->caBundle,
            $this->config->pinnedPublicKey,
            $this->config->connectToEntry(),
        ]));

        if (!isset(self::$shares[$id])) {
            $share = curl_share_init();
            curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
            curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);

            if (\defined('CURL_LOCK_DATA_CONNECT')) {
                curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
            }

            self::$shares[$id] = $share;
        }

        return self::$shares[$id];
    }

    /**
     * The CA settings the Joomla HTTP client uses: the configured bundle, else Composer's system
     * bundle (a file or a directory).
     *
     * @param  array<int, mixed>  $options
     */
    private function setCa(array &$options): void
    {
        if ($this->config->caBundle !== '') {
            $options[CURLOPT_CAINFO] = $this->config->caBundle;

            return;
        }

        if (!class_exists(\Composer\CaBundle\CaBundle::class)) {
            return;
        }

        $path = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();

        if (is_dir($path) || (is_link($path) && is_dir((string) readlink($path)))) {
            $options[CURLOPT_CAPATH] = $path;
        } else {
            $options[CURLOPT_CAINFO] = $path;
        }
    }

    private function http(): Http
    {
        if ($this->http !== null) {
            return $this->http;
        }

        $options = [
            'follow_location' => false,
            'transport.curl'  => $this->curlOptions(),
        ];

        if ($this->config->caBundle !== '') {
            $options['curl.certpath'] = $this->config->caBundle;
        }

        $this->http = (new HttpFactory())->getHttp($options, ['curl']);

        return $this->http;
    }

    /**
     * cURL options shared by every request.
     *
     * @return array<int, mixed>
     */
    private function curlOptions(): array
    {
        $curl = [];

        if ($this->config->isPinned()) {
            // The pinned public key identifies the server instead of a CA and host name, so an
            // IP address or a self-signed certificate works. cURL aborts the connection before
            // any data (including the authtoken header) is sent if the server's key differs.
            // It comes first, so that cURL refuses it before the checks below are switched off,
            // and writes (Joomla's client ignores option errors) are never sent unchecked.
            if (!self::pinningSupported()) {
                throw new TransportException('Could not reach ServiceDesk Plus (TLS/certificate error: this server\'s cURL cannot check a pinned key; remove the pin or use a CA bundle)');
            }

            $curl[CURLOPT_PINNEDPUBLICKEY] = $this->config->pinnedPublicKey;
        }

        $curl += [
            CURLOPT_CONNECTTIMEOUT => $this->config->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => $this->config->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->config->verifySsl ? 2 : 0,
        ];

        $curl += self::sizeLimit(self::MAX_RESPONSE_BYTES);

        $connectTo = $this->config->connectToEntry();

        if ($connectTo !== null) {
            // Open the connection to the "connect via" address (e.g. the firewall's public name)
            // while SNI, certificate name check and Host header keep using the base URL's host.
            $curl[CURLOPT_CONNECT_TO] = [$connectTo];
        }

        if ($this->config->isPinned()) {
            $curl[CURLOPT_SSL_VERIFYPEER] = false;
            $curl[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        return $curl;
    }

    /**
     * cURL options that stop a response larger than $bytes: MAXFILESIZE refuses an answer whose
     * announced length is too big, the progress callback stops one without a length (chunked)
     * once it gets too big.
     *
     * @return array<int, mixed>
     */
    private static function sizeLimit(int $bytes): array
    {
        return [
            CURLOPT_MAXFILESIZE      => $bytes,
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_XFERINFOFUNCTION => static fn ($handle, $downloadTotal, $downloaded): int => $downloaded > $bytes ? 1 : 0,
        ];
    }

    /** True when this PHP's cURL accepts a pinned public key (checked once per request). */
    private static function pinningSupported(): bool
    {
        static $supported = null;

        if ($supported === null) {
            $probe     = curl_init();
            $supported = $probe !== false && @curl_setopt($probe, CURLOPT_PINNEDPUBLICKEY, 'sha256//' . base64_encode(str_repeat("\0", 32)));
        }

        return $supported;
    }

    /**
     * The cURL error text (e.g. "SSL certificate problem: unable to get local issuer certificate"),
     * one line, at most 200 characters, without the query string of any URL it mentions.
     */
    private static function detail(string $message): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? '';
        $text = preg_replace('/\?[^\s\'"]*/u', '', $text) ?? '';
        $text = trim($text);

        return $text !== '' ? mb_substr($text, 0, 200) : 'no details';
    }

    private static function describe(string $message): string
    {
        $message = strtolower($message);

        return match (true) {
            str_contains($message, 'timed out') || str_contains($message, 'timeout')   => 'timeout',
            str_contains($message, 'file size') || str_contains($message, 'callback')  => 'answer too large',
            str_contains($message, 'ssl') || str_contains($message, 'certificate')     => 'TLS/certificate error',
            str_contains($message, 'resolve')                                          => 'host not found',
            str_contains($message, 'refused') || str_contains($message, 'connect')     => 'connection failed',
            default                                                                    => 'network error',
        };
    }
}
