<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
$expectedKey = getenv('API_CREATE_KEY');

if (!$apiKey) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'API key obrigatória.']);
    error_log('[artists/create] 401: Missing API key');
    exit;
}

if ($apiKey !== $expectedKey) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'API key inválida.']);
    error_log('[artists/create] 401: Invalid API key');
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if ($data === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Corpo da requisição inválido.']);
    error_log('[artists/create] 422: Invalid JSON body');
    exit;
}

$missing = [];
foreach (['slug', 'display_name', 'whatsapp_number'] as $field) {
    if (empty($data[$field])) {
        $missing[] = $field;
    }
}

if (!empty($missing)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Campos obrigatórios: slug, display_name, whatsapp_number']);
    error_log('[artists/create] 422: Missing required fields: ' . implode(', ', $missing));
    exit;
}

if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $data['slug'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Slug inválido. Use apenas letras minúsculas, números e hifens.']);
    error_log('[artists/create] 422: Invalid slug format: ' . $data['slug']);
    exit;
}

$baseDir = __DIR__ . '/../../artists';
$configDir = $baseDir . '/config';
$slug = $data['slug'];
$configPath = $configDir . '/' . $slug . '.php';

if (file_exists($configPath)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Artista com este slug já existe.']);
    error_log('[artists/create] 409: Slug already exists: ' . $slug);
    exit;
}

if (empty($data['hero_headline'])) {
    $data['hero_headline'] = $data['display_name'];
}

if (empty($data['hero_subheadline'])) {
    if (!empty($data['style'])) {
        $data['hero_subheadline'] = 'Tatuador ' . $data['style'];
    } else {
        $data['hero_subheadline'] = 'Tatuador profissional';
    }
}

if (!is_dir($configDir) && !mkdir($configDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao criar diretório do artista.']);
    error_log('[artists/create] 500: Failed to create config directory');
    exit;
}

$mediaDir = $baseDir . '/' . $slug . '/media';
if (!is_dir($mediaDir) && !mkdir($mediaDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao criar diretório do artista.']);
    error_log('[artists/create] 500: Failed to create media directory: ' . $slug);
    exit;
}

$configContent = '<?php' . "\n\n" . 'return ' . var_export($data, true) . ';' . "\n";
$tmpPath = $configPath . '.tmp';

if (file_put_contents($tmpPath, $configContent) === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao salvar configuração do artista.']);
    error_log('[artists/create] 500: Failed to write temp config: ' . $slug);
    exit;
}

if (!rename($tmpPath, $configPath)) {
    @unlink($tmpPath);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao salvar configuração do artista.']);
    error_log('[artists/create] 500: Failed to rename config: ' . $slug);
    exit;
}

http_response_code(200);
echo json_encode(['success' => true, 'url' => '/artists/' . $slug]);
