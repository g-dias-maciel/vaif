<?php
declare(strict_types=1);

/**
 * Specialty-gated blocks (e.g. the cover-up "antes e depois" section) are
 * available only to artists that explicitly declare a `specialty` AND supply
 * the block's items. This keeps specialty sections off every other artist.
 *
 * Run: php tests/artists/specialty.test.php
 */

require_once __DIR__ . '/../../lib/artists/specialty.php';

$passed = 0;
$failed = 0;

function test(string $label, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "✅ PASS: {$label}\n";
    } else {
        $failed++;
        $msg = $detail ? " — {$detail}" : '';
        echo "❌ FAIL: {$label}{$msg}\n";
    }
}

function item(string $before = 'a.jpg', string $after = 'b.jpg'): array {
    return ['before' => $before, 'after' => $after];
}

echo "=== artist specialty gate tests ===\n";

// ── specialty flag ────────────────────────────────────────
test('No specialty → empty', artist_specialty([]) === '');
test('Empty specialty → empty', artist_specialty(['specialty' => '']) === '');
test('Whitespace specialty → empty', artist_specialty(['specialty' => '   ']) === '');
test('Declared specialty is returned trimmed', artist_specialty(['specialty' => ' coberturas ']) === 'coberturas');
test('Non-string specialty ignored', artist_specialty(['specialty' => ['coberturas']]) === '');

test('No specialty → has_specialty false', artist_has_specialty([]) === false);
test('Declared specialty → has_specialty true', artist_has_specialty(['specialty' => 'coberturas']) === true);

// ── before/after gate ─────────────────────────────────────
test('No specialty → block hidden even with items',
    artist_show_before_after(['before_after' => ['items' => [item()]]]) === false);

test('Specialty but no items → block hidden',
    artist_show_before_after(['specialty' => 'coberturas']) === false);

test('Specialty but empty items → block hidden',
    artist_show_before_after(['specialty' => 'coberturas', 'before_after' => ['items' => []]]) === false);

test('Specialty but item missing images → block hidden',
    artist_show_before_after(['specialty' => 'coberturas', 'before_after' => ['items' => [['title' => 'x']]]]) === false);

test('Specialty + at least one complete item → block shown',
    artist_show_before_after(['specialty' => 'coberturas', 'before_after' => ['items' => [item()]]]) === true);

test('Specialty + a mix where one item is complete → block shown',
    artist_show_before_after(['specialty' => 'coberturas', 'before_after' => ['items' => [
        ['title' => 'incomplete'],
        item(),
    ]]]) === true);

test('Other specialty still allowed if declared explicitly',
    artist_show_before_after(['specialty' => 'realismo', 'before_after' => ['items' => [item()]]]) === true);

echo "\n" . str_repeat('═', 50) . "\n";
echo "  Results: {$passed} passed, {$failed} failed\n";
echo str_repeat('═', 50) . "\n\n";

exit($failed > 0 ? 1 : 0);
