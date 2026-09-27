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
