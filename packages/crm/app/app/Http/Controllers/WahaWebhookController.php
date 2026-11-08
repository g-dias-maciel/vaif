<?php

namespace App\Http\Controllers;

use App\Jobs\CompareDraft;
use App\Jobs\ProcessWhatsAppTurn;
use App\Models\AgentDraft;
use App\Models\Artist;
use App\Services\Agent\AgentStore;
use App\Services\Agent\DraftStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound WAHA webhook — the WhatsApp message path that used to point at n8n.
 *
 * The controller only does cheap routing (session → artist, pause/block/group
 * gates, presence, buffer append). The grounded turn runs in a queued job so
 * WAHA is acknowledged immediately and the debounce can span messages.
 */
class WahaWebhookController extends Controller
{
    public function __invoke(Request $request, AgentStore $store, DraftStore $drafts)
    {
        $expected = (string) config('agent.webhook_token');
        $provided = (string) ($request->header('X-VAIF-Webhook-Token') ?: $request->header('X-Crm-Token'));

        abort_unless($expected !== '' && hash_equals($expected, $provided), 401);

        $body = $request->json()->all();
        $event = (string) ($body['event'] ?? '');
        $session = (string) ($body['session'] ?? '');
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];

        if ($session === '') {
            return response()->json(['ignored' => 'no_session']);
        }

        $artist = Artist::where('wa_session_slug', $session)->first();

        if (! $artist) {
            $store->logDrop(null, $session, null, null, 'session_not_found', $body);

            return response()->json(['ignored' => 'unknown_session']);
        }

        // A suspended artist normally receives nothing. Draft mode is the
        // exception: it is safe to read and prepare a suggestion, and it never
        // sends to the lead. Use reply_mode=off to stop drafting too.
        $drafting = ($artist->settings?->reply_mode ?? 'draft') === 'draft';

        if ($artist->status === 'suspended' && ! $drafting) {
            $store->logDrop($artist->id, $session, $this->digits($this->senderJid($payload, false)), $this->chatId($session, $payload), 'artist_suspended', $body);

            return response()->json(['ignored' => 'artist_suspended']);
        }

        if ($event === 'presence.update') {
            $this->presence($artist, $session, $payload, $store);

            return response()->json(['ok' => true]);
        }

        if ($event !== 'message') {
            return response()->json(['ignored' => 'event']);
        }

        if ($this->isGroup($payload)) {
            return response()->json(['ignored' => 'group']);
        }

        $phone = $this->digits($this->senderJid($payload, true));

        if ($phone === '') {
            return response()->json(['ignored' => 'no_phone']);
        }

        // Blocked numbers are ignored in both directions. For a `fromMe`
        // message the artist is the sender, so checking only `from` would miss
        // the personal chat; the recipient and chat JIDs are checked too.
        if ($this->isBlockedPayload($artist, $payload)) {
            return response()->json(['ignored' => 'blocked']);
        }

        if (($payload['fromMe'] ?? false) === true) {
            $this->takeover($artist, $session, $payload, $store, $drafts);

            return response()->json(['ok' => true, 'from_me' => true]);
        }

        if (! $this->inActiveHours($artist)) {
            return response()->json(['ignored' => 'outside_hours']);
        }

        $chatId = $this->chatId($session, $payload);
        $text = $this->messageText($payload);
        $name = $this->displayName($payload);

        $buffer = $store->bufferAccumulate($artist->id, $chatId, $text);

        ProcessWhatsAppTurn::dispatch(
            $artist->id,
            $chatId,
            $phone,
            $name,
            (string) ($buffer['last_msg_at'] ?? ''),
        )
            ->delay(now()->addMilliseconds((int) config('agent.debounce.quiet_ms', 6000)))
            ->onQueue((string) config('agent.queue', 'agent'));

