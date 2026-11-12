<?php

namespace App\Services\Agent;

use App\Models\Artist;
use App\Models\Lead;
use App\Services\WahaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Onboarding backfill: import an Artist's existing WhatsApp 1:1 chats into the
 * CRM, then run one interpretation pass per conversation.
 *
 * Dry-run never writes and never calls the model. A real run imports messages
 * with their original timestamps, restores the Lead's timeline, writes explicit
 * discovery fields, quotes, historical bookings (created_by=backfill) and the
 * conversation summary.
 */
class ConversationBackfiller
{
    public function __construct(
        private readonly WahaService $waha,
        private readonly AgentStore $store,
        private readonly ObservedStore $observed,
        private readonly PreflightClassifier $preflight,
        private readonly PromptBuilder $prompts,
        private readonly ArtistSignalExtractor $artistSignals,
        private readonly ConversationSummarizer $summarizer,
    ) {}

    /**
     * @param  array<int, string>  $exclude  phone digits to skip
     * @return array<string, mixed>
     */
    public function run(
        Artist $artist,
        int $days = 90,
        array $exclude = [],
        int $limit = 100,
        bool $dryRun = false,
    ): array {
        $artist->loadMissing('settings');

        $since = now()->subDays(max(1, $days));
        $counts = [
            'dry_run' => $dryRun,
            'days' => max(1, $days),
            'chats_fetched' => 0,
            'chats_considered' => 0,
            'messages' => 0,
            'inbound_messages' => 0,
            'imported' => 0,
            'interpreted' => 0,
            'quotes' => 0,
            'bookings' => 0,
            'summaries' => 0,
            'errors' => 0,
            'skipped' => [],
        ];

        $chats = $this->waha->chats($artist, 500);

        if ($chats === null) {
            return ['error' => 'waha_unavailable'] + $counts;
        }

        $counts['chats_fetched'] = count($chats);
        $exclude = array_values(array_filter(array_map(fn ($phone) => $this->digits((string) $phone), $exclude)));
        $blocked = array_values(array_filter(array_map(
            fn ($phone) => $this->digits((string) $phone),
            (array) ($artist->settings?->blocked_numbers ?? []),
        )));

        foreach ($chats as $chat) {
            if (! is_array($chat)) {
                continue;
            }

            if ($counts['chats_considered'] >= max(1, $limit)) {
                break;
            }

            $jid = $this->chatJid($chat);

            if ($jid === '' || $this->isGroup($jid)) {
                $this->skip($counts, 'group_or_invalid');

                continue;
            }

            $messages = $this->waha->chatMessages($artist, $jid, 500, false);

            if ($messages === null) {
                $this->skip($counts, 'history_unavailable');

                continue;
            }

            $messages = $this->filterMessages($messages, $since);

            if ($messages === []) {
                $this->skip($counts, 'no_messages_in_window');

                continue;
            }

            $phone = $this->phoneFor($jid, $messages);

            if ($phone === '') {
                $this->skip($counts, 'no_phone');

                continue;
            }

            if (in_array($phone, $exclude, true) || in_array($phone, $blocked, true)) {
                $this->skip($counts, 'excluded');

                continue;
            }

            $inbound = array_values(array_filter($messages, fn (array $message) => ! $this->fromMe($message)));

            if ($inbound === []) {
                $this->skip($counts, 'no_inbound');

                continue;
            }

            $counts['chats_considered']++;
            $counts['messages'] += count($messages);
            $counts['inbound_messages'] += count($inbound);

            if ($dryRun) {
                continue;
            }

            try {
                $this->importChat($artist, $chat, $jid, $phone, $messages, $counts);
            } catch (\Throwable $e) {
                $counts['errors']++;
                Log::warning('Backfill chat failed', [
                    'artist_id' => $artist->id,
                    'jid' => $jid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $counts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $counts
     */
    private function importChat(
        Artist $artist,
        array $chat,
        string $jid,
        string $phone,
        array $messages,
        array &$counts,
    ): void {
        $chatId = ($artist->wa_session_slug ?: 'artist').':'.$jid;
        $normalizedPhone = $this->normalizePhone($phone);

        $prior = Lead::query()
            ->where('artist_id', $artist->id)
            ->where('phone_e164', $normalizedPhone)
            ->first(['id', 'first_seen_at', 'last_message_at']);

        $start = $this->store->startTurn(
            $artist->id,
            $chatId,
            $normalizedPhone,
            $this->chatName($chat),
            'whatsapp_backfill',
        );

        if ($start === []) {
            $this->skip($counts, 'start_turn_empty');

            return;
        }

        $leadId = (string) $start['lead_id'];
        $projectId = (string) $start['project_id'];

        $firstInbound = null;
        $lastMessage = null;
        $inboundLines = [];
        $artistLines = [];

        foreach ($messages as $message) {
            $fromMe = $this->fromMe($message);
            $text = $this->messageText($message);

            if ($text === '') {
                continue;
            }

            $timestamp = $this->timestamp($message);

            if ($timestamp === null) {
                continue;
            }

            $waId = trim((string) ($message['id'] ?? ''));
            $isArtist = $fromMe && (($message['source'] ?? '') !== 'api');

            if ($this->observed->importMessage(
                $leadId,
                $fromMe ? 'outbound' : 'inbound',
                $text,
                $waId !== '' ? $waId : null,
                $timestamp->toIso8601String(),
                $isArtist,
            )) {
                $counts['imported']++;
            }

            $lastMessage = $lastMessage === null || $timestamp->greaterThan($lastMessage)
                ? $timestamp
                : $lastMessage;

            if ($fromMe) {
                $artistLines[] = $text;
            } else {
                $inboundLines[] = $text;
                $firstInbound = $firstInbound === null || $timestamp->lessThan($firstInbound)
                    ? $timestamp
                    : $firstInbound;
            }
        }

        $firstTimeline = $this->earliest($prior?->first_seen_at, $firstInbound);
        $lastTimeline = $this->latest($prior?->last_message_at, $lastMessage);

        $this->observed->setLeadTimeline(
            $leadId,
            $firstTimeline?->toIso8601String(),
            $lastTimeline?->toIso8601String(),
        );

        $this->interpret(
            $artist,
            $start,
            $leadId,
            $projectId,
            $normalizedPhone,
            $inboundLines,
            $artistLines,
            $counts,
            $lastMessage?->toIso8601String(),
        );
        $counts['interpreted']++;
    }

    /**
     * @param  array<int, string>  $inboundLines
     * @param  array<int, string>  $artistLines
     * @param  array<string, mixed>  $counts
     */
    private function interpret(
        Artist $artist,
        array $start,
        string $leadId,
        string $projectId,
        string $phone,
        array $inboundLines,
        array $artistLines,
        array &$counts,
        ?string $occurredAt = null,
    ): void {
        if ($inboundLines !== []) {
            $context = $this->prompts->context($artist, $start);
            $preflight = $this->preflight->classify($context, implode("\n", $inboundLines), []);
            $entities = EntitySanitizer::sanitize($preflight['entities']);

            if ($entities !== []) {
                $this->observed->applyFields($artist->id, $projectId, $phone, $entities, 'system');
            }
        }

        if ($artistLines !== []) {
            $signals = $this->artistSignals->extract(
                $artist,
                "MENSAGENS DO ESTÚDIO (em ordem cronológica):\n".implode("\n", $artistLines),
                $start,
                [],
                true,
            );

            $price = $signals['price_cents'];

            if (is_int($price) && $price > 0) {
                $base = (int) ($start['table_price_cents'] ?? 0);
                $quote = $this->observed->writeQuote(
                    $artist->id,
                    $projectId,
                    $base > 0 ? $base : $price,
                    $price,
                    'system',
                );

                if ($quote !== null) {
                    $counts['quotes']++;
                }
            }

            $slot = $signals['booking_confirmed_at'];

            if (! is_string($slot) || $slot === '') {
                $slot = $signals['reschedule_to'];
            }

            $active = $start['active_booking_id'] ?? null;

            if (is_string($slot) && $slot !== '' && ($active === null || $active === '')) {
                $booking = $this->store->bookSlot($artist->id, $projectId, $slot, 120, 'system');

                if ($booking !== null) {
                    $past = Carbon::parse($slot)->lessThan(now());

                    $this->observed->setStage(
                        $artist->id,
                        $projectId,
                        $past ? 'fechado' : 'agendado',
                        'system',
                        $occurredAt,
                    );

                    $this->observed->setBookingCreatedAt(
                        (string) ($booking['id'] ?? ''),
                        $occurredAt,
                        ! $past,
                        'backfill',
                    );
                    $counts['bookings']++;
                }
            }
        }

        $lines = count($inboundLines) + count($artistLines);

        if ($lines >= (int) config('agent.audit.min_messages', 6)) {
            $lead = Lead::find($leadId);

            if ($lead !== null) {
                $summary = $this->summarizer->summarize($artist, $lead);

                if ($summary !== null) {
                    $this->observed->upsertSummary(
                        $leadId,
                        $summary['body'],
                        $summary['message_count'],
                        $summary['model'],
                    );
                    $counts['summaries']++;
                }
            }
        }
    }

    /**
     * @param  array<int, mixed>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function filterMessages(array $messages, Carbon $since): array
    {
        $out = [];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $timestamp = $this->timestamp($message);

            if ($timestamp === null || $timestamp->lessThan($since)) {
                continue;
            }

            $out[] = $message;
        }

        usort($out, fn (array $a, array $b) => $this->timestamp($a)?->getTimestamp() <=> $this->timestamp($b)?->getTimestamp());

        return $out;
    }

    private function fromMe(array $message): bool
    {
        return filter_var($message['fromMe'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function timestamp(array $message): ?Carbon
    {
        $raw = $message['timestamp'] ?? $message['t'] ?? null;

        if (! is_numeric($raw) || (int) $raw <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $raw, 'UTC');
    }

    private function chatJid(array $chat): string
    {
        foreach ([
            $chat['id'] ?? null,
            $chat['_data']['id']['_serialized'] ?? null,
            $chat['_data']['id'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    private function chatName(array $chat): ?string
    {
        foreach ([
            $chat['name'] ?? null,
            $chat['_data']['name'] ?? null,
            $chat['_data']['subject'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function isGroup(string $jid): bool
    {
        return str_ends_with($jid, '@g.us')
            || str_ends_with($jid, '@broadcast')
            || str_ends_with($jid, '@newsletter')
            || $jid === 'status@broadcast';
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function phoneFor(string $jid, array $messages): string
    {
        if (! str_ends_with($jid, '@lid')) {
            $digits = $this->digits($jid);

            if ($digits !== '') {
                return $digits;
            }
        }

        foreach ($messages as $message) {
            foreach ([
                $message['_data']['key']['remoteJidAlt'] ?? null,
                $message['_data']['key']['senderPn'] ?? null,
                $message['from'] ?? null,
                $message['to'] ?? null,
            ] as $candidate) {
                if (! is_string($candidate) || $candidate === '' || str_ends_with($candidate, '@lid')) {
                    continue;
                }

                $digits = $this->digits($candidate);

                if ($digits !== '') {
                    return $digits;
                }
            }
        }

        return '';
    }

    /**
     * WhatsApp media without a body still has to appear in the transcript.
     */
    private function messageText(array $message): string
    {
        $body = trim((string) ($message['body'] ?? ''));

        if ($body !== '') {
            return $body;
        }

        $type = (string) ($message['type'] ?? '');
        $data = is_array($message['_data']['message'] ?? null) ? $message['_data']['message'] : [];
        $mime = (string) ($message['media']['mimetype'] ?? '');
        $fromMe = $this->fromMe($message);

        $sticker = $type === 'sticker' || isset($data['stickerMessage']);
        $audio = in_array($type, ['ptt', 'audio'], true) || isset($data['audioMessage']) || isset($data['pttMessage']);
        $video = $type === 'video' || isset($data['videoMessage']);
        $image = ! $sticker && ($type === 'image' || isset($data['imageMessage']) || str_starts_with($mime, 'image/'));
        $hasMedia = $sticker || $audio || $video || $image
            || ($message['hasMedia'] ?? false) === true
            || ! empty($message['media']['url']);

        if (! $hasMedia) {
            return '';
        }

        return match (true) {
            $sticker => $fromMe ? '[STICKER ENVIADO]' : '[STICKER RECEBIDO]',
            $audio => $fromMe ? '[ÁUDIO ENVIADO]' : '[ÁUDIO RECEBIDO]',
            $video => $fromMe ? '[VÍDEO ENVIADO]' : '[VÍDEO RECEBIDO]',
            $image => $fromMe ? '[FOTO ENVIADA]' : '[FOTO RECEBIDA]',
            default => $fromMe ? '[MÍDIA ENVIADA]' : '[MÍDIA RECEBIDA]',
        };
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D/', '', preg_replace('/@.*$/', '', $value) ?? '') ?? '';
    }

    private function normalizePhone(string $digits): string
    {
        $digits = $this->digits($digits);

        if (in_array(strlen($digits), [10, 11], true)) {
            $digits = '55'.$digits;
        }

        return '+'.$digits;
    }

    private function earliest(?Carbon $a, ?Carbon $b): ?Carbon
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a->lessThan($b) ? $a : $b;
    }

    private function latest(?Carbon $a, ?Carbon $b): ?Carbon
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a->greaterThan($b) ? $a : $b;
    }

    /**
     * @param  array<string, mixed>  $counts
     */
    private function skip(array &$counts, string $reason): void
    {
        $counts['skipped'][$reason] = ($counts['skipped'][$reason] ?? 0) + 1;
    }
}
