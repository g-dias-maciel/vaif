#!/usr/bin/env php
<?php

/**
 * Acceptance test: the artist page must not break on small phones (320px).
 *
 * The Instagram reel embed used to be forced to a hard 326px width, which is
 * wider than a 320px viewport and caused horizontal overflow. This locks the
 * responsive contract: embeds are capped with max-width, never forced wider
 * than their container, and gutters shrink on very small screens.
 *
 * Run: php tests/artist_mobile_acceptance_test.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$serverPort = 9879;

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

function fetch_css(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return $body;
}

$docroot = dirname(__DIR__);
$pid = (int) trim((string) shell_exec(sprintf(
    'php -S localhost:%d -t %s %s > /dev/null 2>&1 & echo $!',
    $serverPort,
    escapeshellarg($docroot),
    escapeshellarg($docroot . '/router.php')
)));
register_shutdown_function(function () use ($pid) {
    if ($pid) { exec("kill $pid 2>/dev/null"); }
});
sleep(1);

$ch = curl_init("http://localhost:$serverPort/artists/adriano-santos");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
$html = (string) curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$css = fetch_css("http://localhost:$serverPort/artists/artist.css");

echo "=== Artist Mobile Layout Acceptance Tests ===\n\n";

echo "Test: page loads\n";
assert_true($status === 200, "status {$status} (expected 200)");

echo "Test: reel embeds are capped, not forced to a fixed width\n";
assert_contains($css, 'max-width: 326px !important', 'reel max-width cap');
$forcedWidth = preg_match('/(?<!max-)width:\s*326px/', $css) === 1;
assert_true(!$forcedWidth, 'found a forced width: 326px (should be max-width only)');
assert_not_contains($css, 'min-width: 326px !important', 'no forced 326px min-width');

echo "Test: Instagram embeds can shrink below their content width\n";
assert_contains($css, '.instagram-grid > blockquote', 'blockquote reset rule');
assert_true(
    str_contains($css, 'min-width: 0 !important'),
    'embeds must allow min-width: 0 so they can shrink'
);

echo "Test: extra-small phones get tighter gutters\n";
assert_contains($css, 'max-width: 380px', 'very small phone media query');

echo "Test: transitions name their properties (no 'transition: all')\n";
assert_not_contains($css, 'transition: all', 'no transition: all in artist.css');

echo "Test: portfolio is one-per-row on phones\n";
assert_contains($css, '@media (max-width: 600px)', 'phone media query');
assert_contains($css, '.portfolio-grid { grid-template-columns: 1fr; gap: 12px; }', 'single-column portfolio');
assert_not_contains($css, '.portfolio-grid { grid-template-columns: 1fr 1fr', 'no two-column portfolio on small screens');

echo "Test: portfolio items open a lightbox\n";
assert_contains($html, 'id="lightbox"', 'lightbox element');
assert_contains($html, 'class="portfolio-item" data-full=', 'clickable portfolio items with full image');
assert_contains($html, 'id="lightbox-close"', 'lightbox close control');
assert_contains($html, 'id="lightbox-prev"', 'lightbox prev control');
assert_contains($html, 'id="lightbox-next"', 'lightbox next control');

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
