<?php

/**
 * @license GNU General Public License version 2 or later
 *
 * A small stand-in for ServiceDesk Plus (REST API v3) for end-to-end tests of the portal:
 *
 *   php -S 127.0.0.1:8099 tests/EndToEnd/fake-sdp.php
 *
 * It holds a few users, requests, approvals, assets, loans and announcements in a JSON file
 * (FAKE_SDP_STATE, default: the system temp folder) and appends every call to FAKE_SDP_LOG, so a
 * test can check what the portal sent. Never use it for anything but tests.
 */

const KEY = 'ABCDEF01-2345-6789-ABCD-EF0123456789';

$stateFile = getenv('FAKE_SDP_STATE') ?: sys_get_temp_dir() . '/fake-sdp-state.json';
$logFile   = getenv('FAKE_SDP_LOG') ?: sys_get_temp_dir() . '/fake-sdp.log';

$ms = static fn (int $daysAgo): array => ['value' => (string) ((time() - $daysAgo * 86400) * 1000), 'display_value' => 'x'];

$seed = static fn (): array => [
    'users' => [
        '501' => ['id' => '501', 'name' => 'Ali Requester', 'email_id' => 'admin@example.com', 'is_technician' => false],
        '502' => ['id' => '502', 'name' => 'Mona Other', 'email_id' => 'other@example.com', 'is_technician' => false],
        '900' => ['id' => '900', 'name' => 'API Technician', 'email_id' => 'api@example.com', 'is_technician' => true],
    ],
    'requests' => [
        '100' => ['id' => '100', 'subject' => 'Laptop <b>broken</b>', 'requester' => ['id' => '501', 'name' => 'Ali Requester'], 'status' => ['name' => 'Open'], 'priority' => ['name' => 'High'], 'category' => ['name' => 'Hardware'], 'is_service_request' => false, 'created_time' => $ms(2), 'description' => '<p>Screen is <script>alert(1)</script>dark</p>'],
        '101' => ['id' => '101', 'subject' => 'New mouse', 'requester' => ['id' => '501', 'name' => 'Ali Requester'], 'status' => ['name' => 'Onhold'], 'priority' => ['name' => 'Low'], 'category' => ['name' => 'Hardware'], 'is_service_request' => true, 'created_time' => $ms(5), 'description' => 'Please'],
        '200' => ['id' => '200', 'subject' => 'Budget approval of Mona', 'requester' => ['id' => '502', 'name' => 'Mona Other'], 'status' => ['name' => 'Open'], 'priority' => ['name' => 'Normal'], 'category' => ['name' => 'Finance'], 'is_service_request' => true, 'approval_status' => ['name' => 'Pending Approval'], 'created_time' => $ms(1), 'description' => 'Needs approval'],
        '300' => ['id' => '300', 'subject' => 'Private request of Mona', 'requester' => ['id' => '502', 'name' => 'Mona Other'], 'status' => ['name' => 'Open'], 'priority' => ['name' => 'Normal'], 'category' => ['name' => 'HR'], 'is_service_request' => false, 'created_time' => $ms(1), 'description' => 'SECRET-SALARY-DATA'],
    ],
    // request id => levels; each level holds its approvals
    'levels' => [
        '200' => [['id' => '20', 'level' => 1, 'name' => 'Level 1', 'is_current' => true, 'status' => ['name' => 'Pending Approval'], 'approvals' => [
            ['id' => '3', 'approver' => ['id' => '501', 'name' => 'Ali Requester', 'email_id' => 'admin@example.com'], 'status' => ['name' => 'Pending Approval'], 'sent_on' => $ms(1)],
        ]]],
        '300' => [['id' => '30', 'level' => 1, 'name' => 'Level 1', 'is_current' => true, 'status' => ['name' => 'Pending Approval'], 'approvals' => [
            ['id' => '4', 'approver' => ['id' => '502', 'name' => 'Mona Other', 'email_id' => 'other@example.com'], 'status' => ['name' => 'Pending Approval'], 'sent_on' => $ms(1)],
        ]]],
    ],
    'assets' => [
        '700' => ['id' => '700', 'name' => 'LAPTOP-ALI', 'user' => ['id' => '501'], 'is_loaned' => true, 'product' => ['name' => 'ThinkPad'], 'product_type' => ['name' => 'Laptop'], 'loan_start' => $ms(10), 'loan_end' => $ms(-5)],
        '701' => ['id' => '701', 'name' => 'LAPTOP-MONA', 'user' => ['id' => '502'], 'is_loaned' => true, 'product' => ['name' => 'MacBook'], 'product_type' => ['name' => 'Laptop']],
    ],
    'asset_loans' => [
        ['id' => '800', 'loaned_to' => ['id' => '501'], 'status' => 'Closed', 'start_time' => $ms(60), 'end_time' => $ms(40), 'returned_on' => $ms(41), 'loaned_assets' => [['asset' => ['id' => '700', 'name' => 'LAPTOP-ALI']]]],
        ['id' => '801', 'loaned_to' => ['id' => '502'], 'status' => 'Closed', 'start_time' => $ms(60), 'end_time' => $ms(40), 'returned_on' => $ms(41), 'loaned_assets' => [['asset' => ['id' => '701', 'name' => 'LAPTOP-MONA']]]],
    ],
    'announcements' => [
        '11' => ['id' => '11', 'title' => 'Maintenance on Friday', 'description' => '<p>Network <img src=x onerror=alert(2)> down</p>', 'is_public' => true, 'from_date' => $ms(1), 'to_date' => $ms(-10), 'created_time' => $ms(1)],
        '12' => ['id' => '12', 'title' => 'TECHNICIANS-ONLY-NOTE', 'description' => 'internal', 'is_public' => false, 'from_date' => $ms(1), 'to_date' => $ms(-10), 'created_time' => $ms(1)],
    ],
    'categories' => [['id' => '1', 'name' => 'Hardware'], ['id' => '2', 'name' => 'Finance'], ['id' => '3', 'name' => 'HR']],
];

