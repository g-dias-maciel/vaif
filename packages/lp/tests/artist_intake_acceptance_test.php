#!/usr/bin/env php
<?php

/**
 * Acceptance test: artist intake → draft → review → publish workflow,
 * including the invite-token gate.
 *
 * Boots an ephemeral MySQL (skipped when mysqld is unavailable) and the LP app
 * wired to it, then verifies:
 *   - /novo-artista without a valid token renders an invalid-link page
 *   - a seeded invite token lets the form load and submit (with an image)
 *   - the token is single-use (a second submit is rejected)
 *   - the draft is hidden publicly, visible via ?preview=<token>
 *   - the admin panel can mint a new invite link, list the draft, and publish it
 *   - after publish, /artists/<slug> is public and listed in the sitemap
 *
 * Run: php tests/artist_intake_acceptance_test.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;

function assert_true(bool $cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "  FAIL: $msg\n"; }
}

function assert_contains(string $haystack, string $needle, string $msg): void
{
    global $pass, $fail;
    if (str_contains($haystack, $needle)) { $pass++; } else {
        $fail++; echo "  FAIL: $msg — not found: '$needle'\n";
    }
}

function free_port(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

function fetch(string $url): array
{
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

function post_fields(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'status' => $status];
}

$docroot = dirname(__DIR__);
$tmp = rtrim(sys_get_temp_dir(), '/') . '/vaif_intake_' . getmypid();
$dbDir = "$tmp/mysql";
$dbPort = free_port();
$lpPort = free_port();

$pids = [];
register_shutdown_function(function () use (&$pids, $tmp, $docroot) {
    foreach ($pids as $pid) {
        if ($pid) { exec("kill $pid 2>/dev/null"); }
    }
    exec('rm -rf ' . escapeshellarg($tmp) . ' 2>/dev/null');
    exec('rm -rf ' . escapeshellarg($docroot . '/artists/teste-artist') . ' 2>/dev/null');
});

$mysqld = trim((string) shell_exec('command -v mysqld 2>/dev/null'));
if ($mysqld === '') {
    echo "SKIP: mysqld not found — skipping artist intake acceptance test\n";
    exit(0);
}

mkdir($dbDir, 0777, true);
$basedir = trim((string) shell_exec('dirname "$(dirname "$(command -v mysqld)")" 2>/dev/null'));
if ($basedir === '' || !is_file("$basedir/bin/mysqld")) { $basedir = '/usr'; }

exec(sprintf(
    'mysqld --initialize-insecure --datadir=%s --basedir=%s --log-error=%s > /dev/null 2>&1',
    escapeshellarg($dbDir), escapeshellarg($basedir), escapeshellarg("$tmp/init.log")
), $o, $initRc);
if ($initRc !== 0) {
    echo "SKIP: could not initialize ephemeral MySQL (rc=$initRc)\n";
    exit(0);
}

$pids['mysql'] = (int) trim((string) shell_exec(sprintf(
    'mysqld --datadir=%s --basedir=%s --log-error=%s --port=%d --bind-address=127.0.0.1 '
    . '--socket=%s --pid-file=%s --mysqlx=0 > /dev/null 2>&1 & echo $!',
    escapeshellarg($dbDir), escapeshellarg($basedir), escapeshellarg("$tmp/mysql.log"),
    $dbPort, escapeshellarg("$tmp/mysql.sock"), escapeshellarg("$tmp/mysql.pid")
)));

$pdo = null;
for ($i = 0; $i < 40; $i++) {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;port=$dbPort;charset=utf8mb4", 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2,
        ]);
        break;
    } catch (PDOException $e) {
        usleep(500000);
    }
}
if (!$pdo) {
    echo "SKIP: ephemeral MySQL did not come up — skipping\n";
    exit(0);
}

$dbUser = 'vaif';
$dbPass = 'VaifTest123!';
$pdo->exec('CREATE DATABASE IF NOT EXISTS vaif_lp_test CHARACTER SET utf8mb4');
$pdo->exec("CREATE USER IF NOT EXISTS '$dbUser'@'%' IDENTIFIED BY '$dbPass'");
$pdo->exec("GRANT ALL ON vaif_lp_test.* TO '$dbUser'@'%'");
$pdo->exec('USE vaif_lp_test');

$adminToken = 'test-admin-token-123';
$dbHost = "127.0.0.1;port=$dbPort";

$pids['lp'] = (int) trim((string) shell_exec(sprintf(
    'DB_HOST=%s DB_NAME=vaif_lp_test DB_USER=%s DB_PASSWORD=%s ARTISTS_ADMIN_TOKEN=%s '
    . 'php -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!',
    escapeshellarg($dbHost), escapeshellarg($dbUser), escapeshellarg($dbPass),
    escapeshellarg($adminToken),
    $lpPort, escapeshellarg($docroot), escapeshellarg("$docroot/router.php")
)));

sleep(2);
$base = "http://127.0.0.1:$lpPort";

echo "=== Artist Intake Acceptance Test ===\n\n";

// ---- Intake form requires a valid token ----
echo "Test: /novo-artista without a token shows invalid-link state\n";
$resp = fetch("$base/novo-artista");
assert_contains($resp['body'], 'Link inválido', '/novo-artista (no token) shows invalid-link state');

echo "Test: /novo-artista with an unknown token shows invalid-link state\n";
$resp = fetch("$base/novo-artista/not-a-real-token");
assert_contains($resp['body'], 'Link inválido', '/novo-artista/not-a-real-token shows invalid-link state');

// ---- Seed an invite token + verify the form loads ----
echo "Test: a valid invite token renders the intake form\n";
$inviteToken = 'invite-test-token-123';
$pdo->prepare('INSERT INTO intake_tokens (token, label) VALUES (?, ?)')->execute([$inviteToken, 'Teste']);

$resp = fetch("$base/novo-artista/$inviteToken");
assert_true($resp['status'] === 200, "/novo-artista/$inviteToken status {$resp['status']} (expected 200)");
assert_contains($resp['body'], 'intake-form', 'form renders with valid token');

// ---- Create a draft via multipart POST (with token + image) ----
echo "Test: POST /api/artists/intake.php creates a draft\n";
$imgPath = "$tmp/tattoo.png";
file_put_contents($imgPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

$ch = curl_init("$base/api/artists/intake.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_POSTFIELDS => [
        'mode' => 'create',
        'intake_token' => $inviteToken,
        'display_name' => 'Teste Artist',
        'instagram_handle' => 'testeartist',
        'whatsapp_number' => '5511999999999',
        'style' => 'Realismo, Coberturas',
        'city' => 'Poços de Caldas',
        'state' => 'MG',
        'has_coberturas' => '1',
        'bio' => "Linha um\n\nLinha dois",
        'profile_photo' => new CURLFile($imgPath, 'image/png', 'tattoo.png'),
    ],
]);
$body = (string) curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

assert_true($status === 200, "create status $status (expected 200)");
$decoded = json_decode($body, true);
assert_true(is_array($decoded) && ($decoded['success'] ?? false) === true, "create success (got: $body)");
assert_true(($decoded['slug'] ?? '') === 'teste-artist', "slug is 'teste-artist' (got: " . ($decoded['slug'] ?? '') . ")");

$slug = (string) ($decoded['slug'] ?? '');
$previewUrl = (string) ($decoded['preview_url'] ?? '');
$artistId = (int) ($decoded['id'] ?? 0);

// ---- Token is single-use ----
echo "Test: the invite token is single-use\n";
$resp = post_fields("$base/api/artists/intake.php", [
    'mode' => 'create',
    'intake_token' => $inviteToken,
    'display_name' => 'Segundo Artista',
    'whatsapp_number' => '5511888888888',
]);
assert_true($resp['status'] === 403, "reused token status {$resp['status']} (expected 403)");

echo "Test: POST without a token is rejected\n";
$resp = post_fields("$base/api/artists/intake.php", [
    'mode' => 'create',
    'display_name' => 'Sem Token',
    'whatsapp_number' => '5511888888888',
]);
assert_true($resp['status'] === 403, "no-token create status {$resp['status']} (expected 403)");

// ---- Draft is hidden without a preview token ----
echo "Test: draft is NOT publicly visible (404)\n";
$resp = fetch("$base/artists/$slug");
assert_true($resp['status'] === 404, "draft /artists/$slug status {$resp['status']} (expected 404)");

// ---- Draft is visible with the preview token ----
echo "Test: draft preview is visible with ?preview=<token>\n";
$resp = fetch($base . $previewUrl);
assert_true($resp['status'] === 200, "preview status {$resp['status']} (expected 200)");
assert_contains($resp['body'], 'Teste Artist', 'preview shows display name');

// ---- Uploaded image is persisted + referenced ----
echo "Test: uploaded photo is stored and referenced\n";
$heroPath = "$docroot/artists/$slug/media/hero.png";
assert_true(is_file($heroPath), "hero image exists on disk at $heroPath");
$resp = fetch($base . $previewUrl);
assert_contains($resp['body'], "/artists/$slug/media/hero.png", 'preview references the uploaded hero');

// ---- Admin panel lists the draft + can mint a new invite link ----
echo "Test: admin panel lists the draft artist\n";
$resp = fetch("$base/artistas-admin/$adminToken");
assert_true($resp['status'] === 200, "admin list status {$resp['status']} (expected 200)");
assert_contains($resp['body'], 'Teste Artist', 'admin list shows artist name');
assert_contains($resp['body'], 'Rascunho', 'admin list shows draft badge');

echo "Test: admin panel can generate a new invite link\n";
$ch = curl_init("$base/artistas-admin/$adminToken");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_POSTFIELDS => ['action' => 'generate_token', 'token_label' => 'Novo artista'],
    CURLOPT_TIMEOUT => 10,
]);
curl_exec($ch);
$redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
curl_close($ch);
assert_true(str_contains($redirect, 'new_token='), "generate_token redirects with new_token (got: $redirect)");

// ---- Admin rejects missing/invalid token ----
echo "Test: admin panel rejects invalid token\n";
$resp = fetch("$base/artistas-admin");
assert_contains($resp['body'], 'Link inválido', '/artistas-admin (no token) shows invalid-link state');
$resp = fetch("$base/artistas-admin/wrong-token");
assert_contains($resp['body'], 'Link inválido', '/artistas-admin/wrong-token shows invalid-link state');

// ---- Publish ----
echo "Test: publishing makes the page public\n";
$ch = curl_init("$base/artistas-admin/$adminToken/$artistId");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_POSTFIELDS => ['action' => 'publish'],
    CURLOPT_TIMEOUT => 10,
]);
curl_exec($ch);
curl_close($ch);

$resp = fetch("$base/artists/$slug");
assert_true($resp['status'] === 200, "published /artists/$slug status {$resp['status']} (expected 200)");
assert_contains($resp['body'], 'Teste Artist', 'published page shows display name');

// ---- Published artists appear in the sitemap ----
echo "Test: published artist appears in sitemap.xml\n";
$resp = fetch("$base/sitemap.php");
assert_contains($resp['body'], "/artists/$slug", 'sitemap includes the published artist');

// ---- Cleanup ----
if ($pids['lp']) { exec("kill {$pids['lp']} 2>/dev/null"); }
if ($pids['mysql']) { exec("kill {$pids['mysql']} 2>/dev/null"); }

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
