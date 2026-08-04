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
// 👇 ADICIONE ESTA VARIÁVEL NO SEU COOLIFY (URL DO WEBHOOK DO N8N)
$n8nWebhookUrl = getenv('N8N_CALENDAR_WEBHOOK_URL');

try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Function to Update Lead
    function updateLead($pdo, $data) {
        $sql = "UPDATE leads SET data_agendamento = ? WHERE whatsapp = ? ORDER BY id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$data['data_agendamento'], $data['whatsapp']]);
    }

    // Attempt to update the lead
    if (!updateLead($pdo, $data)) {
        echo json_encode(['success' => false, 'error' => 'Erro ao atualizar os dados.']);
        error_log('Error: Failed to update lead with whatsapp: ' . $data['whatsapp']);
        exit;
    }

    // Send to N8N Webhook (Asynchronous)
    if ($n8nWebhookUrl) {
        // Add your logic for sending to n8n after checking availability (if needed)
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erro Real: ' . $e->getMessage()]);
}
?>