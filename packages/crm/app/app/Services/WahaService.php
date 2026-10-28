<?php

namespace App\Services;

use App\Models\Artist;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client over the self-hosted WAHA HTTP API (WhatsApp transport).
 *
 * Endpoints mirror the ones the n8n workflows already use:
 *   GET  /api/sessions/{name}         -> { status, me }
 *   GET  /api/{name}/auth/qr          -> QR (base64 when JSON)
 *   POST /api/sessions                -> create session
 *   POST /api/sessions/{name}/restart -> restart session
 */
class WahaService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
    ) {
    }

    public static function make(): self
    {
        return new self(
            rtrim((string) config('transport.waha_url'), '/'),
            config('transport.waha_api_key') ?: null,
        );
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '';
    }

    /**
     * @return array{state: string, connected: bool, me: ?string}|null
     *         null when WAHA is unreachable / not configured.
     */
    public function status(Artist $artist): ?array
    {
        try {
            $resp = $this->client()->get("/api/sessions/{$artist->wa_session_slug}");
        } catch (ConnectionException) {
            return null;
        }

        if (! $resp->successful()) {
            return null;
        }

        $data = $resp->json() ?? [];
        $state = (string) ($data['status'] ?? 'UNKNOWN');
        $me = $data['me'] ?? null;

        return [
            'state' => $state,
            'connected' => $state === 'WORKING' || ! empty($me),
            'me' => $this->normalizeMe($me),
        ];
    }

    /**
     * Base64 PNG of the session QR code, or null.
     *
     * WAHA only serves the QR while the session is in SCAN_QR_CODE; a freshly
     * created session is STOPPED until /start is called, and takes a moment to
     * move to SCAN_QR_CODE.
     */
    public function qr(Artist $artist): ?string
    {
        $current = $this->status($artist);

        if ($current && $current['state'] === 'STOPPED') {
            $this->start($artist);
        }

        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                $resp = $this->client()->get("/api/{$artist->wa_session_slug}/auth/qr?format=base64");
            } catch (ConnectionException) {
                return null;
            }

            if ($resp->successful()) {
                $data = $resp->json();
                if (is_array($data)) {
                    return $data['qrcode'] ?? $data['data'] ?? $data['qr'] ?? null;
                }

                if (str_starts_with((string) $resp->header('Content-Type'), 'image/')) {
                    return base64_encode($resp->body());
                }

                return null;
            }

            // 422 = session not in SCAN_QR_CODE yet; give it a moment.
            if ($resp->status() !== 422) {
                return null;
            }

            usleep(1000000);
        }

        return null;
    }

    /**
     * Create (or reuse) the WAHA session and start it so it emits the QR.
     */
    public function create(Artist $artist): bool
    {
        try {
            $resp = $this->client()->post('/api/sessions', [
                'name' => $artist->wa_session_slug,
                'config' => [
                    'webhooks' => [[
                        'url' => (string) config('transport.waha_webhook_url'),
                        'events' => ['message', 'presence.update'],
                    ]],
                ],
            ]);
        } catch (ConnectionException) {
            return false;
        }

        // 422 means the session already exists: reuse it and just start it
        // when needed (never restart a working session).
        if ($resp->status() === 422) {
            $current = $this->status($artist);

            if ($current && in_array($current['state'], ['STARTING', 'SCAN_QR_CODE', 'WORKING'], true)) {
                return true;
            }

            return $this->start($artist);
        }

        if (! $resp->successful()) {
            return false;
        }

        return $this->start($artist);
    }

    /**
     * Start a stopped session (WAHA returns 422 when it is already running).
     */
    public function start(Artist $artist): bool
    {
        try {
            $resp = $this->client()->post("/api/sessions/{$artist->wa_session_slug}/start");
        } catch (ConnectionException) {
            return false;
        }

        return $resp->successful() || $resp->status() === 422;
    }

    /**
     * Restart the session (fix a stuck / bugged session).
     */
    public function restart(Artist $artist): bool
    {
        try {
            $resp = $this->client()->post("/api/sessions/{$artist->wa_session_slug}/restart");
        } catch (ConnectionException) {
            return false;
        }

        return $resp->successful();
    }

    private function client(): PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl)->acceptJson()->timeout(5);

        if ($this->apiKey) {
            $client->withHeaders(['X-Api-Key' => $this->apiKey]);
        }

        return $client;
    }

    private function normalizeMe(mixed $me): ?string
    {
        if (is_array($me)) {
            return $me['pushName'] ?? $me['id'] ?? null;
        }

        return $me ? (string) $me : null;
    }
}