        return response()->json(['ok' => true, 'queued' => true]);
    }

    private function presence(Artist $artist, string $session, array $payload, AgentStore $store): void
    {
        $raw = (string) ($payload['id'] ?? '');
        if ($raw === '') {
            return;
        }

        $presence = 'paused';
        foreach ((array) ($payload['presences'] ?? []) as $item) {
            if (is_array($item) && ! empty($item['lastKnownPresence'])) {
                $presence = (string) $item['lastKnownPresence'];
            }
        }

        $store->bufferTyping($artist->id, $session.':'.$raw, $presence);
    }

    /**
     * The artist answered from their own phone: the bot must stop for this chat
     * and keep the artist's message in the conversation memory.
     */
    private function takeover(Artist $artist, string $session, array $payload, AgentStore $store, DraftStore $drafts): void
    {
        $chatId = $this->chatId($session, $payload);

        try {
            $store->takeControl($artist->id, $chatId, null);
        } catch (\Throwable $e) {
            Log::warning('Agent takeover failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
        }

        $text = trim((string) ($payload['body'] ?? '')) ?: '[MÍDIA ENVIADA]';
        $store->recordArtistMessage($artist->id, $chatId, $text);

        // Pair the artist's answer with the newest copilot draft for this chat.
        $draft = $drafts->latestOpenForChat($artist->id, $chatId);

        if ($draft === null || $drafts->hasNewerLeadMessage($draft)) {
            return;
        }

        $drafts->attachArtistReply($draft, $text);

        if ($draft->status === AgentDraft::STATUS_REPLIED) {
            CompareDraft::dispatch((string) $draft->id)
                ->delay(now()->addMinute())
                ->onQueue((string) config('agent.queue', 'agent'));
        }
    }

    private function isGroup(array $payload): bool
    {
        $key = is_array($payload['_data']['key'] ?? null) ? $payload['_data']['key'] : [];
        $from = (string) ($payload['from'] ?? $key['remoteJid'] ?? '');

        return str_ends_with($from, '@g.us')
            || in_array($from, ['status@broadcast'], true)
            || str_ends_with($from, '@broadcast')
            || str_ends_with($from, '@newsletter')
            || ($payload['isGroup'] ?? false) === true;
    }

    /**
     * WAHA's LID addressing puts the real phone in `_data.key.remoteJidAlt`;
     * the LID itself must never be stored as a phone number.
     */
    private function senderJid(array $payload, bool $preferPhone): string
    {
        $key = is_array($payload['_data']['key'] ?? null) ? $payload['_data']['key'] : [];
        $from = (string) ($payload['from'] ?? $key['remoteJid'] ?? '');
        $alt = (string) ($key['remoteJidAlt'] ?? $key['senderPn'] ?? '');

        if (! $preferPhone) {
            return $from;
        }

        return str_ends_with($from, '@lid') && $alt !== '' ? $alt : $from;
    }

    private function chatId(string $session, array $payload): string
    {
        $key = is_array($payload['_data']['key'] ?? null) ? $payload['_data']['key'] : [];
        $from = (string) ($payload['from'] ?? $key['remoteJid'] ?? '');

        return $session.':'.$from;
    }

    private function digits(string $jid): string
    {
        return preg_replace('/\D/', '', preg_replace('/@.*$/', '', $jid) ?? '') ?? '';
    }

    private function isBlockedPayload(Artist $artist, array $payload): bool
    {
        $blocked = (array) ($artist->settings?->blocked_numbers ?? []);

        if ($blocked === []) {
            return false;
        }

        $key = is_array($payload['_data']['key'] ?? null) ? $payload['_data']['key'] : [];
        $jids = array_filter([
            (string) ($payload['from'] ?? ''),
            (string) ($payload['to'] ?? ''),
            (string) ($key['remoteJid'] ?? ''),
            (string) ($key['remoteJidAlt'] ?? ''),
            (string) ($key['senderPn'] ?? ''),
        ]);

        foreach ($blocked as $number) {
            $digits = $this->digits((string) $number);

            if ($digits === '') {
                continue;
            }

            foreach ($jids as $jid) {
                if ($this->digits($jid) === $digits) {
                    return true;
                }
            }
        }

        return false;
    }

    private function inActiveHours(Artist $artist): bool
    {
        $hours = $artist->settings?->ai_active_hours;
        $start = is_array($hours) ? ($hours['start'] ?? null) : null;
        $end = is_array($hours) ? ($hours['end'] ?? null) : null;

        if (! $start || ! $end) {
            return true;
        }

        $tz = $artist->timezone ?: 'America/Sao_Paulo';
        $now = CarbonImmutable::now($tz);
        $minutes = $now->hour * 60 + $now->minute;
        $from = $this->minutes((string) $start);
        $to = $this->minutes((string) $end);

        return $from < $to
            ? ($minutes >= $from && $minutes < $to)
            : ($minutes >= $from || $minutes < $to);
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_pad(explode(':', $time), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }

    /**
     * WAHA sends media without a body; the agent still has to see that
     * something arrived. Image URLs travel through the buffer so a photo sent
     * earlier in the same burst is not lost.
     */
    private function messageText(array $payload): string
    {
        $raw = (string) ($payload['body'] ?? '');
        if ($raw !== '') {
            return $raw;
        }

        $type = (string) ($payload['type'] ?? 'chat');
        $media = is_array($payload['media'] ?? null) ? $payload['media'] : [];
        $message = is_array($payload['_data']['message'] ?? null) ? $payload['_data']['message'] : [];
        $mime = (string) ($media['mimetype'] ?? $payload['mimetype'] ?? '');

        $sticker = $type === 'sticker' || ($payload['isSticker'] ?? false) === true
            || ($media['isSticker'] ?? false) === true || isset($message['stickerMessage']);
        $audio = in_array($type, ['ptt', 'audio'], true) || isset($message['audioMessage']) || isset($message['pttMessage']);
        $video = $type === 'video' || isset($message['videoMessage']);
        $image = ! $sticker && ($type === 'image' || isset($message['imageMessage']) || str_starts_with($mime, 'image/'));

        $text = match (true) {
            $sticker => '[STICKER RECEBIDO]',
            $audio => '[ÁUDIO RECEBIDO]',
            $video => '[VÍDEO RECEBIDO]',
            $image || ! empty($payload['hasMedia']) => '[FOTO RECEBIDA]',
            default => '[MÍDIA RECEBIDA]',
        };

        $hasUrl = ! empty($payload['hasMedia']) && ! empty($media['url']);
        $url = $hasUrl ? (string) $media['url'] : '';

        if ($url === '') {
            return $text;
        }

        if ($image) {
            return $text."\n[[REF_FOTO:".$url.']]';
        }

        if ($audio) {
            return $text."\n[[REF_AUDIO:".$url.']]';
        }

        return $text;
    }

    private function displayName(array $payload): ?string
    {
        $data = is_array($payload['_data'] ?? null) ? $payload['_data'] : [];
        $name = trim((string) ($data['notifyName'] ?? $data['pushName'] ?? ''));

        return $name !== '' ? $name : null;
    }
}
