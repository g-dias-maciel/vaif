<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/instagram/embed-url.php';

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

echo "=== instagram embed-url tests ===\n";

test('Canonical photo URL unchanged', instagram_embed_url('https://www.instagram.com/p/AbC123xyz/') === 'https://www.instagram.com/p/AbC123xyz/');
test('Canonical reel URL unchanged', instagram_embed_url('https://www.instagram.com/reel/AbC123xyz/') === 'https://www.instagram.com/reel/AbC123xyz/');
test('Profile-scoped reel URL normalized', instagram_embed_url('https://www.instagram.com/studiomadri_tattoo/reel/Db3P0YTx7YF/') === 'https://www.instagram.com/reel/Db3P0YTx7YF/');
test('Profile-scoped photo URL normalized', instagram_embed_url('https://www.instagram.com/joaosilvatattoo/p/AbC123xyz/') === 'https://www.instagram.com/p/AbC123xyz/');
test('Reels path normalized to reel', instagram_embed_url('https://www.instagram.com/studiomadri_tattoo/reels/AbC123xyz/') === 'https://www.instagram.com/reel/AbC123xyz/');
test('IGTV URL normalized', instagram_embed_url('https://www.instagram.com/tv/AbC123xyz/') === 'https://www.instagram.com/tv/AbC123xyz/');
test('Short instagr.am domain normalized', instagram_embed_url('https://instagr.am/p/AbC123xyz/') === 'https://www.instagram.com/p/AbC123xyz/');
test('Query string stripped', instagram_embed_url('https://www.instagram.com/reel/AbC123xyz/?igsh=abc123') === 'https://www.instagram.com/reel/AbC123xyz/');
test('Trailing fragment stripped', instagram_embed_url('https://www.instagram.com/p/AbC123xyz/#footer') === 'https://www.instagram.com/p/AbC123xyz/');
test('Non-Instagram URL unchanged', instagram_embed_url('https://example.com/post/123') === 'https://example.com/post/123');
test('Empty string unchanged', instagram_embed_url('') === '');

echo "\n" . str_repeat('═', 50) . "\n";
echo "  Results: {$passed} passed, {$failed} failed\n";
echo str_repeat('═', 50) . "\n\n";

exit($failed > 0 ? 1 : 0);
