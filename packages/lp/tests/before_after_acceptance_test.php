#!/usr/bin/env php
<?php

/**
 * Acceptance tests for the config-driven "Antes e Depois" (before/after) block.
 *
 * Any artist can opt in by adding a `before_after` key to their config. The
 * block renders an accessible, draggable comparison slider per item.
 *
 * Run: php tests/before_after_acceptance_test.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$serverPort = 9877;

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

function assert_count(int $actual, int $expected, string $msg): void {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; } else {
        $fail++; echo "  FAIL: $msg — got $actual, expected $expected\n";
    }
}

/**
 * @return array{body: string, status: int}
 */
function fetch(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'status' => $status];
}

$docroot = dirname(__DIR__);
$router = escapeshellarg($docroot . '/router.php');
$docrootEsc = escapeshellarg($docroot);
$cmd = sprintf(
    'php -S localhost:%d -t %s %s > /dev/null 2>&1 & echo $!',
    $serverPort,
    $docrootEsc,
    $router
);
$pid = (int) trim((string) shell_exec($cmd));
register_shutdown_function(function () use ($pid) {
    if ($pid) { exec("kill $pid 2>/dev/null"); }
});
sleep(1);
$base = "http://localhost:$serverPort";

echo "=== Before/After Block Acceptance Tests ===\n\n";

// ── Opted-in artist (Adriano) ─────────────────────────────
$resp = fetch("$base/artists/adriano-santos");
$adriano = $resp['body'];
$css = fetch("$base/artists/artist.css")['body'];
$js = fetch("$base/artists/artist.js")['body'];

echo "Test: Adriano page loads\n";
assert_true($resp['status'] === 200, "status {$resp['status']} (expected 200)");

echo "Test: before/after section renders\n";
assert_contains($adriano, 'id="before_after"', 'section id');
assert_contains($adriano, 'Antes', 'before badge label');
assert_contains($adriano, 'Depois', 'after badge label');

echo "Test: section heading is configurable\n";
assert_contains($adriano, 'Coberturas', 'configured heading');
assert_contains($adriano, '<span>antes e depois</span>', 'configured highlight');

echo "Test: nav links to the before/after section\n";
assert_contains($adriano, 'href="#before_after"', 'anchor link');

echo "Test: one comparison slider per configured item\n";
assert_count(substr_count($adriano, 'class="ba-item"'), 3, 'ba-item count');
assert_count(substr_count($adriano, 'class="ba-range"'), 3, 'range input count');
assert_count(substr_count($adriano, 'class="ba-img ba-img--before"'), 3, 'before image count');
assert_count(substr_count($adriano, 'class="ba-img ba-img--after"'), 3, 'after image count');

echo "Test: slider is accessible\n";
assert_count(substr_count($adriano, 'type="range"'), 3, 'range inputs');
assert_contains($adriano, 'aria-label="Arrastar para comparar antes e depois', 'range aria-label');

echo "Test: photos are shown whole (contain), not cropped\n";
assert_contains($css, 'object-fit: contain', 'contain default');
assert_contains($adriano, 'class="ba-viewport" style="aspect-ratio: 4 / 5;"', 'default frame aspect');

echo "Test: slider handle is GPU-friendly (no per-frame layout)\n";
assert_contains($css, 'transform: translateX(var(--ba-pos))', 'handle moved via transform');
assert_not_contains($css, 'left: var(--ba-pos)', 'handle must not animate left');
assert_not_contains($css, 'transition: clip-path', 'clip-path must not lag behind drag');
assert_contains($js, 'requestAnimationFrame', 'input updates coalesced per frame');

echo "Test: local media resolves to an absolute URL\n";
assert_contains($adriano, '/artists/adriano-santos/media/', 'absolute media path');
assert_not_contains($adriano, 'src="artists/adriano-santos/media/', 'no relative media path');

// ── Opted-out artist (João) ───────────────────────────────
$joao = fetch("$base/artists/joao-silva")['body'];

echo "Test: block requires the explicit specialty flag\n";
assert_contains($adriano, 'id="before_after"', 'adriano declares specialty → section present');
assert_not_contains($joao, 'id="before_after"', 'section absent for joao-silva (no specialty)');
assert_not_contains($joao, 'class="ba-item"', 'items absent for joao-silva');

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
