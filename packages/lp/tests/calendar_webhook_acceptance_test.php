#!/usr/bin/env php
<?php
/**
 * Acceptance test: confirming a calendar booking forwards the lead to the n8n
 * "agendamento-confirmado" webhook (N8N_CALENDAR_WEBHOOK_URL).
 *
 * Boots three throwaway servers:
 *   - an ephemeral MySQL (userspace; skipped when mysqld is unavailable)
 *   - a mock of the n8n agendamento-confirmado webhook
 *   - the LP app, wired to both
 *
 * Then POSTs to /api/leads/update_agendamento.php and asserts the lead row was
 * updated AND the webhook received the lead's name, WhatsApp, Instagram and the
 * chosen slot — the payload the n8n "Cadastro Lead Calculadora" workflow reads.
 *
 * Run: php tests/calendar_webhook_acceptance_test.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;

function assert_true(bool $cond, string $msg): void {
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "  FAIL: $msg\n"; }
}

function free_port(): int {
    $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

function post_json(string $url, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'status' => $status];
}

$docroot = dirname(__DIR__);
$tmp = rtrim(sys_get_temp_dir(), '/') . '/vaif_cal_accept_' . getmypid();
$dbDir = "$tmp/mysql";
$dbPort = free_port();
$lpPort = free_port();
$mockPort = free_port();
$mockState = "$tmp/webhook.json";

$pids = [];
register_shutdown_function(function () use (&$pids, $tmp) {
    foreach ($pids as $pid) {
        if ($pid) { exec("kill $pid 2>/dev/null"); }
    }
    exec('rm -rf ' . escapeshellarg($tmp) . ' 2>/dev/null');
});

// --- 0. Require mysqld (skip gracefully when absent) ---
$mysqld = trim((string) shell_exec('command -v mysqld 2>/dev/null'));
if ($mysqld === '') {
    echo "SKIP: mysqld not found — skipping calendar webhook acceptance test\n";
    exit(0);
}

mkdir($dbDir, 0777, true);
$basedir = trim((string) shell_exec('dirname "$(dirname "$(command -v mysqld)")" 2>/dev/null'));
if ($basedir === '' || !is_file("$basedir/bin/mysqld")) { $basedir = '/usr'; }

// --- 1. Initialize + start ephemeral MySQL ---
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

// Wait for MySQL to accept connections (root has an empty password after --initialize-insecure).
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
$pdo->exec('CREATE TABLE leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255),
    whatsapp VARCHAR(20),
    instagram VARCHAR(255),
    faturamento DECIMAL(12,2),
    ticket DECIMAL(12,2),
    sessoes INT,
    horas_admin DECIMAL(6,2),
    valor_hora DECIMAL(12,2),
    horas_secretario DECIMAL(8,2),
    prejuizo_mensal DECIMAL(12,2),
    potencial_lucro DECIMAL(12,2),
    data_agendamento DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)');

$wa = '(21) 98888-7777';
$pdo->prepare('INSERT INTO leads (nome, whatsapp, instagram) VALUES (?, ?, ?)')
    ->execute(['Maria Teste', $wa, 'mariateste']);

// --- 2. Start the mock webhook ---
$pids['mock'] = (int) trim((string) shell_exec(sprintf(
    'CALENDAR_MOCK_STATE_FILE=%s php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!',
    escapeshellarg($mockState), $mockPort, escapeshellarg(__DIR__ . '/fixtures/calendar_webhook_mock.php')
)));

// --- 3. Start the LP app wired to both ---
$dbHost = "127.0.0.1;port=$dbPort"; // DSN injection: endpoint concatenates host;dbname
$pids['lp'] = (int) trim((string) shell_exec(sprintf(
    'DB_HOST=%s DB_NAME=vaif_lp_test DB_USER=%s DB_PASSWORD=%s '
    . 'N8N_CALENDAR_WEBHOOK_URL=%s php -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!',
    escapeshellarg($dbHost), escapeshellarg($dbUser), escapeshellarg($dbPass),
    escapeshellarg("http://127.0.0.1:$mockPort/"),
    $lpPort, escapeshellarg($docroot), escapeshellarg("$docroot/router.php")
)));

sleep(2);

echo "=== Calendar Webhook Acceptance Test ===\n\n";

// --- 4. Confirm a booking ---
$slot = '2026-10-15 14:00:00';
$resp = post_json("http://127.0.0.1:$lpPort/api/leads/update_agendamento.php", [
    'whatsapp' => $wa,
    'data_agendamento' => $slot,
]);

echo "Test: endpoint returns success\n";
$decoded = json_decode($resp['body'], true);
assert_true(($decoded['success'] ?? false) === true, "response success true (got: {$resp['body']})");
assert_true(!str_contains($resp['body'], 'Fatal error'), 'no fatal errors in response');

echo "Test: lead slot persists in the database\n";
$stored = $pdo->query('SELECT data_agendamento FROM leads WHERE whatsapp = ' . $pdo->quote($wa) . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
assert_true((string) $stored === $slot, "data_agendamento stored '$slot' (got '$stored')");

echo "Test: n8n webhook received the booking payload\n";
$raw = @file_get_contents($mockState);
assert_true($raw !== false && $raw !== '', 'webhook was called');
$hook = json_decode((string) $raw, true) ?: [];
assert_true(($hook['nome'] ?? null) === 'Maria Teste', 'payload has nome');
assert_true(($hook['whatsapp'] ?? null) === $wa, 'payload has whatsapp');
assert_true(($hook['instagram'] ?? null) === 'mariateste', 'payload has instagram');
assert_true(($hook['data_agendamento'] ?? null) === $slot, 'payload has data_agendamento');

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
