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

if ($n8nDiagnosticoWebhookUrl) {
    $ch = curl_init($n8nDiagnosticoWebhookUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Não trava o site se o n8n oscilar
    curl_exec($ch);
    curl_close($ch);
} else {
    error_log('Warning: N8N_DIAGNOSTICO_WEBHOOK_URL not set');
}

echo json_encode(['success' => true]);
