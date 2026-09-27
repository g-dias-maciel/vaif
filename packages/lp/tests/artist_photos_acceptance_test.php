#!/usr/bin/env php
<?php

/**
 * Acceptance tests: Adriano's local photos are served as optimized WebP
 * (hero, bio and cover-up before/after), with no leftover local JPG refs.
 *
 * Run: php tests/artist_photos_acceptance_test.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$serverPort = 9878;

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
$cmd = sprintf(
    'php -S localhost:%d -t %s %s > /dev/null 2>&1 & echo $!',
    $serverPort,
    escapeshellarg($docroot),
    escapeshellarg($docroot . '/router.php')
);
$pid = (int) trim((string) shell_exec($cmd));
register_shutdown_function(function () use ($pid) {
    if ($pid) { exec("kill $pid 2>/dev/null"); }
});
sleep(1);
$base = "http://localhost:$serverPort";

echo "=== Artist Photos (WebP) Acceptance Tests ===\n\n";

$resp = fetch("$base/artists/adriano-santos");
$html = $resp['body'];

echo "Test: page loads\n";
assert_true($resp['status'] === 200, "status {$resp['status']} (expected 200)");

echo "Test: hero and bio photos use WebP\n";
assert_contains($html, '/artists/adriano-santos/media/adriano-hero.webp', 'hero webp');
assert_contains($html, '/artists/adriano-santos/media/adriano-bio.webp', 'bio webp');

echo "Test: cover-up before/after use WebP\n";
assert_contains($html, '/artists/adriano-santos/media/cobertura-01-antes.webp', 'cover-up before webp');
assert_contains($html, '/artists/adriano-santos/media/cobertura-01-depois.webp', 'cover-up after webp');

echo "Test: portfolio works are local WebP (no placeholders)\n";
for ($i = 1; $i <= 6; $i++) {
    assert_contains($html, sprintf('/artists/adriano-santos/media/portfolio-%02d.webp', $i), "portfolio-{$i} webp");
}
assert_not_contains($html, 'placehold.co/400x400', 'portfolio placeholders removed');

echo "Test: no leftover local JPG references\n";
$jpgRefs = preg_match_all('#/artists/adriano-santos/media/[^"\']+\.(?:jpe?g|png)#i', $html);
assert_true($jpgRefs === 0, "found {$jpgRefs} local jpg/png reference(s)");

echo "Test: WebP files are served with the right content type\n";
foreach (['adriano-hero.webp', 'cobertura-01-depois.webp', 'portfolio-03.webp'] as $file) {
    $ch = curl_init("$base/artists/adriano-santos/media/$file");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    curl_exec($ch);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    assert_true($code === 200, "$file status $code (expected 200)");
    assert_true(str_contains($type, 'image/webp'), "$file content-type '$type' (expected image/webp)");
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