$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : $seed();
$save  = static function () use (&$state, $stateFile): void {
    file_put_contents($stateFile, json_encode($state), LOCK_EX);
};

$method = $_SERVER['REQUEST_METHOD'];
$path   = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str($method === 'GET' ? (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) : (string) file_get_contents('php://input'), $params);
$input  = isset($params['input_data']) ? (json_decode((string) $params['input_data'], true) ?: []) : [];
$key    = $_SERVER['HTTP_AUTHTOKEN'] ?? '';

file_put_contents($logFile, json_encode(['method' => $method, 'path' => $path, 'input' => $input, 'key_ok' => $key === KEY]) . "\n", FILE_APPEND | LOCK_EX);

$send = static function (array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data + ['response_status' => ['status_code' => 2000, 'status' => 'success']]);
    exit;
};
$fail = static function (int $http, int $code, string $message): never {
    http_response_code($http);
    header('Content-Type: application/json');
    echo json_encode(['response_status' => ['status_code' => 4000, 'status' => 'failed', 'messages' => [['status_code' => $code, 'type' => 'failed', 'message' => $message]]]]);
    exit;
};

if ($path === '/__reset') {
    $state = $seed();
    $save();
    @unlink($logFile);
    $send(['reset' => true]);
}

if ($key !== KEY) {
    $fail(401, 4022, 'Invalid API key');
}

/** Value at "a.b" of a row. */
$at = static function (array $row, string $field): mixed {
    foreach (explode('.', $field) as $part) {
        if (!is_array($row) || !array_key_exists($part, $row)) {
            return null;
        }

        $row = $row[$part];
    }

    return $row;
};

