<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Nenhum dado recebido.']);
    error_log('Error: No data received in diagnostico.php');
    exit;
}

// Validar campos obrigatórios do diagnóstico gratuito
$nome     = trim($data['nome'] ?? '');
$whatsapp = trim($data['whatsapp'] ?? '');
$email    = trim($data['email'] ?? '');

if ($nome === '' || $whatsapp === '' || $email === '') {
    echo json_encode(['success' => false, 'error' => 'Nome, WhatsApp e e-mail são obrigatórios.']);
    exit;
}

// 1. Persistir o lead de diagnóstico na mesma tabela `leads` da calculadora.
//    Não bloqueia o fluxo: se o banco falhar, o n8n ainda entrega o diagnóstico.
$host = getenv('DB_HOST');
$dbname = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');

if ($host && $dbname && $user && $pass) {
    try {
        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $faturamento = (isset($data['faturamento']) && is_numeric($data['faturamento'])) ? $data['faturamento'] : null;
        $ticket      = (isset($data['ticket']) && is_numeric($data['ticket'])) ? $data['ticket'] : null;
        $sessoes     = ($ticket && $ticket > 0 && $faturamento !== null) ? (int) round($faturamento / $ticket) : null;

        $sql = "INSERT INTO leads (nome, whatsapp, instagram, email, studio, faturamento, ticket, sessoes, origem)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $nome,
            $whatsapp,
            $data['instagram'] ?? null,
            $email,
            $data['studio'] ?? null,
            $faturamento,
            $ticket,
            $sessoes,
            $data['origem'] ?? 'diagnostico'
        ]);
    } catch (PDOException $e) {
        error_log('Error: Failed to insert diagnostic lead: ' . $e->getMessage());
    }
} else {
    error_log('Warning: DB env vars not set, diagnostic lead not persisted');
}

$n8nDiagnosticoWebhookUrl = getenv('N8N_DIAGNOSTICO_WEBHOOK_URL');

if (!$n8nDiagnosticoWebhookUrl) {
    error_log('Warning: N8N_DIAGNOSTICO_WEBHOOK_URL not set');
    echo json_encode(['success' => false, 'error' => 'N8N_DIAGNOSTICO_WEBHOOK_URL not set']);
    exit;
}

$ch = curl_init($n8nDiagnosticoWebhookUrl);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Não trava o site se o n8n oscilar
$n8nResponse = curl_exec($ch);
$curlError   = curl_error($ch);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($n8nResponse === false) {
    echo json_encode(['success' => false, 'error' => 'Falha ao notificar n8n: ' . $curlError]);
    exit;
}

if ($httpCode >= 400) {
    error_log("Warning: n8n diagnostico webhook returned HTTP {$httpCode}");
}

echo json_encode(['success' => true]);
