<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Services\Agent\AgentStore;
use App\Services\Agent\DraftProcessor;
use App\Services\Agent\ObservedTurnProcessor;
use App\Services\Agent\TurnProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Debounced grounded turn for one WhatsApp chat.
 *
 * Every inbound message dispatches a job. The job whose `msgAt` is older than
 * the buffer's `last_msg_at` is stale and exits; the newest one waits for
 * silence (or the typing grace) and then runs the agent.
 */
class ProcessWhatsAppTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // A debounce turn can be released several times while the lead is typing.
    // `tries` counts releases, so it must be much larger than the number of
    // actual exceptions; `maxExceptions` still stops a genuinely broken job.
    public int $tries = 100;

    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 15];

    public function __construct(
        public readonly string $artistId,
        public readonly string $chatId,
        public readonly string $phone,
        public readonly ?string $name,
        public readonly string $msgAt,
    ) {}

    public function handle(AgentStore $store, TurnProcessor $processor, ?DraftProcessor $draftProcessor = null, ?ObservedTurnProcessor $observedProcessor = null): void
    {
        $artist = Artist::with('settings')->find($this->artistId);

        if (! $artist) {
            return;
        }

        $mode = (string) ($artist->settings?->reply_mode ?? 'draft');
        $state = $store->bufferState($artist->id, $this->chatId);

        if ($artist->status === 'suspended' && $mode !== 'draft') {
            return;
        }

        // Never send when the calibration schema is missing: an unmigrated
        // deployment must fail safe to "no reply".
        if ($mode === 'draft' && ! Schema::hasTable('agent_drafts')) {
            Log::warning('Draft turn skipped: agent_drafts table is missing', [
                'artist_id' => $artist->id,
                'chat_id' => $this->chatId,
            ]);
            $this->recordInbound($store, (string) ($state['pending'] ?? ''));
            $store->bufferClear($artist->id, $this->chatId);

            return;
        }

        if ($mode === 'off') {
            $this->recordInbound($store, (string) ($state['pending'] ?? ''));
            $store->bufferClear($artist->id, $this->chatId);

            return;
        }

        if ($state === null || trim((string) ($state['pending'] ?? '')) === '') {
            return;
        }

        $now = microtime(true);
        $thisMsg = $this->timestamp($this->msgAt);
        $lastMsg = $this->timestamp($state['last_msg_at'] ?? null);
        $lastTyping = $this->timestamp($state['last_typing_at'] ?? null);

        // A newer message owns the reply: let its own job run.
        if ($lastMsg > $thisMsg + 0.001) {
            return;
        }

        $quietMs = (int) config('agent.debounce.quiet_ms', 6000);
        $maxWaitMs = (int) config('agent.debounce.max_wait_ms', 20000);
        $typingGraceMs = (int) config('agent.debounce.typing_grace_ms', 30000);

        // While WAHA reports the lead as typing, hold the reply. The grace cap
        // prevents a lost "paused" presence from stalling the conversation.
        $typingActive = ($state['typing_state'] ?? null) === 'typing'
            && $lastTyping > 0
            && (($now - $lastTyping) * 1000) < $typingGraceMs;

        $lastActivity = max($lastMsg, $lastTyping);
        $quietFor = ($now - $lastActivity) * 1000;
        $waitedFor = ($now - $thisMsg) * 1000;

        if (($typingActive || $quietFor < $quietMs) && $waitedFor < $maxWaitMs) {
            $this->release(1);

            return;
        }

        // In auto mode an artist takeover silences the agent. In draft mode the
        // artist is expected to answer, and we still want the suggestion.
        if ($mode === 'auto' && $store->chatOwner($artist->id, $this->chatId) === 'artist') {
            $this->recordInbound($store, (string) ($state['pending'] ?? ''));
            $store->bufferClear($artist->id, $this->chatId);

            return;
        }

        $text = $this->cleanPending((string) $state['pending']);

        if ($text === '') {
            $store->bufferClear($artist->id, $this->chatId);

            return;
        }

        try {
            if ($mode === 'observe') {
                if ($observedProcessor === null) {
                    throw new \RuntimeException('ObservedTurnProcessor is not bound.');
                }

                $result = $observedProcessor->observeInbound($artist, $this->chatId, $this->phone, $text, $this->name, $this->msgAt);
            } elseif ($mode === 'draft') {
                if ($draftProcessor === null) {
                    throw new \RuntimeException('DraftProcessor is not bound.');
                }

                $result = $draftProcessor->draft($artist, $this->chatId, $this->phone, $text, $this->name, $this->msgAt);
            } else {
                $result = $processor->process($artist, $this->chatId, $this->phone, $text, $this->name);
            }
        } catch (\Throwable $e) {
            // A credit failure is not retryable. Retrying creates duplicate
            // turns and a retry storm while the account has no balance.
            if ($this->isCreditFailure($e)) {
                Log::warning('WhatsApp agent turn skipped: AI credits exhausted', [
                    'artist_id' => $artist->id,
                    'chat_id' => $this->chatId,
                    'mode' => $mode,
                    'error' => $e->getMessage(),
                ]);
                $store->bufferClear($artist->id, $this->chatId);

                return;
            }

            Log::error('WhatsApp agent turn failed', [
                'artist_id' => $artist->id,
                'chat_id' => $this->chatId,
                'mode' => $mode,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $store->bufferClear($artist->id, $this->chatId);

        if (($result['skipped'] ?? null) === 'contact_gate') {
            Log::info('WhatsApp agent held by contact gate', [
                'artist_id' => $artist->id,
                'chat_id' => $this->chatId,
            ]);
        }
    }

    private function isCreditFailure(\Throwable $e): bool
    {
        return $e->getCode() === 402
            || str_contains($e->getMessage(), 'credits exhausted')
            || str_contains($e->getMessage(), 'HTTP 402');
    }

    /**
     * Record an inbound burst that will not reach a processor (mode off,
     * artist takeover, missing schema). The lead conversation must still show
     * the customer message.
     */
    private function recordInbound(AgentStore $store, string $pending): void
    {
        $text = trim($this->cleanPending($pending));

        if ($text === '') {
            return;
        }

        try {
            $start = $store->startTurn($this->artistId, $this->chatId, $this->phone, $this->name);

            if ($start !== []) {
                $store->rememberHuman((string) $start['lead_id'], $text, $this->msgAt);
            }
        } catch (\Throwable $e) {
            Log::warning('Inbound message write failed', [
                'artist_id' => $this->artistId,
                'chat_id' => $this->chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The burst may carry reference-photo markers added by the webhook; the
     * agent must read only the human text.
     */
    private function cleanPending(string $pending): string
    {
        $text = preg_replace('/\[\[REF_FOTO:[^\]]+\]\]/', '', $pending) ?? $pending;

        return trim(preg_replace("/[ \t]+\n/", "\n", $text) ?? $text);
    }

    private function timestamp(mixed $value): float
    {
        if ($value instanceof \DateTimeInterface) {
            return (float) $value->getTimestamp();
        }

        if (is_string($value) && $value !== '') {
            // Postgres may render the offset as "+00"; ISO expects "+00:00".
            $normalized = preg_replace('/([+-]\d{2})$/', '$1:00', $value) ?? $value;

            try {
                return (float) (new \DateTimeImmutable($normalized))->format('U.u');
            } catch (\Throwable) {
                return 0.0;
            }
        }

        return 0.0;
    }
}
