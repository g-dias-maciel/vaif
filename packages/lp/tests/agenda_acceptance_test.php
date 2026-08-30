#!/usr/bin/env php
<?php
/**
 * Acceptance tests for the artist agenda admin page (/agenda/<token>).
 *
 * Spins up two PHP built-in servers:
 *   - a mock of the n8n "Artist Calendar Webhook" (#29) on port 9877
 *   - the LP app (with router.php, N8N_AGENDA_WEBHOOK_URL → mock) on port 9876
 *
 * Run: php tests/agenda_acceptance_test.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$lpPort = 9876;
$mockPort = 9877;
$docroot = dirname(__DIR__);

function assert_true(bool $cond, string $msg): void {
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "  FAIL: $msg\n"; }
}

function assert_contains(string $haystack, string $needle, string $msg): void {
    global $pass, $fail;
    if (str_contains($haystack, $needle)) { $pass++; } else {
        $fail++; echo "  FAIL: $msg — not found: '$needle'\n";
    }
}

function assert_not_contains(string $haystack, string $needle, string $msg): void {
    global $pass, $fail;
    if (!str_contains($haystack, $needle)) { $pass++; } else {
        $fail++; echo "  FAIL: $msg — found: '$needle'\n";
    }
}

/**
 * @return array{body: string, status: int}
 */
function request(string $url, ?array $post = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'status' => $status];
}

// --- 1. Start the mock n8n calendar webhook ---
$mockRouter = escapeshellarg(__DIR__ . '/fixtures/agenda_mock.php');
$mockPid = (int) trim((string) shell_exec(sprintf(
    'php -S localhost:%d %s > /dev/null 2>&1 & echo $!',
    $mockPort,
    $mockRouter
)));

// --- 2. Start the LP app, wired to the mock ---
$router = escapeshellarg($docroot . '/router.php');
$docrootEsc = escapeshellarg($docroot);
$lpPid = (int) trim((string) shell_exec(sprintf(
    'N8N_AGENDA_WEBHOOK_URL=http://localhost:%d/webhook/calendar php -S localhost:%d -t %s %s > /dev/null 2>&1 & echo $!',
    $mockPort,
    $lpPort,
    $docrootEsc,
    $router
)));

register_shutdown_function(function () use ($mockPid, $lpPid) {
    if ($mockPid) { exec("kill $mockPid 2>/dev/null"); }
    if ($lpPid) { exec("kill $lpPid 2>/dev/null"); }
});
sleep(1);

$mockBase = "http://localhost:$mockPort";
$base = "http://localhost:$lpPort";

// Reset mock state to the canonical starting point.
request("$mockBase/?reset=1");

echo "=== Agenda Admin Page Acceptance Tests ===\n\n";

$VALID = 'testvalidtoken123';
$SLOT1_ID = 'aaaa1111-0000-0000-0000-000000000001';
$BLOCK1_ID = 'cccc3333-0000-0000-0000-000000000001';

// --- Test 1: missing token → invalid link state ---
echo "Test: missing token shows invalid-link state\n";
$resp = request("$base/agenda/");
assert_true($resp['status'] === 200, "/agenda/ status {$resp['status']}");
assert_contains($resp['body'], '<html', 'Missing token renders HTML');
assert_contains($resp['body'], 'inválido', 'Missing token shows invalid message');

// --- Test 2: unknown token → invalid-link state (same as onboarding) ---
echo "Test: unknown token shows invalid-link state\n";
$resp = request("$base/agenda/invalidtoken999");
assert_true($resp['status'] === 200, "/agenda/<unknown> status {$resp['status']}");
assert_contains($resp['body'], '<html', 'Unknown token renders HTML');
assert_contains($resp['body'], 'inválido', 'Unknown token shows invalid message');
assert_contains($resp['body'], 'expirou', 'Unknown token mentions expiry');
assert_not_contains($resp['body'], 'João Silva', 'No artist content leaked for invalid token');

// --- Test 3: valid token — page structure + branding ---
echo "Test: valid token renders the agenda with availability\n";
$resp = request("$base/agenda/$VALID");
assert_true($resp['status'] === 200, "/agenda/<valid> status {$resp['status']}");
assert_contains($resp['body'], '<!DOCTYPE html>', 'Has DOCTYPE');
assert_contains($resp['body'], 'card', 'Uses card layout');
assert_contains($resp['body'], '#D4B04C', 'Gold accent present');
assert_contains($resp['body'], 'fonts.googleapis.com', 'Google Fonts loaded');
assert_contains($resp['body'], 'João Silva', 'Artist name shown');
assert_contains($resp['body'], 'Disponibilidade', 'Availability section present');
assert_contains($resp['body'], 'Bloquear', 'Block UI present');
assert_contains($resp['body'], 'Horários bloqueados', 'Blocks section present');
assert_contains($resp['body'], 'America/Sao_Paulo', 'Artist timezone surfaced');
assert_not_contains($resp['body'], 'PHP Warning', 'No PHP warnings');
assert_not_contains($resp['body'], 'Fatal error', 'No fatal errors');

