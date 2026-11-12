<?php

namespace App\Services\Agent;

use App\Jobs\SummarizeConversation;
use App\Models\Artist;
use App\Models\ObservedWrite;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Observe mode: read the conversation, never reply, write the safe facts.
 *
 * This class has no dependency on `MessageTransport` by design. If someone
 * tries to make the observer send something, the dependency has to be added
 * here first — and it must not be.
 */
class ObservedTurnProcessor
{
    public function __construct(
        private readonly AgentStore $store,
        private readonly ObservedStore $observed,
        private readonly PreflightClassifier $preflight,
        private readonly PromptBuilder $prompts,
        private readonly ?AgentMedia $media = null,
        private readonly ?ArtistSignalExtractor $artistSignals = null,
    ) {}

    /**
     * Interpret a lead burst and record it.
     *
     * @return array<string, mixed>
     */
    public function observeInbound(
        Artist $artist,
        string $chatId,
        string $phone,
        string $leadText,
        ?string $name = null,
        string $msgAt = '',
    ): array {
        $start = $this->store->startTurn($artist->id, $chatId, $phone, $name);

        if ($start === []) {
            return ['skipped' => 'start_turn_empty'];
        }

        $leadId = (string) $start['lead_id'];
        $projectId = (string) $start['project_id'];

        $effectiveText = $leadText;
        if ($this->media !== null) {
            $prepared = $this->media->prepare(
                $artist,
                $projectId,
                $leadText,
                (string) ($start['tattoo_type'] ?? ''),
            );
            $effectiveText = $prepared['text'];

            if ($prepared['analysis'] !== null) {
                $start['reference_analysis'] = $prepared['analysis'];
            }
        }

        $history = $this->store->history($leadId, 8, $msgAt === '' ? null : $msgAt);
        $context = $this->prompts->context($artist, $start);
        $preflight = $this->preflight->classify($context, $effectiveText, $history);
        $entities = EntitySanitizer::sanitize($preflight['entities']);

        // The transcript is the one thing that must survive every failure.
        try {
            $this->store->rememberHuman($leadId, $effectiveText, $msgAt === '' ? null : $msgAt);
        } catch (\Throwable $e) {
            Log::warning('Observe transcript write failed', [
                'lead_id' => $leadId,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }

        $changed = [];
        if ($entities !== []) {
            $changed = $this->observed->applyFields(
                $artist->id,
                $projectId,
                (string) ($start['phone_e164'] ?? $phone),
                $entities,
                'artist',
            );
        }

        $occurredAt = $msgAt !== '' ? $msgAt : now()->toIso8601String();
        $turnId = (string) Str::uuid();

        $this->observed->saveTurn([
            'id' => $turnId,
            'artist_id' => $artist->id,
            'lead_id' => $leadId,
            'project_id' => $projectId,
            'chat_id' => $chatId,
            'direction' => 'inbound',
            'occurred_at' => $occurredAt,
            'text' => $effectiveText,
            'intent' => $preflight['intent'] ?? null,
            'entities' => $entities,
            'facts' => [
                'assumptions' => $preflight['assumptions'] ?? [],
                'flags' => $preflight['flags'] ?? [],
                'confidence' => $preflight['confidence'] ?? null,
                'changed' => $changed,
            ],
            'model' => (string) config('agent.models.preflight'),
        ]);

        $applied = $this->handleLeadBooking(
            $artist,
            $start,
            $preflight,
            $chatId,
            $leadId,
            $projectId,
            $turnId,
            $effectiveText,
        );

        $depositReview = $this->handleLeadDepositClaim(
            $artist,
            $start,
            $chatId,
            $leadId,
            $projectId,
            $turnId,
            $leadText,
            $effectiveText,
        );

        if ($depositReview !== []) {
            $applied['deposit_confirm_review'] = $depositReview;
        }

        $this->summarize($artist, $leadId, $occurredAt);

        return [
            'lead_id' => $leadId,
            'project_id' => $projectId,
            'intent' => $preflight['intent'] ?? null,
            'entities' => $entities,
            'changed' => $changed,
            'applied' => $applied,
        ];
    }

    /**
     * Record an artist outbound burst. The webhook already stored the message
     * itself; this interprets the signals and refreshes the summary.
     *
     * @return array<string, mixed>
     */
    public function observeArtist(
        Artist $artist,
        string $chatId,
        string $phone,
        string $text,
        ?string $waMessageId = null,
        ?string $at = null,
    ): array {
        $text = trim($text);

        if ($text === '') {
            return ['skipped' => 'empty_text'];
        }

        $start = $this->store->startTurn($artist->id, $chatId, $phone, null);

        if ($start === []) {
            return ['skipped' => 'start_turn_empty'];
        }

        $leadId = (string) $start['lead_id'];
        $projectId = (string) $start['project_id'];
        $occurredAt = $at !== null && $at !== '' ? $at : now()->toIso8601String();
        $turnId = (string) Str::uuid();

        $signals = $this->signals($artist, $text, $start, $leadId, $at);

        $this->observed->saveTurn([
            'id' => $turnId,
            'artist_id' => $artist->id,
            'lead_id' => $leadId,
            'project_id' => $projectId,
            'chat_id' => $chatId,
            'direction' => 'outbound',
            'wa_message_id' => $waMessageId,
            'occurred_at' => $occurredAt,
            'text' => $text,
            'facts' => ['signals' => $signals],
            'model' => (string) config('agent.models.artist_signals'),
        ]);

        $applied = $this->handleArtistSignals(
            $artist,
            $start,
            $signals,
            $chatId,
            $leadId,
            $projectId,
            $turnId,
            $text,
        );

        $this->summarize($artist, $leadId, $occurredAt);

        return [
            'lead_id' => $leadId,
            'project_id' => $projectId,
            'observed' => true,
            'signals' => $signals,
            'applied' => $applied,
        ];
    }

    /**
     * The lead selected one of the slots the artist offered. A match is the
     * two-sided signal that lets the observer book without a human.
     *
     * @return array<string, mixed>
     */
    private function handleLeadBooking(
        Artist $artist,
        array $start,
        array $preflight,
        string $chatId,
        string $leadId,
        string $projectId,
        string $turnId,
        string $text,
    ): array {
        $selected = trim((string) ($preflight['entities']['selected_slot_start'] ?? ''));
        $intent = (string) ($preflight['intent'] ?? '');

        if ($selected === '' || ! in_array($intent, ['choose_slot', 'reschedule', 'close'], true)) {
            return [];
        }

        $offered = is_array($start['agent_state']['offered_slots'] ?? null)
            ? $start['agent_state']['offered_slots']
            : [];

        if (! $this->matchesOffered($offered, $selected)) {
            return [];
        }

        $active = $start['active_booking_id'] ?? null;

        if ($active !== null && $active !== '') {
            return $this->queueWrite($artist, [
                'lead_id' => $leadId,
                'project_id' => $projectId,
                'chat_id' => $chatId,
                'observed_turn_id' => $turnId,
                'kind' => ObservedWrite::KIND_RESCHEDULE,
                'dedupe_key' => 'reschedule:'.$projectId.':'.$selected,
                'confidence' => 0.85,
                'payload' => [
                    'booking_id' => $active,
                    'start_at' => $selected,
                ],
                'evidence' => $this->evidence($text),
            ]);
        }

        $booking = $this->store->bookSlot($artist->id, $projectId, $selected, 120, 'artist');

        if ($booking === null) {
            return $this->queueWrite($artist, [
                'lead_id' => $leadId,
                'project_id' => $projectId,
                'chat_id' => $chatId,
                'observed_turn_id' => $turnId,
                'kind' => ObservedWrite::KIND_BOOKING,
                'dedupe_key' => 'booking:'.$projectId.':'.$selected,
                'confidence' => 0.8,
                'payload' => [
                    'start_at' => $selected,
                    'source' => 'lead_accepted_offer',
                ],
                'evidence' => $this->evidence($text),
            ]);
        }

        $this->observed->labelBooking((string) ($booking['id'] ?? ''), 'artist_observed');

        return $this->recordAutoApplied($artist, [
            'lead_id' => $leadId,
            'project_id' => $projectId,
            'chat_id' => $chatId,
            'observed_turn_id' => $turnId,
            'kind' => ObservedWrite::KIND_BOOKING,
            'dedupe_key' => 'booking:'.$projectId.':'.$selected,
            'confidence' => 0.9,
            'payload' => [
                'start_at' => $selected,
                'booking_id' => $booking['id'] ?? null,
                'source' => 'lead_accepted_offer',
            ],
            'evidence' => $this->evidence($text),
        ]);
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function handleArtistSignals(
        Artist $artist,
        array $start,
        array $signals,
        string $chatId,
        string $leadId,
        string $projectId,
        string $turnId,
        string $text,
    ): array {
        $applied = [];

        if ($signals['offered_slots'] !== []) {
            try {
                $this->store->patchChatState($artist->id, $chatId, [
                    'offered_slots' => $signals['offered_slots'],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Observe offered slots write failed', [
                    'artist_id' => $artist->id,
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $price = $signals['price_cents'];
        if (is_int($price) && $price > 0) {
            $base = (int) ($start['table_price_cents'] ?? $price);
            $quote = $this->observed->writeQuote(
                $artist->id,
                $projectId,
                $base > 0 ? $base : $price,
                $price,
                'artist',
            );

            if ($quote !== null) {
                $applied['quote'] = (string) ($quote['id'] ?? '');
            } else {
                $applied['quote_review'] = $this->queueWrite($artist, [
                    'lead_id' => $leadId,
                    'project_id' => $projectId,
                    'chat_id' => $chatId,
                    'observed_turn_id' => $turnId,
                    'kind' => ObservedWrite::KIND_QUOTE,
                    'dedupe_key' => 'quote:'.$projectId.':'.$price,
                    'confidence' => $signals['confidence'],
                    'payload' => [
                        'price_cents' => $price,
                        'base_cents' => $base > 0 ? $base : $price,
                    ],
                    'evidence' => $this->evidence($text),
                ]);
            }
        }

        $deposit = $signals['deposit_cents'] ?? (int) ($start['deposit_amount_cents'] ?? 0);
        if ($signals['asks_for_deposit'] && is_int($deposit) && $deposit > 0) {
            $active = $start['active_booking_id'] ?? null;

            if ($active !== null && $active !== '') {
                $payment = $this->observed->requestDeposit(
                    $artist->id,
                    $projectId,
                    $deposit,
                    'pix',
                    'artist',
                );

                if ($payment !== null) {
                    $applied['deposit_request'] = (string) ($payment['id'] ?? '');
                }
            } else {
                $applied['deposit_review'] = $this->queueWrite($artist, [
                    'lead_id' => $leadId,
                    'project_id' => $projectId,
                    'chat_id' => $chatId,
                    'observed_turn_id' => $turnId,
                    'kind' => ObservedWrite::KIND_DEPOSIT_REQUEST,
                    'dedupe_key' => 'deposit:'.$projectId.':'.$deposit,
                    'confidence' => $signals['confidence'],
                    'payload' => ['amount_cents' => $deposit],
                    'evidence' => $this->evidence($text),
                ]);
            }
        }

        if (is_string($signals['booking_confirmed_at']) && $signals['booking_confirmed_at'] !== '') {
            $applied['booking_review'] = $this->queueWrite($artist, [
                'lead_id' => $leadId,
                'project_id' => $projectId,
                'chat_id' => $chatId,
                'observed_turn_id' => $turnId,
                'kind' => ObservedWrite::KIND_BOOKING,
                'dedupe_key' => 'booking:'.$projectId.':'.$signals['booking_confirmed_at'],
                'confidence' => $signals['confidence'],
                'payload' => [
                    'start_at' => $signals['booking_confirmed_at'],
                    'source' => 'artist_confirmed',
                ],
                'evidence' => $this->evidence($text),
            ]);
        }

        if (is_string($signals['reschedule_to']) && $signals['reschedule_to'] !== '') {
            $active = $start['active_booking_id'] ?? null;

            $applied['reschedule_review'] = $this->queueWrite($artist, [
                'lead_id' => $leadId,
                'project_id' => $projectId,
                'chat_id' => $chatId,
                'observed_turn_id' => $turnId,
                'kind' => $active ? ObservedWrite::KIND_RESCHEDULE : ObservedWrite::KIND_BOOKING,
                'dedupe_key' => ($active ? 'reschedule:' : 'booking:').$projectId.':'.$signals['reschedule_to'],
                'confidence' => $signals['confidence'],
                'payload' => array_filter([
                    'booking_id' => $active,
                    'start_at' => $signals['reschedule_to'],
                    'source' => 'artist_reschedule',
                ]),
                'evidence' => $this->evidence($text),
            ]);
        }

        return $applied;
    }

    /**
     * The lead says the PIX was sent. Never confirm silently: this only
     * creates a review item for a human to check the receipt.
     *
     * @return array<string, mixed>
     */
    private function handleLeadDepositClaim(
        Artist $artist,
        array $start,
        string $chatId,
        string $leadId,
        string $projectId,
        string $turnId,
        string $rawText,
        string $effectiveText,
    ): array {
        if (($start['deposit_status'] ?? '') !== 'aguardando_confirmacao') {
            return [];
        }

        if (! $this->claimsDepositPaid($rawText)) {
            return [];
        }

        return $this->queueWrite($artist, [
            'lead_id' => $leadId,
            'project_id' => $projectId,
            'chat_id' => $chatId,
            'observed_turn_id' => $turnId,
            'kind' => ObservedWrite::KIND_DEPOSIT_CONFIRM,
            'dedupe_key' => 'deposit_confirm:'.$projectId.':'.now()->toDateString(),
            'confidence' => 0.7,
            'payload' => ['method' => 'pix'],
            'evidence' => $this->evidence($effectiveText),
        ]);
    }

    private function claimsDepositPaid(string $text): bool
    {
        $text = Str::ascii(mb_strtolower($text));

        $hasReceiptPhoto = str_contains($text, 'comprovante')
            && (str_contains($text, '[foto') || str_contains($text, '[[ref_foto'));

        if ($hasReceiptPhoto) {
            return true;
        }

        return preg_match(
            '/\b(pix|sinal|pagamento|transferencia)\b[^.]{0,40}\b(enviado|pago|feito|transferido|mandado|confirmado)\b/',
            $text,
        ) === 1
            || preg_match(
                '/\b(paguei|enviei|mandei|fiz|transferi)\b[^.]{0,30}\b(pix|sinal|pagamento|transferencia)\b/',
                $text,
            ) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function queueWrite(Artist $artist, array $write): array
    {
        $write['artist_id'] = $artist->id;
        $write['status'] = ObservedWrite::STATUS_PENDING;

        $item = $this->observed->createWrite($write);

        return $item !== null ? ['write_id' => $item->id, 'status' => $item->status] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordAutoApplied(Artist $artist, array $write): array
    {
        $write['artist_id'] = $artist->id;
        $write['status'] = ObservedWrite::STATUS_AUTO_APPLIED;

        $item = $this->observed->createWrite($write);

        // A pending item may have been queued before the two-sided signal
        // arrived. Turn it into the applied record instead of leaving a stale
        // suggestion behind.
        if ($item !== null && $item->status === ObservedWrite::STATUS_PENDING) {
            $item->forceFill([
                'status' => ObservedWrite::STATUS_AUTO_APPLIED,
                'payload' => array_merge(
                    is_array($item->payload) ? $item->payload : [],
                    is_array($write['payload'] ?? null) ? $write['payload'] : [],
                ),
                'decided_at' => now(),
            ])->save();
        }

        return $item !== null ? ['write_id' => $item->id, 'status' => $item->status] : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $offered
     */
    private function matchesOffered(array $offered, string $selected): bool
    {
        if ($offered === []) {
            return false;
        }

        foreach ($offered as $slot) {
            if (isset($slot['start_at']) && $this->sameInstant((string) $slot['start_at'], $selected)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $start
     * @return array<string, mixed>
     */
    private function signals(Artist $artist, string $text, array $start, string $leadId, ?string $at): array
    {
        if ($this->artistSignals === null) {
            return $this->emptySignals();
        }

        $history = $this->store->history($leadId, 12, $at === '' ? null : $at);

        try {
            return $this->artistSignals->extract($artist, $text, $start, $history);
        } catch (\Throwable $e) {
            Log::warning('Artist signal extraction failed', [
                'artist_id' => $artist->id,
                'lead_id' => $leadId,
                'error' => $e->getMessage(),
            ]);

            return $this->emptySignals();
        }
    }

    /**
     * Keep only explicit, canonical discovery values. Anything else the model
     * puts in `entities` is ignored; assumptions are never written.
     *
     * @param  array<string, mixed>  $entities
     * @return array<string, mixed>
     */
    private function summarize(Artist $artist, string $leadId, string $occurredAt): void
    {
        $delay = (int) config('agent.observe.summary_delay_seconds', 90);

        SummarizeConversation::dispatch($artist->id, $leadId, $occurredAt)
            ->delay(now()->addSeconds(max(0, $delay)))
            ->onQueue((string) config('agent.queue', 'agent'));
    }

    /**
     * @return array<string, mixed>
     */
    private function evidence(string $text): array
    {
        return ['message' => mb_substr(trim($text), 0, 400)];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySignals(): array
    {
        return [
            'offered_slots' => [],
            'booking_confirmed_at' => null,
            'reschedule_to' => null,
            'price_cents' => null,
            'deposit_cents' => null,
            'asks_for_deposit' => false,
            'promises' => [],
            'handoff' => false,
            'confidence' => 0.0,
        ];
    }

    private function sameInstant(string $a, string $b): bool
    {
        try {
            return (new \DateTimeImmutable($a))->getTimestamp() === (new \DateTimeImmutable($b))->getTimestamp();
        } catch (\Throwable) {
            return $a === $b;
        }
    }
}
