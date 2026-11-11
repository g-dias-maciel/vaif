<?php

namespace App\Services\Agent;

use App\Models\Artist;
use Illuminate\Support\Facades\Log;

class TurnProcessor
{
    public function __construct(
        private readonly AgentStore $store,
        private readonly ReplyComposer $composer,
        private readonly PromptBuilder $prompts,
        private readonly PreflightClassifier $preflight,
        private readonly MessageTransport $transport,
        private readonly ?ContactGate $gate = null,
        private readonly ?AgentMedia $media = null,
    ) {
    }

    /**
     * Run one grounded turn. `send=false` is used by the simulator/eval.
     *
     * @return array<string, mixed>
     */
    public function process(Artist $artist, string $chatId, string $phone, string $leadText, ?string $name = null, bool $send = true): array
    {
        $startedAt = microtime(true);

        $start = $this->store->startTurn($artist->id, $chatId, $phone, $name);
        if ($start === []) {
            return ['skipped' => 'start_turn_empty'];
        }

        if (! empty($start['agent_blocked'])) {
            return ['skipped' => 'agent_blocked'];
        }

        // A number saved in the artist's contacts is held on first contact
        // until the artist explicitly allows the agent to answer.
        if (! empty($start['is_new']) && $this->gate !== null && $this->gate->shouldHold($artist, $start)) {
            return ['skipped' => 'contact_gate', 'lead_id' => $start['lead_id'] ?? null];
        }

        $state = is_array($start['agent_state'] ?? null) ? $start['agent_state'] : [];

        // Audio becomes text and a reference photo becomes an analysis before
        // the classifier/agent see the turn.
        $effectiveText = $leadText;
        $photoAnalyzed = false;
        if ($this->media !== null) {
            $prepared = $this->media->prepare(
                $artist,
                (string) ($start['project_id'] ?? ''),
                $leadText,
                (string) ($start['tattoo_type'] ?? ''),
            );
            $effectiveText = $prepared['text'];

            if ($prepared['analysis'] !== null) {
                $start['reference_analysis'] = $prepared['analysis'];
                $photoAnalyzed = true;
            }
        }

        $history = $this->store->history((string) $start['lead_id']);

        $context = $this->prompts->context($artist, $start);
        $preflight = $this->preflight->classify($context, $effectiveText, $history);
        $intent = $preflight['intent'];
        $entities = $preflight['entities'];

        $this->maybeBook($artist, $start, $state, $intent, $entities);

        $facts = $this->store->facts(
            $artist->id,
            (string) $start['project_id'],
            (string) ($start['phone_e164'] ?? $phone),
            $intent,
            $entities
        );

        $facts['assumptions'] = $preflight['assumptions'] ?? [];
        $facts['turn']['flags'] = $preflight['flags'] ?? [];

        $composed = $this->composer->compose($artist, $start, $facts, $effectiveText, $history, $preflight);
        $validation = $composed['validation'];
        $originalViolations = $composed['original_violations'];
        $repaired = $composed['repaired'];

        $turnId = TurnId::make($chatId, $leadText);

        $apply = $this->store->apply([
            'artist_id' => $artist->id,
            'lead_id' => $start['lead_id'],
            'project_id' => $start['project_id'],
            'chat_id' => $chatId,
            'turn_id' => $turnId,
            'intent' => $intent,
            'flags' => $preflight['flags'],
            'entities' => $entities,
            'facts' => $facts,
            'claims' => $validation['effective_claims'],
            'reply' => $validation['final_reply'],
            'resolution' => $validation['resolution'],
            'offered_slots' => $validation['offered_slots'],
            'lead_text' => $effectiveText,
            'confidence' => $preflight['confidence'],
        ]);

        if (! empty($apply['duplicate'])) {
            // A retry after a crash between `apply` and `send` still owes the
            // lead an answer; the writes are already idempotent.
            if ($send) {
                $this->sendReply($artist, $chatId, (string) $validation['final_reply']);
            }

            return [
                'skipped' => 'duplicate',
                'turn_id' => $turnId,
                'apply' => $apply,
                'reply' => $validation['final_reply'],
            ];
        }

        // Scratch flags the prompt reads on the following turn.
        $priceClaim = false;
        foreach ($validation['effective_claims'] as $claim) {
            if (($claim['type'] ?? null) === 'price') {
                $priceClaim = true;
                break;
            }
        }

        $chatPatch = [];
        if (! empty($state['preco_liberado']) || $priceClaim) {
            $chatPatch['preco_liberado'] = true;
        }
        if (! empty($state['duvidas_eliminadas']) || ! empty($preflight['flags']['doubts_cleared'])) {
            $chatPatch['duvidas_eliminadas'] = true;
        }
        if ($photoAnalyzed) {
            $chatPatch['photo_confirmed'] = true;
        }
        if ($chatPatch !== []) {
            try {
                $this->store->patchChatState($artist->id, $chatId, $chatPatch);
            } catch (\Throwable $e) {
                Log::warning('Agent chat state patch failed', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->store->remember((string) $start['lead_id'], $effectiveText, (string) $validation['final_reply']);

        $latency = (int) round((microtime(true) - $startedAt) * 1000);

        $this->store->log([
            'turn_id' => $turnId,
            'artist_id' => $artist->id,
            'lead_id' => $start['lead_id'],
            'project_id' => $start['project_id'],
            'chat_id' => $chatId,
            'mode' => 'enforce',
            'intent' => $intent,
            'confidence' => $preflight['confidence'],
            'facts' => $facts,
            'reply' => $validation['final_reply'],
            'claims' => $validation['effective_claims'],
            'violations' => $validation['violations'],
            'resolution' => $repaired ? 'repaired' : $validation['resolution'],
            'model' => (string) config('agent.models.agent'),
            'latency_ms' => $latency,
            'repaired' => $repaired,
            'original_violations' => $originalViolations,
        ]);

        if ($send) {
            $this->sendReply($artist, $chatId, (string) $validation['final_reply']);
        }

        return [
            'turn_id' => $turnId,
            'preflight' => $preflight,
            'facts' => $facts,
            'reply' => $validation['final_reply'],
            'validation' => $validation,
            'apply' => $apply,
            'repaired' => $repaired,
            'latency_ms' => $latency,
        ];
    }

    private function maybeBook(Artist $artist, array $start, array $state, string $intent, array $entities): void
    {
        $selected = trim((string) ($entities['selected_slot_start'] ?? ''));
        if ($selected === '' || ! in_array($intent, ['choose_slot', 'reschedule', 'close'], true)) {
            return;
        }

        $offered = is_array($state['offered_slots'] ?? null) ? $state['offered_slots'] : [];
        if ($offered !== []) {
            $match = false;
            foreach ($offered as $slot) {
                if (isset($slot['start_at']) && $this->sameInstant((string) $slot['start_at'], $selected)) {
                    $match = true;
                    break;
                }
            }
            if (! $match) {
                return;
            }
        }

        $active = $start['active_booking_id'] ?? null;
        if ($active) {
            $this->store->reschedule($artist->id, (string) $active, $selected);
        } else {
            $this->store->bookSlot($artist->id, (string) $start['project_id'], $selected);
        }
    }

    private function sendReply(Artist $artist, string $chatId, string $reply): void
    {
        $reply = trim($reply);
        if ($reply === '') {
            return;
        }

        $this->transport->typing($artist, $chatId, true);

        if (config('agent.humanize', true)) {
            $words = str_word_count(strip_tags($reply));
            $seconds = min(4.0, max(1.2, 0.8 + $words / 3.2));
            usleep((int) round($seconds * 1_000_000));
        }

        foreach ($this->split($reply) as $chunk) {
            $this->transport->send($artist, $chatId, $chunk);
        }

        $this->transport->typing($artist, $chatId, false);
    }

    /**
     * @return array<int, string>
     */
    private function split(string $reply): array
    {
        $paragraphs = preg_split('/\n\s*\n+/', $reply) ?: [$reply];
        $chunks = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) <= 700) {
                $chunks[] = $paragraph;
                continue;
            }

            $sentenceChunks = preg_split('/(?<=[.!?])\s+/', $paragraph) ?: [$paragraph];
            $current = '';
            foreach ($sentenceChunks as $sentence) {
                if ($current !== '' && mb_strlen($current.' '.$sentence) > 700) {
                    $chunks[] = trim($current);
                    $current = $sentence;
                } else {
                    $current = trim($current.' '.$sentence);
                }
            }
            if ($current !== '') {
                $chunks[] = $current;
            }
        }

        return $chunks === [] ? [$reply] : $chunks;
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
