#!/usr/bin/env php
<?php

/**
 * Acceptance tests: public artist profile feed used by the CRM content workflow.
 *
 * Run: php tests/artist_profile_api_acceptance_test.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$port = 9881;

function assert_true(bool $condition, string $message): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL: {$message}\n";
    }
}

function assert_contains(string $haystack, string $needle, string $message): void
{
    global $pass, $fail;
    if (str_contains($haystack, $needle)) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL: {$message} — not found: '{$needle}'\n";
    }
}

function fetch(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['body' => $body, 'status' => $status];
}

$docroot = dirname(__DIR__);
$pid = (int) trim((string) shell_exec(sprintf(
    'php -S localhost:%d -t %s %s > /dev/null 2>&1 & echo $!',
    $port,
    escapeshellarg($docroot),
    escapeshellarg($docroot . '/router.php')
)));

register_shutdown_function(function () use ($pid): void {
    if ($pid > 0) {
        exec("kill {$pid} 2>/dev/null");
    }
});

sleep(1);
$base = "http://localhost:{$port}";

echo "=== Artist profile API — acceptance tests ===\n\n";

$response = fetch("{$base}/api/artists/profile.php?slug=adriano-santos");
$payload = json_decode($response['body'], true);

echo "Test: profile endpoint returns a published artist\n";
assert_true($response['status'] === 200, "status {$response['status']} (expected 200)");
assert_true(is_array($payload), 'response is valid JSON');
assert_true(($payload['success'] ?? false) === true, 'success flag');
assert_true(($payload['slug'] ?? '') === 'adriano-santos', 'slug');
assert_true(($payload['display_name'] ?? '') === 'Adriano Santos', 'display name');
assert_true(($payload['city'] ?? '') === 'Poços de Caldas', 'city');
assert_true(($payload['state'] ?? '') === 'MG', 'state');
assert_true(str_contains((string) ($payload['landing_page_url'] ?? ''), '/artists/adriano-santos'), 'landing page URL');
assert_true(in_array('Coberturas', $payload['styles'] ?? [], true), 'styles include Coberturas');
assert_true(($payload['specialty'] ?? '') === 'coberturas', 'specialty');
assert_true(is_string($payload['bio_plain'] ?? null) && $payload['bio_plain'] !== '', 'plain-text bio');
assert_true(!empty($payload['portfolio']), 'portfolio list');
assert_true(!empty($payload['before_after']), 'before/after list');
assert_true(!empty($payload['testimonials']), 'testimonials');
assert_true(str_starts_with((string) ($payload['portfolio'][0]['src'] ?? ''), '/artists/adriano-santos/media/'), 'portfolio media URL');
assert_true(str_starts_with((string) ($payload['whatsapp_url'] ?? ''), 'https://wa.me/'), 'WhatsApp URL');

echo "Test: missing and unknown slugs\n";
$missing = fetch("{$base}/api/artists/profile.php");
assert_true($missing['status'] === 422, "missing slug status {$missing['status']}");
$unknown = fetch("{$base}/api/artists/profile.php?slug=nao-existe");
assert_true($unknown['status'] === 404, "unknown slug status {$unknown['status']}");

echo "Test: discovery files expose the artist\n";
$llms = fetch("{$base}/llms.txt");
assert_true($llms['status'] === 200, "llms.txt status {$llms['status']}");
assert_contains($llms['body'], 'Adriano Santos', 'llms.txt lists the artist');

$sitemap = fetch("{$base}/sitemap.xml");
assert_true($sitemap['status'] === 200, "sitemap status {$sitemap['status']}");
assert_contains($sitemap['body'], 'https://vaif.com.br/artists/adriano-santos', 'sitemap lists the artist page');

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