/** search_criteria (one criterion or a list joined by logical_operator) against a row. */
$matches = static function (mixed $criteria, array $row) use (&$matches, $at): bool {
    if (!is_array($criteria) || $criteria === []) {
        return true;
    }

    if (array_is_list($criteria)) {
        $result = null;

        foreach ($criteria as $c) {
            $one    = $matches($c, $row);
            $op     = strtoupper((string) ($c['logical_operator'] ?? 'AND'));
            $result = $result === null ? $one : ($op === 'OR' ? ($result || $one) : ($result && $one));
        }

        return $result ?? true;
    }

    $value  = $at($row, (string) ($criteria['field'] ?? ''));
    $wanted = $criteria['values'] ?? [$criteria['value'] ?? null];
    $norm   = static fn (mixed $v): string => is_bool($v) ? ($v ? 'true' : 'false') : mb_strtolower((string) $v);
    $hit    = match ($criteria['condition'] ?? 'is') {
        'contains' => str_contains($norm($value), $norm($wanted[0])),
        'is not'   => !in_array($norm($value), array_map($norm, $wanted), true),
        default    => in_array($norm($value), array_map($norm, $wanted), true),
    };

    if (isset($criteria['children'])) {
        $hit = $hit && $matches($criteria['children'], $row);
    }

    return $hit;
};

$list = static function (string $key, array $rows) use ($input, $matches, $send): never {
    $info  = $input['list_info'] ?? [];
    $rows  = array_values(array_filter($rows, static fn (array $r): bool => $matches($info['search_criteria'] ?? null, $r)));

    foreach ($info['search_fields'] ?? [] as $field => $value) {
        $rows = array_values(array_filter($rows, static fn (array $r): bool => str_contains(mb_strtolower((string) ($r[$field] ?? '')), mb_strtolower((string) $value))));
    }

    $total = count($rows);
    $start = max(1, (int) ($info['start_index'] ?? 1));
    $count = max(1, min(100, (int) ($info['row_count'] ?? 10)));
    $page  = array_slice($rows, $start - 1, $count);

    $send([$key => $page, 'list_info' => ['has_more_rows' => $start - 1 + $count < $total, 'start_index' => $start, 'row_count' => count($page), 'total_count' => $total]]);
};

$seg = array_values(array_filter(explode('/', substr($path, strlen('/api/v3')))));

// /users
if ($seg[0] === 'users') {
    isset($seg[1]) ? (isset($state['users'][$seg[1]]) ? $send(['user' => $state['users'][$seg[1]]]) : $fail(404, 4007, 'Invalid URL')) : $list('users', $state['users']);
}

// /approvals: every approval with its level and request
if ($seg[0] === 'approvals') {
    $rows = [];

    foreach ($state['levels'] as $requestId => $levels) {
        foreach ($levels as $level) {
            foreach ($level['approvals'] as $approval) {
                $rows[] = $approval + ['associated_entity' => 'request', 'approval_level' => [
                    'id' => $level['id'], 'name' => $level['name'],
                    'request' => array_intersect_key($state['requests'][$requestId], array_flip(['id', 'subject', 'requester', 'status'])),
                ]];
            }
        }
    }

    $list('approvals', $rows);
}

