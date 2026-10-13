<?php
/**
 * Mock of the n8n "agendamento-confirmado" calendar webhook for acceptance tests.
 *
 * Records the raw JSON body of the last POST so a test can assert what the LP
 * forwarded. The state file path is provided via the CALENDAR_MOCK_STATE_FILE
 * env var so parallel runs don't collide.
 *
 *   POST /            -> { success: true }   (records body)
 *   GET  /?reset=1    -> clears the recorded body
 */

declare(strict_types=1);

$stateFile = getenv('CALENDAR_MOCK_STATE_FILE') ?: sys_get_temp_dir() . '/vaif_calendar_webhook_mock.json';

if (($_GET['reset'] ?? '') === '1') {
    @unlink($stateFile);
    header('Content-Type: application/json');
    echo json_encode(['reset' => true]);
    exit;
}

$raw = (string) file_get_contents('php://input');
file_put_contents($stateFile, $raw);

header('Content-Type: application/json');
echo json_encode(['success' => true]);
