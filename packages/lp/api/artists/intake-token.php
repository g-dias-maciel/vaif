<?php

/**
 * Mint a single-use artist intake link for the CRM.
 *
 * POST /api/artists/intake-token.php
 * X-Api-Key: <LP_INTAKE_API_KEY>
 * {"label": "Adriano Santos"}
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST');

require_once __DIR__ . '/../../lib/artists/store.php';

$expectedKey = getenv('LP_INTAKE_API_KEY');
if ($expectedKey === false || $expectedKey === '') {
    $expectedKey = getenv('API_CREATE_KEY');
}

$providedKey = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');

if ($expectedKey === false || $expectedKey === '' || $providedKey === '' || !hash_equals((string) $expectedKey, $providedKey)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'API key inválida.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Use POST.']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw !== false ? $raw : '', true);
if (!is_array($payload)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'JSON inválido.']);
    exit;
}

$label = trim((string) ($payload['label'] ?? ''));
$label = mb_substr($label, 0, 255);

$pdo = artist_db();
if ($pdo === null) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Banco de dados indisponível.']);
    exit;
}

$token = intake_token_create($pdo, $label);
$base = rtrim((string) (getenv('LP_BASE_URL') ?: 'https://vaif.com.br'), '/');

echo json_encode([
    'success' => true,
    'token' => $token,
    'url' => $base . '/novo-artista/' . rawurlencode($token),
    'label' => $label,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
