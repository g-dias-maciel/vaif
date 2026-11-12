<?php

namespace App\Services;

use App\Models\Artist;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
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
    ) {}

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
     *                                                                 null when WAHA is unreachable / not configured.
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
     * Stored chats for the session. Used by the onboarding backfill to
     * enumerate conversations before fetching each message history.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function chats(Artist $artist, int $limit = 200): ?array
    {
        if (! $artist->wa_session_slug) {
            return null;
        }

        try {
            $resp = $this->client()->get(
                '/api/'.$artist->wa_session_slug.'/chats',
                ['limit' => $limit],
            );
        } catch (ConnectionException) {
            return null;
        }

        if (! $resp->successful()) {
            return null;
        }

        $data = $resp->json();

        if (! is_array($data)) {
            return null;
        }

        // WAHA has returned both a bare list and `{ "chats": [...] }`.
        if (isset($data['chats']) && is_array($data['chats'])) {
            return array_values(array_filter($data['chats'], 'is_array'));
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * Stored messages for one chat (NOWEB store only).
     *
     * Returns null when WAHA is unreachable or the session has no store.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function chatMessages(Artist $artist, string $chatJid, int $limit = 200, bool $downloadMedia = false): ?array
    {
        if (! $artist->wa_session_slug || $chatJid === '') {
            return null;
        }

        try {
            $resp = $this->client()->get(
                '/api/'.$artist->wa_session_slug.'/chats/'.rawurlencode($chatJid).'/messages',
                ['limit' => $limit, 'downloadMedia' => $downloadMedia ? 'true' : 'false'],
            );
        } catch (ConnectionException) {
            return null;
        }

        if (! $resp->successful()) {
            return null;
        }

        $data = $resp->json();

        return is_array($data) ? $data : null;
    }

    /**
     * Current session status + webhook target, for the calibration page.
     *
     * @return array{status: string, me: ?string, url: ?string, events: array<int, string>, has_token: bool}|null
     */
    public function webhookInfo(Artist $artist): ?array
    {
        if (! $artist->wa_session_slug) {
            return null;
        }

        try {
            $resp = $this->client()->get("/api/sessions/{$artist->wa_session_slug}");
        } catch (ConnectionException) {
            return null;
        }

        if (! $resp->successful()) {
            return null;
        }

        $data = $resp->json() ?? [];
        $hooks = $data['config']['webhooks'] ?? [];
        $hook = is_array($hooks) && isset($hooks[0]) && is_array($hooks[0]) ? $hooks[0] : null;
        $events = is_array($hook['events'] ?? null) ? $hook['events'] : [];
        $headers = is_array($hook['customHeaders'] ?? null) ? $hook['customHeaders'] : [];

        return [
            'status' => (string) ($data['status'] ?? 'UNKNOWN'),
            'me' => $this->normalizeMe($data['me'] ?? null),
            'url' => is_array($hook) ? (string) ($hook['url'] ?? '') : null,
            'events' => array_values(array_map('strval', $events)),
            'has_token' => $headers !== [],
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
        } elseif ($current && $current['state'] === 'FAILED') {
            $this->restart($artist);
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
        $webhook = [
            'url' => (string) config('transport.waha_webhook_url'),
            // message.any is required to capture the artist's own replies
            // (fromMe). The CRM ignores source=api (its own sends).
            'events' => ['message.any', 'presence.update'],
        ];

        // The CRM webhook rejects unauthenticated calls. A recreated session
        // must keep sending the shared header or every message is dropped.
        $webhookToken = (string) config('transport.waha_webhook_token');

        if ($webhookToken !== '') {
            $webhook['customHeaders'] = [[
                'name' => 'X-VAIF-Webhook-Token',
                'value' => $webhookToken,
            ]];
        }

        try {
            $resp = $this->client()->post('/api/sessions', [
                'name' => $artist->wa_session_slug,
                'config' => [
                    'webhooks' => [$webhook],
                    // Enable the NOWEB store so the agent can read the artist's
                    // saved contacts (and their saved name). It must be set at
                    // session creation: changing it after the QR scan makes
                    // WAHA drop the stored history.
                    'noweb' => [
                        'store' => [
                            'enabled' => true,
                            'fullSync' => true,
                        ],
                    ],
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

            if ($current && $current['state'] === 'FAILED') {
                return $this->restart($artist);
            }

            return $this->start($artist);
        }

        if (! $resp->successful()) {
            return false;
        }

        return $this->start($artist);
    }

    /**
     * Point an existing session at a new webhook **without re-linking**.
     *
     * WAHA's `PUT /api/sessions/{name}` stops and starts the session with the
     * new config but keeps the WhatsApp authentication, so the artist never
     * scans the QR again. The current config is merged rather than replaced so
     * the NOWEB store and metadata survive.
     */
    public function updateWebhook(Artist $artist, ?string $url = null, ?string $token = null, ?array $events = null): bool
    {
        $slug = (string) $artist->wa_session_slug;

        if ($slug === '') {
            return false;
        }

        try {
            $current = $this->client()->get("/api/sessions/{$slug}");
        } catch (ConnectionException) {
            return false;
        }

        if (! $current->successful()) {
            return false;
        }

        $config = $current->json('config');
        $config = is_array($config) ? $config : [];

        $webhook = [
            'url' => $url ?: (string) config('transport.waha_webhook_url'),
            'events' => $events ?: ['message.any', 'presence.update'],
        ];

        $webhookToken = $token !== null ? $token : (string) config('transport.waha_webhook_token');

        if ($webhookToken !== '') {
            $webhook['customHeaders'] = [[
                'name' => 'X-VAIF-Webhook-Token',
                'value' => $webhookToken,
            ]];
        }

        $config['webhooks'] = [$webhook];

        try {
            $resp = $this->client()->put("/api/sessions/{$slug}", [
                'name' => $slug,
                'config' => $config,
            ]);
        } catch (ConnectionException) {
            return false;
        }

        return $resp->successful();
    }

    /**
     * Re-link the device from scratch so the session picks up a config change
     * (e.g. the NOWEB store). WAHA refuses to apply a new store config to an
     * already-paired session, so the only safe path is delete + create: the
     * artist scans the QR again.
     */
    public function relink(Artist $artist): bool
    {
        // Best-effort logout first so the phone drops the old link even if the
        // delete endpoint is unavailable.
        try {
            $this->client()->post("/api/sessions/{$artist->wa_session_slug}/logout");
        } catch (ConnectionException) {
            // Already unlinked / unreachable: continue with the delete.
        }

        $this->deleteSession($artist);

        return $this->create($artist);
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
     * Remove the Artist's WAHA session when the Artist is deleted.
     */
    public function deleteSession(Artist $artist): bool
    {
        if (! $artist->wa_session_slug) {
            return false;
        }

        try {
            $resp = $this->client()->delete("/api/sessions/{$artist->wa_session_slug}");
        } catch (ConnectionException) {
            return false;
        }

        return $resp->successful() || $resp->status() === 404;
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

    /**
     * Whether the phone is in the Artist's saved WhatsApp contacts.
     *
     * `check-exists` is NOT this check: it answers "is this a registered
     * WhatsApp number?" (true for anyone). A saved contact is an entry in the
     * address-book list that has a `name` (a bare `pushname` is the contact's
     * own profile name, not something the Artist saved).
     *
     * Requires the NOWEB store. Returns null when the check cannot be made
     * (store off, WAHA unreachable) so the caller can fail open.
     */
    public function isSavedContact(Artist $artist, string $phone): ?bool
    {
        if (! $artist->wa_session_slug) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            return null;
        }

        $contacts = $this->contacts($artist);

        if ($contacts === null) {
            return null;
        }

        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $numbers = [
                preg_replace('/\D+/', '', (string) ($contact['phoneNumber'] ?? '')),
                preg_replace('/\D+/', '', (string) ($contact['id'] ?? '')),
            ];

            if (in_array($digits, $numbers, true)) {
                return ! empty($contact['name']);
            }
        }

        return false;
    }

    /**
     * The Artist's saved contacts (name + phone), cached briefly because first
     * contacts are rare but the list can be large.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function contacts(Artist $artist): ?array
    {
        $cacheKey = 'waha:contacts:'.$artist->id;
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $resp = $this->client()->get('/api/contacts/all', [
                'session' => $artist->wa_session_slug,
            ]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $resp->successful()) {
            return null;
        }

        $contacts = $resp->json();

        if (! is_array($contacts)) {
            return null;
        }

        Cache::put($cacheKey, $contacts, 300);

        return $contacts;
    }

    /**
     * Send a plain-text WhatsApp message through the Artist's session.
     */
    public function sendText(Artist $artist, string $chatId, string $text): bool
    {
        if (! $artist->wa_session_slug || $chatId === '' || $text === '') {
            return false;
        }

        try {
            $resp = $this->client()->post('/api/sendText', [
                'session' => $artist->wa_session_slug,
                'chatId' => $chatId,
                'text' => $text,
            ]);
        } catch (ConnectionException) {
            return false;
        }

        return $resp->successful();
    }

    public function startTyping(Artist $artist, string $chatId): bool
    {
        return $this->presence($artist, $chatId, 'typing');
    }

    public function stopTyping(Artist $artist, string $chatId): bool
    {
        return $this->presence($artist, $chatId, 'paused');
    }

    private function presence(Artist $artist, string $chatId, string $state): bool
    {
        if (! $artist->wa_session_slug || $chatId === '') {
            return false;
        }

        try {
            $response = $this->client()->post('/api/'.$artist->wa_session_slug.'/presence', [
                'chatId' => $chatId,
                'presence' => $state,
            ]);
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful();
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