if ($seg[0] === 'requests') {
    $id = $seg[1] ?? null;

    if ($id === null) {
        if ($method === 'POST') {
            $new = (string) (max(array_map('intval', array_keys($state['requests']))) + 1000);
            $state['requests'][$new] = ['id' => $new, 'status' => ['name' => 'Open'], 'created_time' => ['value' => (string) (time() * 1000)]] + ($input['request'] ?? []);
            $save();
            $send(['request' => $state['requests'][$new]], 201);
        }

        $list('requests', $state['requests']);
    }

    if (!isset($state['requests'][$id])) {
        $fail(404, 4007, 'Invalid URL');
    }

    $sub = $seg[2] ?? null;

    if ($sub === null) {
        $send(['request' => $state['requests'][$id]]);
    }

    if ($sub === 'approval_levels') {
        $levels = $state['levels'][$id] ?? [];

        if (!isset($seg[3])) {
            $send(['approval_levels' => array_map(static fn (array $l): array => array_diff_key($l, ['approvals' => 1]), $levels)]);
        }

        foreach ($levels as $li => $level) {
            if ($level['id'] !== $seg[3]) {
                continue;
            }

            if (!isset($seg[5])) {
                $send(['approvals' => $level['approvals']]);
            }

            foreach ($level['approvals'] as $ai => $approval) {
                if ($approval['id'] !== $seg[5]) {
                    continue;
                }

                $action = ltrim($seg[6] ?? '', '_');

                if ($method === 'PUT' && in_array($action, ['approve', 'reject'], true)) {
                    if ($approval['status']['name'] !== 'Pending Approval') {
                        $fail(400, 4001, 'Already decided');
                    }

                    $name = $action === 'approve' ? 'Approved' : 'Rejected';
                    $state['levels'][$id][$li]['approvals'][$ai]['status']['name']       = $name;
                    $state['levels'][$id][$li]['approvals'][$ai]['comments']             = $input['approval']['comments'] ?? '';
                    $state['levels'][$id][$li]['approvals'][$ai]['action_taken_on']      = ['value' => (string) (time() * 1000)];
                    $state['levels'][$id][$li]['status']['name']                         = $name;
                    $state['requests'][$id]['approval_status']['name']                   = $name;
                    $save();
                    $send(['approval' => $state['levels'][$id][$li]['approvals'][$ai]]);
                }

                $send(['approval' => $approval]);
            }
        }

        $fail(404, 4007, 'Invalid URL');
    }

    if ($sub === 'conversations') {
        $base = 'http://' . $_SERVER['HTTP_HOST'] . '/api/v3/requests/' . $id;
        $send(['conversations' => [
            ['type' => 'notes', 'show_to_requester' => true, 'content_url' => $base . '/notes/1'],
            ['type' => 'notes', 'show_to_requester' => false, 'content_url' => $base . '/notes/2'],
        ]]);
    }

    if ($sub === 'notes') {
        ($seg[3] ?? '') === '1'
            ? $send(['note' => ['id' => '1', 'description' => 'VISIBLE-NOTE <i>for you</i>', 'show_to_requester' => true, 'added_time' => ['value' => (string) (time() * 1000)]]])
            : $send(['note' => ['id' => '2', 'description' => 'INTERNAL-TECH-NOTE', 'show_to_requester' => false]]);
    }

    $fail(404, 4007, 'Invalid URL');
}

if ($seg[0] === 'assets') {
    isset($seg[1]) ? (isset($state['assets'][$seg[1]]) ? $send(['asset' => $state['assets'][$seg[1]]]) : $fail(404, 4007, 'Invalid URL')) : $list('assets', $state['assets']);
}

if ($seg[0] === 'announcements') {
    isset($seg[1]) ? (isset($state['announcements'][$seg[1]]) ? $send(['announcement' => $state['announcements'][$seg[1]]]) : $fail(404, 4007, 'Invalid URL')) : $list('announcements', $state['announcements']);
}

if (isset($state[$seg[0]]) && !isset($seg[1])) {
    $list($seg[0], $state[$seg[0]]);
}

// An answer far larger than any real one (about 24 MB): the portal must cut it off.
if ($seg[0] === 'huge_list') {
    header('Content-Type: application/json');
    echo '{"huge_list":[';

    for ($i = 0; $i < 24; $i++) {
        echo '{"id":"' . $i . '","text":"' . str_repeat('x', 1024 * 1024) . '"},';
        flush();
    }

    echo '{"id":"last"}]}';
    exit;
}

// Anything else (changes, releases, purchase orders, ...): not on this "build".
$fail(404, 4007, 'Invalid URL');
