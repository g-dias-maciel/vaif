#!/usr/bin/env php
<?php

/**
 * Acceptance tests: artist page SEO + AI discovery.
 *
 * Covers the structured data that drives local search and AI recommendations
 * (TattooParlor with geo/hours/rating/reviews), the enriched Person schema,
 * meta description, robots.txt policy and llms.txt.
 *
 * Run: php tests/artist_seo_acceptance_test.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$serverPort = 9880;

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
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'status' => $code];
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
$base = "http://localhost:$serverPort";

$resp = fetch("$base/artists/adriano-santos");
$html = $resp['body'];

echo "=== Artist SEO / AI Discovery Acceptance Tests ===\n\n";

echo "Test: page loads\n";
assert_true($resp['status'] === 200, "status {$resp['status']} (expected 200)");

echo "Test: structured data covers person, studio, FAQ and breadcrumbs\n";
assert_contains($html, '"@type":"Person"', 'Person schema');
assert_contains($html, '"@type":"TattooParlor"', 'TattooParlor schema');
assert_contains($html, '"@type":"FAQPage"', 'FAQPage schema');
assert_contains($html, '"@type":"BreadcrumbList"', 'BreadcrumbList schema');

echo "Test: local-search fields present\n";
assert_contains($html, '"addressLocality":"Poços de Caldas"', 'address locality');
assert_contains($html, '"geo"', 'geo coordinates');
assert_contains($html, '"latitude":-21.7912641', 'latitude');
assert_contains($html, '"openingHoursSpecification"', 'opening hours');
assert_contains($html, '"areaServed":"Poços de Caldas"', 'areaServed');
assert_contains($html, '"knowsAbout"', 'knowsAbout specialties');
assert_contains($html, '"telephone":"+553599968249"', 'telephone');

echo "Test: ratings + reviews are exposed\n";
assert_contains($html, '"aggregateRating"', 'aggregateRating');
assert_contains($html, '"ratingValue":"4.95"', 'rating value from config');
assert_contains($html, '"@type":"Review"', 'review markup');

echo "Test: every JSON-LD block is valid JSON\n";
$valid = true;
if (preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m)) {
    foreach ($m[1] as $json) {
        if (json_decode(trim($json), true) === null) { $valid = false; }
    }
}
assert_true($valid, 'one or more JSON-LD blocks failed to parse');

echo "Test: meta description targets the city and specialties\n";
assert_contains($html, 'Poços de Caldas', 'city in page');
assert_contains($html, 'Coberturas', 'cover-ups mentioned');

echo "Test: pricing question removed from the FAQ\n";
assert_not_contains($html, 'Qual o valor médio', 'pricing FAQ removed');
assert_contains($html, 'aria-controls="faq-answer', 'FAQ still rendered');

echo "Test: robots.txt welcomes AI crawlers\n";
$robots = fetch("$base/robots.txt")['body'];
assert_true(preg_match('/User-agent: GPTBot\s+Allow: \//', $robots) === 1, 'GPTBot allowed');
assert_true(preg_match('/User-agent: OAI-SearchBot\s+Allow: \//', $robots) === 1, 'OAI-SearchBot allowed');

echo "Test: llms.txt is published for AI assistants\n";
$llms = fetch("$base/llms.txt");
assert_true($llms['status'] === 200, "llms.txt status {$llms['status']}");
assert_contains($llms['body'], 'Adriano Santos', 'llms.txt lists the artist');
assert_contains($llms['body'], 'Poços de Caldas', 'llms.txt mentions the city');

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
