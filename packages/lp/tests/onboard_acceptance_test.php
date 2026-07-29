#!/usr/bin/env php
<?php
/**
 * Acceptance tests for the onboarding portal page
 * Run: php tests/onboard_acceptance_test.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$server_port = 9876;

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

function fetch(string $url): string {
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    return @file_get_contents($url, false, $ctx) ?: '';
}

// Start PHP built-in server (cleanup via register_shutdown_function)
$docroot = dirname(__DIR__);
$cmd = sprintf('php -S localhost:%d -t %s > /dev/null 2>&1 & echo $!', $server_port, escapeshellarg($docroot));
$pid = (int) trim((string) shell_exec($cmd));
register_shutdown_function(function () use ($pid) {
    if ($pid) { exec("kill $pid 2>/dev/null"); }
});
sleep(1);
$base = "http://localhost:$server_port";

echo "=== Onboarding Portal Acceptance Tests ===\n\n";

// Test 1: Page renders valid HTML
echo "Test: Page renders valid HTML structure\n";
$resp = fetch("$base/onboard/test-token-123");
assert_contains($resp, '<!DOCTYPE html>', 'Has DOCTYPE');
assert_contains($resp, '<html lang="pt-BR">', 'Has Portuguese lang attribute');
assert_contains($resp, '</html>', 'Has closing html tag');
assert_contains($resp, '<meta name="viewport"', 'Has viewport meta tag');

// Test 2: Mobile-friendly design
echo "Test: Mobile-friendly design\n";
assert_contains($resp, 'initial-scale=1.0', 'Has initial-scale viewport');
assert_contains($resp, 'width=device-width', 'Has width=device-width');

// Test 3: VAIF branding present
echo "Test: VAIF branding present\n";
assert_true(str_contains($resp, 'VAIF') || str_contains($resp, 'vaif'), 'VAIF name appears');

// Test 4: Portuguese content
echo "Test: Portuguese content present\n";
assert_contains($resp, 'VAIF', 'Page mentions VAIF');
assert_true(
    str_contains($resp, 'onboarding') || str_contains($resp, 'manutenção') ||
    str_contains($resp, 'bem-vindo') || str_contains($resp, 'inválido') ||
    str_contains($resp, 'expirado') || str_contains($resp, 'solicite'),
    'Page has Portuguese content'
);

// Test 5: Card layout present
echo "Test: Card layout structure\n";
assert_contains($resp, 'card', 'Page uses card layout');

// Test 6: Font imports present
echo "Test: Google Fonts loaded\n";
assert_contains($resp, 'fonts.googleapis.com', 'Google Fonts loaded');
assert_contains($resp, 'Montserrat', 'Montserrat font included');
assert_contains($resp, 'Cormorant Garamond', 'Cormorant Garamond font included');

// Test 7: Gold accent color in CSS
echo "Test: Gold accent color\n";
assert_contains($resp, '#D4B04C', 'Gold color used in CSS');

// Test 8: No PHP errors exposed
echo "Test: No PHP errors exposed\n";
assert_not_contains($resp, 'PHP Warning', 'No PHP warnings');
assert_not_contains($resp, 'Fatal error', 'No fatal errors');
assert_not_contains($resp, 'Parse error', 'No parse errors');
assert_not_contains($resp, 'Stack trace:', 'No stack traces');

// Test 9: Error path — missing token
echo "Test: Missing token route returns error\n";
$resp2 = fetch("$base/onboard/");
assert_contains($resp2, '<html', 'Missing token page renders HTML');
assert_contains($resp2, 'inválido', 'Missing token shows invalid message');

// Test 10: Success route
echo "Test: Success route works\n";
$resp3 = fetch("$base/onboard/sucesso");
assert_contains($resp3, 'Conectado com sucesso', 'Success page shows connected message');
assert_contains($resp3, 'Beatriz', 'Success page mentions Beatriz');
assert_contains($resp3, 'btn', 'Success page has CTA button');

// Test 11: Valid token page content — shows maintenance when n8n unavailable,
// shows onboarding when n8n is available. Both are valid states.
echo "Test: Valid token page is well-formed\n";
$resp4 = fetch("$base/onboard/abctoken12345");
assert_contains($resp4, '<html', 'Token page renders HTML');
assert_contains($resp4, 'card', 'Token page has card layout');
assert_contains($resp4, 'btn', 'Token page has a button');
// Must not output raw PHP errors in any state
assert_not_contains($resp4, 'Warning:', 'No PHP warnings on token page');
assert_not_contains($resp4, 'Fatal error', 'No PHP fatal errors on token page');

// Cleanup
if ($pid) { exec("kill $pid 2>/dev/null"); }

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