// --- Test 4: availability shown in the artist's timezone ---
// Mock returns 2026-09-10T13:00:00Z; America/Sao_Paulo (UTC-3) → 10/09/2026 10:00.
echo "Test: availability rendered in the artist's timezone\n";
assert_contains($resp['body'], '10/09/2026 10:00', 'First slot converted to artist timezone');

// --- Test 5: block a range → leaves availability, appears in blocks ---
echo "Test: blocking a range removes it from availability and lists the block\n";
$resp = request("$base/agenda/$VALID", [
    'action' => 'block',
    'start_at' => '2026-09-10T13:00:00Z',
    'end_at'   => '2026-09-10T14:00:00Z',
]);
assert_true($resp['status'] === 200, "block POST status {$resp['status']}");
assert_contains($resp['body'], 'bloqueado', 'Block success message shown');

$resp = request("$base/agenda/$VALID");
assert_contains($resp['body'], 'data-available-count="1"', 'Availability dropped to 1 slot');
assert_contains($resp['body'], 'data-block-count="1"', 'Block count is 1');
assert_contains($resp['body'], 'cccc3333-0000-0000-0000-000000000001', 'Block id rendered');
assert_contains($resp['body'], 'Desbloquear', 'Unblock button rendered for the block');
assert_not_contains($resp['body'], "slot-$SLOT1_ID", 'Blocked slot no longer offered as available');

// --- Test 6: custom range block form present + converts artist-local time to UTC ---
echo "Test: custom block-range form converts local time to UTC for the webhook\n";
$resp = request("$base/agenda/$VALID");
assert_contains($resp['body'], 'name="block_date"', 'Date input present');
assert_contains($resp['body'], 'name="block_start"', 'Start time input present');
assert_contains($resp['body'], 'name="block_end"', 'End time input present');

// Artist picks 10:00–11:00 in America/Sao_Paulo (UTC-3) → 13:00–14:00Z for the webhook.
request("$mockBase/?reset=1");
$resp = request("$base/agenda/$VALID", [
    'action' => 'block',
    'timezone'   => 'America/Sao_Paulo',
    'block_date'  => '2026-09-10',
    'block_start' => '10:00',
    'block_end'   => '11:00',
]);
assert_true($resp['status'] === 200, "custom block POST status {$resp['status']}");
assert_contains($resp['body'], 'bloqueado', 'Custom block success message shown');

$resp = request("$base/agenda/$VALID");
assert_contains($resp['body'], 'data-available-count="1"', 'Custom block dropped availability to 1');
assert_contains($resp['body'], 'data-block-count="1"', 'Custom block listed');
assert_contains($resp['body'], '10/09/2026 10:00 – 10/09/2026 11:00', 'Block rendered in artist local time');

// --- Test 7: unblock a range → it returns to availability ---
echo "Test: unblocking restores the range to availability\n";
$resp = request("$base/agenda/$VALID", [
    'action' => 'unblock',
    'block_id' => $BLOCK1_ID,
]);
assert_true($resp['status'] === 200, "unblock POST status {$resp['status']}");
assert_contains($resp['body'], 'desbloqueado', 'Unblock success message shown');

$resp = request("$base/agenda/$VALID");
assert_contains($resp['body'], 'data-available-count="2"', 'Availability restored to 2 slots');
assert_contains($resp['body'], 'data-block-count="0"', 'Block count back to 0');
assert_not_contains($resp['body'], 'cccc3333-0000-0000-0000-000000000001', 'Block id no longer rendered');

// --- Test 8: no PHP errors in any state ---
echo "Test: no PHP errors exposed across states\n";
foreach ([
    "$base/agenda/",
    "$base/agenda/invalidtoken999",
    "$base/agenda/$VALID",
] as $u) {
    $r = request($u);
    assert_not_contains($r['body'], 'Warning:', 'No PHP warnings');
    assert_not_contains($r['body'], 'Fatal error', 'No fatal errors');
    assert_not_contains($r['body'], 'Parse error', 'No parse errors');
    assert_not_contains($r['body'], 'Stack trace:', 'No stack traces');
}

// --- Cleanup ---
if ($mockPid) { exec("kill $mockPid 2>/dev/null"); }
if ($lpPid) { exec("kill $lpPid 2>/dev/null"); }

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);