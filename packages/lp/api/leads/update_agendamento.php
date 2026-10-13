<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');

$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Enhanced Input Validation
if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Dados incompletos.']);
    error_log('Error: Incomplete data in update_agendamento.php');
    exit;
}

if (!isset($data['whatsapp']) || !isset($data['data_agendamento'])) {
    echo json_encode(['success' => false, 'error' => 'Telefone WhatsApp ou data de agendamento faltando.']);
    error_log('Error: Missing whatsapp or data_agendamento in update_agendamento.php');
    exit;
}

// Retrieve environment variables
$host = getenv('DB_HOST');
$dbname = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');
// n8n webhook that registers the confirmed meeting (workflow "Cadastro Lead Calculadora")
$n8nWebhookUrl = getenv('N8N_CALENDAR_WEBHOOK_URL');

try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Save the chosen slot on the most recent lead for this WhatsApp number.
    $sql = "UPDATE leads SET data_agendamento = ? WHERE whatsapp = ? ORDER BY id DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    if (!$stmt->execute([$data['data_agendamento'], $data['whatsapp']])) {
        echo json_encode(['success' => false, 'error' => 'Erro ao atualizar os dados.']);
        error_log('Error: Failed to update lead with whatsapp: ' . $data['whatsapp']);
        exit;
    }

    // 2. Fetch the lead's name + Instagram to enrich the n8n payload.
    $stmtLead = $pdo->prepare("SELECT nome, instagram FROM leads WHERE whatsapp = ? ORDER BY id DESC LIMIT 1");
    $stmtLead->execute([$data['whatsapp']]);
    $leadInfo = $stmtLead->fetch(PDO::FETCH_ASSOC);

    // 3. Forward the booking to n8n so it can create the Notion event.
    if ($n8nWebhookUrl && $leadInfo) {
        $payloadN8n = [
            'nome' => $leadInfo['nome'],
            'whatsapp' => $data['whatsapp'],
            'instagram' => $leadInfo['instagram'],
            'data_agendamento' => $data['data_agendamento'],
        ];

        $ch = curl_init($n8nWebhookUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadN8n));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            error_log("Error: n8n calendar webhook failed (http=$httpCode): " . ($curlError ?: $response));
        }
    } elseif ($n8nWebhookUrl && !$leadInfo) {
        error_log('Warning: no lead found for whatsapp ' . $data['whatsapp'] . ' — skipping n8n calendar webhook.');
    }

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erro Real: ' . $e->getMessage()]);
}
