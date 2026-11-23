<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Discovery;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\ListInfo;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpClient;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpException;

/**
 * Find Endpoints: one run checks the connection, reads a few rows of every endpoint the portal
 * uses, optionally looks up one requester by e-mail, and checks which other known endpoints
 * answer. collect() gives the result for the page (cards and tables); report() writes it as a
 * plain-text report the administrator can download and send to support.
 *
 * The result holds only paths, results, times, row counts, field paths, kinds of values and
 * status-like texts (see FieldInspector). Never keys, names, e-mails, ids, subjects or other
 * personal values. Only GET requests are sent.
 */
final class Diagnostics
{
    /** Sample rows read per portal endpoint. */
    private const SAMPLE_ROWS = 10;

    public const OK      = 'ok';
    public const DENIED  = 'denied';
    public const MISSING = 'missing';
    public const ERROR   = 'error';

    /**
     * @return array{
     *     connection: array{ok: bool, ms: int, total: ?int, kind: string, error: string},
     *     timing: list<string>,
     *     portal: list<array<string, mixed>>,
     *     requester: list<array{label: string, value: string, ok: bool}>|null,
     *     others: list<array<string, mixed>>,
     *     stopped: bool
     * }
     */
    public static function collect(SdpClient $client, ?string $email): array
    {
        $result = [
            'connection' => ['ok' => false, 'ms' => 0, 'total' => null, 'kind' => '', 'error' => ''],
            'timing'     => [],
            'portal'     => [],
            'requester'  => null,
            'others'     => [],
            'stopped'    => false,
        ];

        // 1. Connection: one row of requests.
        $started = hrtime(true);

        try {
            $probe                = $client->probe('/api/v3/requests', new ListInfo(rowCount: 1, getTotalCount: true));
            $result['connection'] = ['ok' => true, 'ms' => self::since($started), 'total' => $probe->totalCount, 'kind' => '', 'error' => ''];
        } catch (SdpException $e) {
            $result['connection'] = ['ok' => false, 'ms' => self::since($started), 'total' => null, 'kind' => $e->getKind(), 'error' => self::error($e)];
            $result['stopped']    = true;

            return $result;
        }

        $result['timing'] = self::timing($client);

        // 2. Endpoints the portal reads.
        $used = [];

        foreach (EndpointCatalog::all() as $ep) {
            if ($ep['portal'] === '') {
                continue;
            }

            $used[]  = $ep['path'];
            $row     = self::check($client, $ep, self::SAMPLE_ROWS, true);
            $result['portal'][] = $row;

            if ($row['kind'] === SdpException::NETWORK) {
                $result['stopped'] = true;

                return $result;
            }
        }

        // 3. One requester, as the portal finds them at sign-in.
        if ($email !== null && $email !== '') {
            $result['requester'] = self::requester($client, $email);
        }

        // 4. Other known endpoints: do they answer at all?
        foreach (EndpointCatalog::all() as $ep) {
            if (\in_array($ep['path'], $used, true)) {
                continue;
            }

            $row                = self::check($client, $ep, 1, false);
            $result['others'][] = $row;

            if ($row['kind'] === SdpException::NETWORK) {
                $result['stopped'] = true;

                break;
            }
        }

        return $result;
    }

