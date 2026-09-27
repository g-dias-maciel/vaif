<?php
declare(strict_types=1);

/**
 * Artist media URL resolution: hero/bio photos can be local files (resolved
 * under artists/<slug>/media/) or absolute URLs, and fall back to
 * profile_photo while a local file has not been uploaded yet.
 *
 * Run: php tests/artists/media.test.php
 */

require_once __DIR__ . '/../../lib/artists/media.php';

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

echo "=== artist media tests ===\n";

// ── artist_media_url ──────────────────────────────────────
test('http URL passes through', artist_media_url('http://x/y.jpg', 's') === 'http://x/y.jpg');
test('https URL passes through', artist_media_url('https://x/y.jpg', 's') === 'https://x/y.jpg');
test('protocol-relative passes through', artist_media_url('//cdn/x.jpg', 's') === '//cdn/x.jpg');
test('root-absolute path passes through', artist_media_url('/already/abs.jpg', 's') === '/already/abs.jpg');
test('bare filename maps to media dir', artist_media_url('local.jpg', 's') === '/artists/s/media/local.jpg');
test('empty stays empty', artist_media_url('', 's') === '');

// ── Temp media dir for existence checks ───────────────────
$dir = sys_get_temp_dir() . '/vaif_media_' . uniqid('', true);
mkdir($dir);
file_put_contents($dir . '/hero.jpg', 'x');
file_put_contents($dir . '/about.jpg', 'x');
$remote = 'https://images.example.com/profile.jpg';

// ── artist_preferred_photo ────────────────────────────────
test('No key, no profile → empty', artist_preferred_photo([], 'hero_photo', 's', $dir) === '');

test('No key → falls back to profile_photo (remote)',
    artist_preferred_photo(['profile_photo' => $remote], 'hero_photo', 's', $dir) === $remote);

test('Key with existing local file → local media URL',
    artist_preferred_photo(['hero_photo' => 'hero.jpg', 'profile_photo' => $remote], 'hero_photo', 's', $dir) === '/artists/s/media/hero.jpg');

test('Key with missing local file → falls back to profile_photo',
    artist_preferred_photo(['hero_photo' => 'missing.jpg', 'profile_photo' => $remote], 'hero_photo', 's', $dir) === $remote);

test('Key absent but profile local + exists → local profile URL',
    artist_preferred_photo(['profile_photo' => 'about.jpg'], 'hero_photo', 's', $dir) === '/artists/s/media/about.jpg');

test('No media dir → trusts the key without touching the filesystem',
    artist_preferred_photo(['hero_photo' => 'hero.jpg', 'profile_photo' => $remote], 'hero_photo', 's', '') === '/artists/s/media/hero.jpg');

test('Absolute key wins over profile',
    artist_preferred_photo(['about_photo' => '/abs/about.jpg', 'profile_photo' => $remote], 'about_photo', 's', $dir) === '/abs/about.jpg');

test('Remote key wins over profile',
    artist_preferred_photo(['about_photo' => 'https://x/a.jpg', 'profile_photo' => $remote], 'about_photo', 's', $dir) === 'https://x/a.jpg');

// cleanup
array_map('unlink', glob($dir . '/*'));
rmdir($dir);

echo "\n" . str_repeat('═', 50) . "\n";
echo "  Results: {$passed} passed, {$failed} failed\n";
echo str_repeat('═', 50) . "\n\n";

exit($failed > 0 ? 1 : 0);
