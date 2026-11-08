<?php

/**
 * Forward a calculator/diagnostic lead to the internal VAIF sales CRM.
 *
 * Non-blocking by design: the LP must succeed even when the CRM is down or the
 * token is not configured. Failures go to error_log only.
 */
function notify_crm(string $json): void
{
    $url = getenv('CRM_INGEST_URL');
    $token = getenv('CRM_INGEST_TOKEN');

    if (! $url || ! $token) {
        return;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer '.$token,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        error_log('CRM ingest failed: '.curl_error($ch));
    } else {
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($status >= 400) {
            error_log('CRM ingest returned HTTP '.$status.': '.substr((string) $response, 0, 300));
        }
    }

    curl_close($ch);
}
