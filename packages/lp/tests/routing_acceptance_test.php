#!/usr/bin/env php
<?php

/**
 * Acceptance tests for Nginx-style routing via php -S router script.
 * Run: php tests/routing_acceptance_test.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$serverPort = 9876;

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
 * @return array{body: string, status: int, content_type: string}
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
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['body' => $body, 'status' => $status, 'content_type' => $contentType];
}

$docroot = dirname(__DIR__);
$router  = escapeshellarg($docroot . '/router.php');
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

echo "=== Routing Acceptance Tests ===\n\n";

// ---- Blog routing ----
echo "Test: /blog returns 200 with blog stub content\n";
$resp = fetch("$base/blog");
assert_true($resp['status'] === 200, "/blog status {$resp['status']} (expected 200)");
assert_contains($resp['body'], 'Blog front-controller', '/blog body');

echo "Test: /blog/ (trailing slash) returns 200\n";
$resp = fetch("$base/blog/");
assert_true($resp['status'] === 200, "/blog/ status {$resp['status']} (expected 200)");
assert_contains($resp['body'], 'Blog front-controller', '/blog/ body');

echo "Test: /blog/some-post returns 200 (clean URL routing)\n";
$resp = fetch("$base/blog/some-post");
assert_true($resp['status'] === 200, "/blog/some-post status {$resp['status']} (expected 200)");
assert_contains($resp['body'], 'Blog front-controller', '/blog/some-post body');

// ---- Artists routing ----
echo "Test: /artists/artist-slug returns 200 with artists stub content\n";
$resp = fetch("$base/artists/artist-slug");
assert_true($resp['status'] === 200, "/artists/artist-slug status {$resp['status']} (expected 200)");
assert_contains($resp['body'], 'Artists front-controller', '/artists/artist-slug body');

echo "Test: /artists returns 200\n";
$resp = fetch("$base/artists");
assert_true($resp['status'] === 200, "/artists status {$resp['status']} (expected 200)");
assert_contains($resp['body'], 'Artists front-controller', '/artists body');

echo "Test: /artists/ (trailing slash) returns 200\n";
$resp = fetch("$base/artists/");
assert_true($resp['status'] === 200, "/artists/ status {$resp['status']} (expected 200)");
assert_contains($resp['body'], 'Artists front-controller', '/artists/ body');

// ---- Regression: existing pages still work ----
echo "Test: GET / returns 200 with landing page content (regression)\n";
$resp = fetch("$base/");
assert_true($resp['status'] === 200, "/ status {$resp['status']} (expected 200)");
assert_contains($resp['body'], 'VAIF', '/ includes VAIF brand');

echo "Test: GET /index.php returns 200 (regression)\n";
$resp = fetch("$base/index.php");
assert_true($resp['status'] === 200, "/index.php status {$resp['status']} (expected 200)");
assert_contains($resp['body'], 'VAIF', '/index.php body');

echo "Test: GET /calculadora.php returns 200 (regression)\n";
$resp = fetch("$base/calculadora.php");
assert_true($resp['status'] === 200, "/calculadora.php status {$resp['status']} (expected 200)");
assert_contains($resp['body'], 'Lucro Oculto', '/calculadora.php body');

echo "Test: /onboard/nonexistent returns 200 (onboard handles its own routing)\n";
$resp = fetch("$base/onboard/nonexistent");
assert_true($resp['status'] === 200, "/onboard/nonexistent status {$resp['status']} (expected 200)");
assert_contains($resp['body'], '<html', '/onboard/nonexistent renders HTML');

// ---- Static file served directly ----
echo "Test: GET /style.css returns 200 with CSS content type\n";
$resp = fetch("$base/style.css");
assert_true($resp['status'] === 200, "/style.css status {$resp['status']} (expected 200)");
assert_true(
    str_contains($resp['content_type'], 'text/css'),
    "/style.css content-type '{$resp['content_type']}' (expected text/css)"
);

// ---- 404 for unknown paths ----
echo "Test: GET /nonexistent-page returns 404\n";
$resp = fetch("$base/nonexistent-page");
assert_true($resp['status'] === 404, "/nonexistent-page status {$resp['status']} (expected 404)");

// ---- Cleanup ----
if ($pid) { exec("kill $pid 2>/dev/null"); }

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