    /**
     * The result as a plain-text report.
     *
     * @param array<string, mixed>  $result   From collect()
     * @param array<string, string> $context  "label => value" lines for the report head (version, settings)
     * @param list<string>          $warnings Warning texts shown at the top of the page
     */
    public static function report(array $result, ?string $email, array $context = [], array $warnings = []): string
    {
        $out   = ['ServiceDesk Plus portal diagnostics', str_repeat('=', 35)];
        $out[] = 'Run at: ' . gmdate('Y-m-d H:i') . ' UTC';
        $out[] = 'Requester e-mail given: ' . ($email !== null && $email !== '' ? 'yes' : 'no');

        foreach ($context as $label => $value) {
            $out[] = $label . ': ' . $value;
        }

        foreach ($warnings as $warning) {
            $out[] = 'WARNING: ' . $warning;
        }

        $c     = $result['connection'];
        $out[] = '';
        $out[] = '1. Connection (GET /api/v3/requests, one row)';

        if (!$c['ok']) {
            $out[] = '   FAILED: ' . $c['error'];
            $out[] = '';
            $out[] = 'Stopped: without a working connection the other checks cannot run.';

            return implode("\n", $out);
        }

        $out[] = '   OK in ' . $c['ms'] . ' ms, ' . ($c['total'] === null ? 'total not given' : $c['total'] . ' requests the API technician can see');
        $out[] = '';
        $out[] = '1b. Timing (small reads of /api/v3/requests)';
        $out   = array_merge($out, $result['timing']);

        $out[] = '';
        $out[] = '2. Endpoints the portal reads';

        foreach ($result['portal'] as $row) {
            $out[] = '   ' . $row['path'] . ' (' . $row['portal'] . ')';

            if ($row['status'] !== self::OK) {
                $out[] = '      FAILED in ' . $row['ms'] . ' ms: ' . $row['error'];

                continue;
            }

            $out[] = '      OK in ' . $row['ms'] . ' ms, ' . $row['rows'] . ' sample rows (' . ($row['total'] === null ? 'total not given' : $row['total'] . ' in total') . ')'
                . ($row['key'] !== '' ? ', under "' . $row['key'] . '"' : '');

            if ($row['fields'] === []) {
                $out[] = '      No fields (empty answer).';
            }

            foreach ($row['fields'] as $name => $info) {
                $out[] = '      - ' . $name . ' (' . $info['type'] . ')' . ($info['values'] !== [] ? ': ' . implode(' | ', \array_slice($info['values'], 0, 10)) : '');
            }
        }

        $n = 3;

        if ($result['requester'] !== null) {
            $out[] = '';
            $out[] = $n++ . '. Requester lookup by e-mail (as at sign-in)';

            foreach ($result['requester'] as $line) {
                $out[] = '   ' . $line['label'] . ($line['value'] !== '' ? ': ' . $line['value'] : '');
            }
        }

        $out[] = '';
        $out[] = $n . '. Other known endpoints';

        foreach ($result['others'] as $row) {
            $out[] = '   ' . $row['path'] . ': ' . ($row['status'] === self::OK
                ? 'OK' . ($row['total'] !== null ? ', ' . $row['total'] . ' in total' : '')
                : $row['error']);
        }

        if ($result['stopped']) {
            $out[] = '';
            $out[] = 'Stopped: ServiceDesk Plus could no longer be reached.';
        }

        return implode("\n", $out);
    }

    /**
     * One endpoint: its result, time, totals and (for the portal's own) its fields.
     *
     * @param array{path: string, summary: string, section: string, doc: string, portal: string} $ep
     *
     * @return array<string, mixed>
     */
    private static function check(SdpClient $client, array $ep, int $rows, bool $fields): array
    {
        $row     = $ep + ['status' => self::OK, 'kind' => '', 'http' => 0, 'ms' => 0, 'rows' => 0, 'total' => null, 'key' => '', 'error' => '', 'fields' => []];
        $started = hrtime(true);

        try {
            $probe        = $client->probe($ep['path'], new ListInfo(rowCount: $rows, getTotalCount: true));
            $row['ms']    = self::since($started);
            $row['rows']  = \count($probe->items);
            $row['total'] = $probe->totalCount;
            $row['key']   = $probe->key;

            if ($fields) {
                $row['fields'] = FieldInspector::inspect($probe->items);
            }
        } catch (SdpException $e) {
            $row['ms']     = self::since($started);
            $row['status'] = self::status($e);
            $row['kind']   = $e->getKind();
            $row['http']   = $e->getHttpStatus();
            $row['error']  = self::error($e);
        }

        return $row;
    }

    /** ok, denied (no permission), missing (not on this build) or error. */
    public static function status(SdpException $e): string
    {
        return match ($e->getKind()) {
            SdpException::PERMISSION, SdpException::LICENSE  => self::DENIED,
            SdpException::NOT_FOUND, SdpException::BAD_RESPONSE => self::MISSING,
            default                                          => self::ERROR,
        };
    }

    /**
     * Three reads one after another (the first may open the connection, the next reuse it), then
     * six at once.
     *
     * @return list<string>
     */
    private static function timing(SdpClient $client): array
    {
        $lines = [];
        $sum   = 0;

        for ($i = 1; $i <= 3; $i++) {
            $started = hrtime(true);

            try {
                $client->probe('/api/v3/requests', new ListInfo(rowCount: 1));
            } catch (SdpException $e) {
                return [...$lines, '   Read ' . $i . ' FAILED: ' . self::error($e)];
            }

            $ms  = self::since($started);
            $sum += $ms;
            $t   = $client->lastTiming();

            $lines[] = '   Read ' . $i . ': ' . $ms . ' ms' . ($t === null ? '' : ($t['reused']
                ? ' (reused connection; ServiceDesk Plus ' . $t['server'] . ' ms)'
                : ' (new connection: DNS ' . $t['dns'] . ' ms, connect ' . $t['connect'] . ' ms, TLS ' . $t['tls'] . ' ms; ServiceDesk Plus ' . $t['server'] . ' ms)'));
        }

        $calls = [];

        for ($i = 0; $i < 6; $i++) {
            $calls[$i] = ['/api/v3/requests', ['list_info' => ['row_count' => 1, 'start_index' => 1]]];
        }

        $started = hrtime(true);
        $results = $client->getMany($calls);
        $ms      = self::since($started);
        $failed  = \count(array_filter($results, static fn ($r): bool => $r instanceof SdpException));
        $average = (int) round($sum / 3);

        $lines[] = '   6 reads at once: ' . $ms . ' ms in total' . ($failed > 0 ? ' (' . $failed . ' failed)' : '')
            . ', one after another they would take about ' . (6 * $average) . ' ms';
        $lines[] = '   A high "ServiceDesk Plus" time means the server itself is slow to answer;'
            . ' high DNS, connect or TLS times point to the network, firewall or certificate setup.';

        return $lines;
    }

