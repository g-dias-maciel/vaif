<?php

namespace App\Services\Agent;

use App\Jobs\CompareDraft;
use App\Models\Artist;
use Illuminate\Support\Facades\Log;

/**
 * Copilot path: run the real generation + validation pipeline, store the draft,
 * and never apply backend actions or send anything.
 */
class DraftProcessor
{
    public function __construct(
        private readonly AgentStore $store,
        private readonly ReplyComposer $composer,
        private readonly PromptBuilder $prompts,
        private readonly PreflightClassifier $preflight,
        private readonly DraftStore $drafts,
        private readonly ?AgentMedia $media = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function draft(
        Artist $artist,
        string $chatId,
        string $phone,
        string $leadText,
        ?string $name,
        string $msgAt = '',
    ): array {
        $startedAt = microtime(true);
        $turnId = TurnId::make($chatId, $leadText);

        if ($this->drafts->findByTurn($artist->id, $turnId) !== null) {
            return ['skipped' => 'duplicate_draft', 'turn_id' => $turnId];
        }

        $start = $this->store->startTurn($artist->id, $chatId, $phone, $name);
        if ($start === []) {
            return ['skipped' => 'start_turn_empty', 'turn_id' => $turnId];
        }

        if (! empty($start['agent_blocked'])) {
            return ['skipped' => 'agent_blocked', 'turn_id' => $turnId, 'lead_id' => $start['lead_id'] ?? null];
        }

        $state = is_array($start['agent_state'] ?? null) ? $start['agent_state'] : [];

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

        // History before this turn: the classifier needs it to resolve short
        // answers and to know which discovery fields are already established.
        $history = $this->store->history((string) $start['lead_id'], 8, $msgAt);

        $context = $this->prompts->context($artist, $start);
        $preflight = $this->preflight->classify($context, $effectiveText, $history);
        $intent = $preflight['intent'];
        $entities = $preflight['entities'];

        $facts = $this->store->facts(
            $artist->id,
            (string) $start['project_id'],
            (string) ($start['phone_e164'] ?? $phone),
            $intent,
            $entities,
        );

        [$facts, $simulated] = $this->simulate($facts, $start, $intent, $entities);

        // What the AI inferred from the context. Used only to phrase a
        // confirmation question; it is never written to the project.
        $facts['assumptions'] = $preflight['assumptions'] ?? [];

        // Build the draft as of the lead message time. If the artist already
        // answered while the draft was generating, that future message must
        // not influence the draft (nor bias the comparison).
        $composed = $this->composer->compose($artist, $start, $facts, $effectiveText, $history, $preflight);

        $draft = $this->drafts->create([
            'artist_id' => $artist->id,
            'lead_id' => $start['lead_id'] ?? null,
            'project_id' => $start['project_id'] ?? null,
            'chat_id' => $chatId,
            'turn_id' => $turnId,
            'lead_text' => $effectiveText,
            'lead_msg_at' => $this->timestamp($msgAt),
            'intent' => $intent,
            'intent_confidence' => $preflight['confidence'],
            'facts' => $facts,
            'draft_reply' => $composed['reply'],
            'claims' => $composed['validation']['effective_claims'] ?? [],
            'validation' => array_merge($composed['validation'], [
                'original_violations' => $composed['original_violations'],
                'repaired' => $composed['repaired'],
            ]),
            'simulated_actions' => $simulated,
            'model' => (string) config('agent.models.agent'),
            'prompt_version' => PromptVersion::current(),
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'status' => 'pending',
        ]);

        // The draft does not enter the live conversation as an AI message. The
        // lead message does, so the next draft has the real context.
        try {
            $this->store->rememberHuman((string) $start['lead_id'], $effectiveText, $msgAt);
        } catch (\Throwable $e) {
            Log::warning('Draft human message write failed', [
                'lead_id' => $start['lead_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        if ($photoAnalyzed) {
            try {
                $this->store->patchChatState($artist->id, $chatId, ['photo_confirmed' => true]);
            } catch (\Throwable $e) {
                Log::warning('Draft chat state patch failed', ['error' => $e->getMessage()]);
            }
        }

        $this->pairExistingArtistReply($draft, $msgAt);

        return [
            'draft_id' => $draft->id,
            'turn_id' => $turnId,
            'intent' => $intent,
            'reply' => $draft->draft_reply,
            'validation' => $composed['validation'],
            'simulated_actions' => $simulated,
            'latency_ms' => $draft->latency_ms,
        ];
    }

    /**
     * A draft can take longer than the artist. If the artist already answered
     * the same lead message, attach that answer instead of losing the pair.
     */
    private function pairExistingArtistReply($draft, string $msgAt): void
    {
        if ($msgAt === '' || $draft->lead_id === null || $this->drafts->hasNewerLeadMessage($draft)) {
            return;
        }

        $artistMessage = $this->store->latestArtistMessageAfter((string) $draft->lead_id, $msgAt);

        if ($artistMessage === null || trim((string) ($artistMessage['content'] ?? '')) === '') {
            return;
        }

        $this->drafts->attachArtistReply($draft, (string) $artistMessage['content']);
        CompareDraft::dispatch((string) $draft->id)
            ->delay(now()->addSeconds(10))
            ->onQueue((string) config('agent.queue', 'agent'));
    }

    /**
     * Simulate the backend action the auto path would have taken, without
     * writing anything. The reply can then mention the booked slot/deposit the
     * same way auto mode would.
     *
     * @return array{0: array, 1: array}
     */
    private function simulate(array $facts, array $start, string $intent, array $entities): array
    {
        $simulated = [];
        $selected = trim((string) ($entities['selected_slot_start'] ?? ''));

        if ($selected !== '' && in_array($intent, ['choose_slot', 'reschedule', 'close'], true)) {
            $offered = is_array($start['agent_state']['offered_slots'] ?? null)
                ? $start['agent_state']['offered_slots']
                : [];

            $matches = $offered === [];
            foreach ($offered as $slot) {
                if (isset($slot['start_at']) && $this->sameInstant((string) $slot['start_at'], $selected)) {
                    $matches = true;
                    break;
                }
            }

            if ($matches) {
                $facts['booking']['created_this_turn'] = true;
                $facts['booking']['active'] = true;
                $facts['booking']['start_at'] = $selected;
                $simulated['booking'] = [
                    'start_at' => $selected,
                    'rescheduled' => ! empty($start['active_booking_id']),
                ];
            }
        }

        $pricing = $facts['pricing'] ?? [];
        if (($pricing['status'] ?? null) === 'ready') {
            $simulated['price_cents'] = ! empty($pricing['open_quote'])
                ? ($pricing['offered_cents'] ?? null)
                : ($pricing['promotion']['price_cents'] ?? $pricing['base_cents'] ?? $pricing['offered_cents'] ?? null);

            if (! empty($pricing['deposit_cents'])) {
                $simulated['deposit_cents'] = (int) $pricing['deposit_cents'];
            }
        }

        return [$facts, $simulated];
    }

    private function timestamp(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $normalized = preg_replace('/([+-]\d{2})$/', '$1:00', $value) ?? $value;

        try {
            return new \DateTimeImmutable($normalized);
        } catch (\Throwable) {
            return null;
        }
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
