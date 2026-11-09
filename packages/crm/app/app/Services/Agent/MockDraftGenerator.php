<?php

namespace App\Services\Agent;

use App\Models\AgentDraft;
use App\Models\Artist;
use App\Models\ChatMessage;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generates a retrospective "what would Beatriz answer?" draft for customer
 * messages that do not have one. It only writes `source=simulated` rows; it
 * never sends, books, quotes or changes the conversation state.
 */
class MockDraftGenerator
{
    public function __construct(
        private readonly AgentStore $store,
        private readonly ReplyComposer $composer,
        private readonly PromptBuilder $prompts,
        private readonly PreflightClassifier $preflight,
        private readonly CalibrationConversation $conversation,
    ) {
    }

    /**
     * @return array{generated: int, skipped: int, missing: int}
     */
    public function generateForLead(Artist $artist, Lead $lead, int $limit = 20): array
    {
        $blocks = $this->conversation->forLead($lead, 200);
        $missing = collect($blocks)
            ->filter(fn ($block) => ! empty($block['message']['id']) && $block['draft'] === null)
            ->values();

        $chatId = $this->chatId($artist, $lead);
        $result = ['generated' => 0, 'skipped' => 0, 'missing' => $missing->count()];

        if ($chatId === null) {
            return ['generated' => 0, 'skipped' => $missing->count(), 'missing' => $missing->count()];
        }

        $start = $this->store->startTurn($artist->id, $chatId, (string) $lead->phone_e164, $lead->name);

        if ($start === []) {
            return ['generated' => 0, 'skipped' => $missing->count(), 'missing' => $missing->count()];
        }

        foreach ($missing->take(max(1, $limit)) as $block) {
            $message = ChatMessage::find($block['message']['id']);

            if (! $message || $message->message_type !== 'human') {
                $result['skipped']++;

                continue;
            }

            $text = trim($message->content);

            if ($text === '') {
                $result['skipped']++;

                continue;
            }

            try {
                $startedAt = microtime(true);
                $context = $this->prompts->context($artist, $start);
                $preflight = $this->preflight->classify($context, $text);
                $facts = $this->store->facts(
                    $artist->id,
                    (string) $start['project_id'],
                    (string) ($start['phone_e164'] ?? $lead->phone_e164),
                    $preflight['intent'],
                    $preflight['entities'],
                );
                $history = $this->store->history(
                    (string) $start['lead_id'],
                    8,
                    $message->created_at?->toIso8601String(),
                );

                $composed = $this->composer->compose($artist, $start, $facts, $text, $history, $preflight);
                $turnId = TurnId::make($chatId, 'sim:'.$message->id);

                AgentDraft::updateOrCreate(
                    ['artist_id' => $artist->id, 'turn_id' => $turnId],
                    [
                        'lead_id' => $start['lead_id'] ?? null,
                        'project_id' => $start['project_id'] ?? null,
                        'chat_id' => $chatId,
                        'lead_text' => $text,
                        'lead_msg_at' => $message->created_at,
                        'lead_message_id' => $message->id,
                        'source' => AgentDraft::SOURCE_SIMULATED,
                        'intent' => $preflight['intent'],
                        'intent_confidence' => $preflight['confidence'],
                        'facts' => $facts,
                        'draft_reply' => $composed['reply'],
                        'claims' => $composed['validation']['effective_claims'] ?? [],
                        'validation' => array_merge($composed['validation'], [
                            'original_violations' => $composed['original_violations'],
                            'repaired' => $composed['repaired'],
                        ]),
                        'model' => (string) config('agent.models.agent'),
                        'prompt_version' => PromptVersion::current(),
                        'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                        'status' => AgentDraft::STATUS_SIMULATED,
                    ],
                );

                $result['generated']++;
            } catch (\Throwable $e) {
                Log::warning('Mock draft generation failed', [
                    'artist_id' => $artist->id,
                    'lead_id' => $lead->id,
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
                $result['skipped']++;
            }
        }

        return $result;
    }

    private function chatId(Artist $artist, Lead $lead): ?string
    {
        try {
            $chatId = DB::table('chat_sessions')
                ->where('artist_id', $artist->id)
                ->where('lead_id', $lead->id)
                ->orderByDesc('updated_at')
                ->value('chat_id');
        } catch (\Throwable $e) {
            return null;
        }

        return $chatId ? (string) $chatId : null;
    }
}
