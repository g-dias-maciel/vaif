<?php
declare(strict_types=1);

/**
 * Normalization for the before/after block's frame options. Values come from
 * artist config, so anything unexpected must fall back to safe defaults rather
 * than leaking into inline CSS.
 *
 * Run: php tests/artists/before_after.test.php
 */

require_once __DIR__ . '/../../lib/artists/before_after.php';

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

echo "=== before/after frame option tests ===\n";

test('Canonical spacing added', normalize_ba_aspect('3/4') === '3 / 4');
test('Existing spacing preserved', normalize_ba_aspect('3 / 4') === '3 / 4');
test('Wide ratio', normalize_ba_aspect('16/9') === '16 / 9');
test('Decimal ratio', normalize_ba_aspect('4.5/9') === '4.5 / 9');
test('Empty → default', normalize_ba_aspect('') === '4 / 5');
test('Non-string → default', normalize_ba_aspect(['3/4']) === '4 / 5');
test('Garbage → default', normalize_ba_aspect('tall') === '4 / 5');
test('Zero denominator → default', normalize_ba_aspect('3/0') === '4 / 5');
test('Zero numerator → default', normalize_ba_aspect('0/4') === '4 / 5');
test('CSS injection blocked', normalize_ba_aspect('3 / 4; background:red') === '4 / 5');
test('Path traversal blocked', normalize_ba_aspect('../../etc') === '4 / 5');

test('cover passes through', normalize_ba_fit('cover') === 'cover');
test('contain passes through', normalize_ba_fit('contain') === 'contain');
test('Case-insensitive', normalize_ba_fit('Cover') === 'cover');
test('Empty → contain', normalize_ba_fit('') === 'contain');
test('Unknown → contain', normalize_ba_fit('stretch') === 'contain');
test('Non-string → contain', normalize_ba_fit(['cover']) === 'contain');

echo "\n" . str_repeat('═', 50) . "\n";
echo "  Results: {$passed} passed, {$failed} failed\n";
echo str_repeat('═', 50) . "\n\n";

exit($failed > 0 ? 1 : 0);
