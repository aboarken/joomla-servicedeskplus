<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Discovery;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\ListInfo;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\ProbeResult;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpClient;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\SdpException;

/**
 * One-click Diagnostics (Find Endpoints): runs the connection check, reads a few rows of every
 * endpoint the portal uses, optionally looks up one requester by e-mail, and checks which other
 * known endpoints answer. Returns a plain-text report the administrator can copy and send to
 * support.
 *
 * The report holds only paths, HTTP results, row counts, field paths, kinds of values and
 * status-like texts (see FieldInspector). Never keys, names, e-mails, ids, subjects or other
 * personal values. Only GET requests are sent.
 */
final class Diagnostics
{
    /** Sample rows read per portal endpoint. */
    private const SAMPLE_ROWS = 10;

    /**
     * @param array<string, string> $context  "label => value" lines for the report head (version, settings)
     */
    public static function run(SdpClient $client, ?string $email, array $context = []): string
    {
        $out   = ['ServiceDesk Plus portal diagnostics', str_repeat('=', 35)];
        $out[] = 'Run at: ' . gmdate('Y-m-d H:i') . ' UTC';
        $out[] = 'Requester e-mail given: ' . ($email !== null && $email !== '' ? 'yes' : 'no');

        foreach ($context as $label => $value) {
            $out[] = $label . ': ' . $value;
        }

        // 1. Connection.
        $out[] = '';
        $out[] = '1. Connection (GET /api/v3/requests, one row)';

        try {
            $probe = $client->probe('/api/v3/requests', new ListInfo(rowCount: 1, getTotalCount: true));
            $out[] = '   OK, ' . ($probe->totalCount === null ? 'total not given' : $probe->totalCount . ' requests the API technician can see');
        } catch (SdpException $e) {
            $out[] = '   FAILED: ' . self::error($e);
            $out[] = '';
            $out[] = 'Stopped: without a working connection the other checks cannot run.';

            return implode("\n", $out);
        }

        // Timing: where the time of a small read goes, and what reuse and parallel reads gain.
        $out[] = '';
        $out[] = '1b. Timing (small reads of /api/v3/requests)';
        $out   = array_merge($out, self::timing($client));

        // 2. Endpoints the portal reads.
        $out[] = '';
        $out[] = '2. Endpoints the portal reads';

        foreach (EndpointCatalog::portalPaths() as $path) {
            $out[] = '   ' . $path;

            try {
                $out = array_merge($out, self::describe($client->probe($path, new ListInfo(rowCount: self::SAMPLE_ROWS, getTotalCount: true)), '      '));
            } catch (SdpException $e) {
                $out[] = '      FAILED: ' . self::error($e);
            }
        }

        // 3. One requester, as the portal finds them at sign-in.
        if ($email !== null && $email !== '') {
            $out[] = '';
            $out[] = '3. Requester lookup by e-mail (as at sign-in)';
            $out   = array_merge($out, self::requester($client, $email));
        }

        // 4. Other known endpoints: do they answer at all?
        $out[] = '';
        $out[] = ($email !== null && $email !== '' ? '4' : '3') . '. Other known endpoints';

        foreach (EndpointCatalog::all() as $ep) {
            if ($ep['portal'] !== '') {
                continue;
            }

            try {
                $probe = $client->probe($ep['path'], new ListInfo(rowCount: 1, getTotalCount: true));
                $out[] = '   ' . $ep['path'] . ': OK' . ($probe->totalCount !== null ? ', ' . $probe->totalCount . ' in total' : '');
            } catch (SdpException $e) {
                $out[] = '   ' . $ep['path'] . ': ' . self::error($e);

                if ($e->getKind() === SdpException::NETWORK) {
                    $out[] = '   Stopped: ServiceDesk Plus could no longer be reached.';

                    break;
                }
            }
        }

        return implode("\n", $out);
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

            $ms  = (int) ((hrtime(true) - $started) / 1_000_000);
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
        $ms      = (int) ((hrtime(true) - $started) / 1_000_000);
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
     * @return list<string>
     */
    private static function requester(SdpClient $client, string $email): array
    {
        try {
            $users = $client->findUsersByEmail($email);
        } catch (SdpException $e) {
            return ['   User search FAILED: ' . self::error($e)];
        } catch (\InvalidArgumentException) {
            return ['   The e-mail is not valid.'];
        }

        $wanted = mb_strtolower(trim($email));
        $exact  = array_values(array_filter($users, static fn (array $u): bool => mb_strtolower(trim((string) ($u['email_id'] ?? ''))) === $wanted));
        $lines  = ['   Users returned: ' . \count($users) . ', with exactly this e-mail: ' . \count($exact)];

        if (\count($exact) !== 1) {
            $lines[] = \count($exact) === 0
                ? '   No user has exactly this e-mail: the portal cannot match this person.'
                : '   More than one user has this e-mail: the portal does not match such users automatically.';

            return $lines;
        }

        $id = (string) ($exact[0]['id'] ?? '');

        if (!preg_match('/^[0-9]{1,20}$/', $id)) {
            $lines[] = '   The matching user has no usable id.';

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
                $lines[] = '   ' . $label . ': ' . ($probe->totalCount === null ? \count($probe->items) . ' row(s) on the first page' : $probe->totalCount);
            } catch (SdpException $e) {
                $lines[] = '   ' . $label . ': FAILED: ' . self::error($e);
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function describe(ProbeResult $probe, string $indent = '   '): array
    {
        $lines  = [$indent . 'OK, ' . \count($probe->items) . ' sample rows (' . ($probe->totalCount === null ? 'total not given' : $probe->totalCount . ' in total') . ')'
            . ($probe->key !== '' ? ', under "' . $probe->key . '"' : '')];
        $fields = FieldInspector::inspect($probe->items);

        if ($fields === []) {
            $lines[] = $indent . 'No fields (empty answer).';

            return $lines;
        }

        foreach ($fields as $name => $info) {
            $lines[] = $indent . '- ' . $name . ' (' . $info['type'] . ')' . ($info['values'] !== [] ? ': ' . implode(' | ', \array_slice($info['values'], 0, 10)) : '');
        }

        return $lines;
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
            $text .= ', server said: ' . mb_substr($e->getSdpMessage(), 0, 200);
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
