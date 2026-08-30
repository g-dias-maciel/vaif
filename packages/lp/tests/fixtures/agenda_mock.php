<?php
/**
 * Mock of the n8n "Artist Calendar Webhook" (#29) for agenda acceptance tests.
 *
 * Serves /webhook/calendar with the same contract the real workflow exposes:
 *   POST { token, action: list|block|unblock, start_at?, end_at?, block_id? }
 *   - list    -> { success, action, artist_name, timezone, duration_min, available[], blocks[] }
 *   - block   -> { success, action, block: {id,start_at,end_at} }
 *   - unblock -> { success, action, block: {...} } (or block_not_found)
 *   invalid token -> HTTP 401 { success: false, error: 'invalid_token' }
 *
 * State is kept in a temp JSON file so a test can block, re-list, unblock and
 * observe availability leave and return — mirroring the real Postgres-backed
 * behavior without a database.
 */

declare(strict_types=1);

$stateFile = sys_get_temp_dir() . '/vaif_agenda_mock_state.json';

function mock_load_state(): array
{
    global $stateFile;
    $raw = @file_get_contents($stateFile);
    return $raw ? (json_decode($raw, true) ?: []) : [];
}

function mock_save_state(array $state): void
{
    global $stateFile;
    file_put_contents($stateFile, json_encode($state));
}

function mock_respond(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

// Reset hook used by the test harness before each run.
if (($_GET['reset'] ?? '') === '1') {
    mock_save_state([
        'available' => [
            ['id' => 'aaaa1111-0000-0000-0000-000000000001', 'start_at' => '2026-09-10T13:00:00Z', 'end_at' => '2026-09-10T14:00:00Z'],
            ['id' => 'aaaa1111-0000-0000-0000-000000000002', 'start_at' => '2026-09-11T14:00:00Z', 'end_at' => '2026-09-11T15:00:00Z'],
        ],
        'blocks' => [],
    ]);
    mock_respond(['success' => true, 'reset' => true]);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$token = $body['token'] ?? '';

if ($token !== 'testvalidtoken123') {
    mock_respond(
        ['success' => false, 'error' => 'invalid_token', 'message' => 'Token de artista inválido ou desconhecido.'],
        401
    );
}

$state = mock_load_state();
$action = $body['action'] ?? '';

switch ($action) {
    case 'list':
        mock_respond([
            'success' => true,
            'action'   => 'list',
            'artist_id' => 'bbbb2222-0000-0000-0000-000000000001',
            'artist_name' => 'João Silva',
            'duration_min' => 60,
            'timezone' => 'America/Sao_Paulo',
            'available' => $state['available'],
            'blocks' => $state['blocks'],
        ]);

    case 'block':
        $start = (string) ($body['start_at'] ?? '');
        $end   = (string) ($body['end_at'] ?? '');
        if ($start === '' || $end === '') {
            mock_respond(['success' => false, 'error' => 'missing_range', 'message' => 'Informe início e fim.'], 400);
        }
        $id = 'cccc3333-0000-0000-0000-00000000000' . (count($state['blocks']) + 1);
        $state['blocks'][] = ['id' => $id, 'start_at' => $start, 'end_at' => $end];
        $state['available'] = array_values(array_filter(
            $state['available'],
            static fn (array $a): bool => !($a['start_at'] < $end && $a['end_at'] > $start)
        ));
        mock_save_state($state);
        mock_respond(['success' => true, 'action' => 'block', 'block' => ['id' => $id, 'start_at' => $start, 'end_at' => $end]]);

    case 'unblock':
        $blockId = (string) ($body['block_id'] ?? '');
        $removed = null;
        $remaining = [];
        foreach ($state['blocks'] as $b) {
            if ($b['id'] === $blockId) {
                $removed = $b;
            } else {
                $remaining[] = $b;
            }
        }
        if ($removed === null) {
            mock_respond(['success' => false, 'action' => 'unblock', 'error' => 'block_not_found', 'message' => 'Bloqueio não encontrado.']);
        }
        $state['blocks'] = $remaining;
        $state['available'][] = [
            'id' => 'dddd4444-0000-0000-0000-000000000001',
            'start_at' => $removed['start_at'],
            'end_at' => $removed['end_at'],
        ];
        usort($state['available'], static fn (array $a, array $b): int => strcmp($a['start_at'], $b['start_at']));
        mock_save_state($state);
        mock_respond(['success' => true, 'action' => 'unblock', 'block' => $removed]);

    default:
        mock_respond(
            ['success' => false, 'error' => 'invalid_action', 'message' => 'Ação desconhecida. Use list, block ou unblock.'],
            400
        );
}