    /**
     * Counts only: how many users match the e-mail exactly, and for a single match how many
     * requests, assets and loans (current and returned) ServiceDesk Plus returns for that user.
     *
     * @return list<array{label: string, value: string, ok: bool}>
     */
    private static function requester(SdpClient $client, string $email): array
    {
        try {
            $users = $client->findUsersByEmail($email);
        } catch (SdpException $e) {
            return [['label' => 'User search', 'value' => 'FAILED: ' . self::error($e), 'ok' => false]];
        } catch (\InvalidArgumentException) {
            return [['label' => 'The e-mail is not valid.', 'value' => '', 'ok' => false]];
        }

        $wanted = mb_strtolower(trim($email));
        $exact  = array_values(array_filter($users, static fn (array $u): bool => mb_strtolower(trim((string) ($u['email_id'] ?? ''))) === $wanted));
        $lines  = [
            ['label' => 'Users returned', 'value' => (string) \count($users), 'ok' => true],
            ['label' => 'With exactly this e-mail', 'value' => (string) \count($exact), 'ok' => \count($exact) === 1],
        ];

        if (\count($exact) !== 1) {
            $lines[] = ['label' => \count($exact) === 0
                ? 'No user has exactly this e-mail: the portal cannot match this person.'
                : 'More than one user has this e-mail: the portal does not match such users automatically.', 'value' => '', 'ok' => false];

            return $lines;
        }

        $id = (string) ($exact[0]['id'] ?? '');

        if (!preg_match('/^[0-9]{1,20}$/', $id)) {
            $lines[] = ['label' => 'The matching user has no usable id.', 'value' => '', 'ok' => false];

            return $lines;
        }

        $checks = [
            'Requests of this requester (requester.id)' => ['/api/v3/requests', 'requester.id'],
            'Assets of this user (user.id)'             => ['/api/v3/assets', 'user.id'],
            'Loans of this user (loaned_to.id)'         => ['/api/v3/asset_loans', 'loaned_to.id'],
        ];

        foreach ($checks as $label => [$path, $field]) {
            try {
                $probe = $client->probe($path, new ListInfo(
                    rowCount: 1,
                    searchCriteria: [['field' => $field, 'condition' => 'is', 'value' => $id]],
                    getTotalCount: true
                ));
                $lines[] = ['label' => $label, 'value' => $probe->totalCount === null ? \count($probe->items) . ' row(s) on the first page' : (string) $probe->totalCount, 'ok' => true];
            } catch (SdpException $e) {
                $lines[] = ['label' => $label, 'value' => 'FAILED: ' . self::error($e), 'ok' => false];
            }
        }

        return $lines;
    }

    private static function since(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }

    public static function error(SdpException $e): string
    {
        $text = $e->getKind();

        if ($e->getHttpStatus() > 0) {
            $text .= ', HTTP ' . $e->getHttpStatus();
        }

        if ($e->getSdpCode() !== null) {
            $text .= ', code ' . $e->getSdpCode();
        }

        if ($e->getSdpMessage() !== '') {
            // The report is sent to support: e-mail addresses ServiceDesk Plus may repeat from a
            // search are left out.
            $said  = preg_replace('/[^\s@<>"\'(),;]+@[^\s@<>"\'(),;]+/u', '[e-mail]', $e->getSdpMessage()) ?? '';
            $text .= ', server said: ' . mb_substr($said, 0, 200);
        }

        $hint = match ($e->getKind()) {
            SdpException::BAD_RESPONSE => ' (not API data, usually a web page: this path does not exist on this build)',
            SdpException::NOT_FOUND    => ' (this path does not exist on this build)',
            SdpException::PERMISSION   => ' (the API technician may not read this)',
            SdpException::AUTH         => ' (the API key was rejected)',
            default                    => '',
        };

        return $text . $hint;
    }
}
