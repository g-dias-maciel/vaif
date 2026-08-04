<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Nenhum dado recebido.']);
    error_log('Error: No data received in submit.php');
    exit;
}

// 1. Variáveis de ambiente vindas do Coolify
$host = getenv('DB_HOST');
$dbname = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');
// 👇 Nova variável para o n8n (Substituindo o Make)
$n8nLeadWebhookUrl = getenv('N8N_LEAD_WEBHOOK_URL');

try {
    // 2. Validate Environment Variables
    if (!$host || !$dbname || !$user || !$pass) {
        echo json_encode(['success' => false, 'error' => 'Configurações de banco de dados não estão definidas.']);
        error_log('Error: Database connection details not set');
        exit;
    }
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 3. Function to Insert Lead
    function insertLead($pdo, $data) {
        $sql = "INSERT INTO leads (nome, whatsapp, instagram, faturamento, ticket, sessoes, horas_admin, valor_hora, horas_secretario, prejuizo_mensal, potencial_lucro)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            $data['nome'],
            $data['whatsapp'],
            $data['instagram'],
            $data['faturamento'],
            $data['ticket'],
            $data['sessoes'],
            $data['horas_admin'],
            $data['valor_hora'],
            $data['horas_secretario'],
            $data['prejuizo_mensal'],
            $data['potencial_lucro']
        ]);
    }

    // Attempt to insert the lead
    if (!insertLead($pdo, $data)) {
        echo json_encode(['success' => false, 'error' => 'Erro ao inserir os dados.']);
        error_log('Error: Failed to insert lead into database');
        exit;
    }

    // 4. 👇 Disparar para o n8n INSTANTANEAMENTE se o insert no banco der certo
    if ($n8nLeadWebhookUrl) {
        $ch = curl_init($n8nLeadWebhookUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Não trava o site se o n8n oscilar

curl_exec($ch);
curl_close($ch);
    }

    // 5. Retorna sucesso para o frontend continuar o fluxo (abrir calendário ou e-book)
echo json_encode(['success' => true]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erro Real: ' . $e->getMessage()]);
}
?>