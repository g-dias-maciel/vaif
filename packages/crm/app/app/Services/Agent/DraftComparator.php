<?php

namespace App\Services\Agent;

use App\Models\AgentDraft;
use Illuminate\Support\Facades\Log;

/**
 * Scores a draft against the artist's actual reply.
 *
 * Deterministic half: extract claims from the artist reply and run the same
 * validator against the draft facts. LLM half: a judge with the methodology
 * rubric. The artist is the authority on style; the methodology is the bar.
 */
class DraftComparator
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly ClaimExtractor $claims,
        private readonly ReplyValidator $validator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function compare(AgentDraft $draft): array
    {
        $facts = is_array($draft->facts) ? $draft->facts : [];
        $artistReply = trim((string) $draft->artist_reply);

        $artistClaims = [];
        $artistValidation = [];

        if ($artistReply !== '') {
            try {
                $artistClaims = $this->claims->extract($artistReply, $facts);
                $artistValidation = $this->validator->validate($facts, $artistClaims, $artistReply);
            } catch (\Throwable $e) {
                Log::warning('Artist reply validation failed', [
                    'draft_id' => $draft->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $deterministic = [
            'ok' => (bool) ($artistValidation['ok'] ?? false),
            'violations' => $this->rules($artistValidation['violations'] ?? []),
            'claims' => $artistClaims,
        ];

        $judge = $this->judge($draft, $deterministic);

        $comparison = [
            'deterministic' => $deterministic,
            'judge' => $judge,
            'compared_at' => now()->toIso8601String(),
        ];

        $draft->forceFill([
            'artist_claims' => $artistClaims,
            'artist_validation' => $artistValidation,
            'comparison' => $comparison,
            'compared_at' => now(),
            'status' => AgentDraft::STATUS_COMPARED,
        ])->save();

        return $comparison;
    }

    /**
     * @param  array<string, mixed>  $deterministic
     * @return array<string, mixed>|null
     */
    private function judge(AgentDraft $draft, array $deterministic): ?array
    {
        $system = (string) file_get_contents(resource_path('prompts/compare-draft.md'));

        try {
            $content = $this->ai->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'FATOS:\n'.Json::string($draft->facts)
                    .'\n\nMENSAGEM DO LEAD:\n'.$draft->lead_text
                    .'\n\nRASCUNHO DA BEATRIZ (não enviado):\n'.$draft->draft_reply
                    .'\n\nRESPOSTA REAL DO ARTISTA:\n'.(string) $draft->artist_reply
                    .'\n\nVIOLAÇÕES DETERMINÍSTICAS DA RESPOSTA DO ARTISTA:\n'.Json::string($deterministic)],
            ], (string) config('agent.models.judge'));
        } catch (\Throwable $e) {
            Log::warning('Draft judge failed', ['draft_id' => $draft->id, 'error' => $e->getMessage()]);

            return null;
        }

        $parsed = Json::parse($content);

        if ($parsed === []) {
            return null;
        }

        $verdict = (string) ($parsed['verdict'] ?? 'unknown');

        return [
            'acceptable' => (bool) ($parsed['acceptable'] ?? false),
            'covered_lead_question' => (bool) ($parsed['covered_lead_question'] ?? false),
            'missing' => $this->strings($parsed['missing'] ?? []),
            'factual_conflicts' => $this->strings($parsed['factual_conflicts'] ?? []),
            'missing_handoff' => (bool) ($parsed['missing_handoff'] ?? false),
            'tone' => max(1, min(5, (int) ($parsed['tone'] ?? 3))),
            'verdict' => in_array($verdict, ['draft_better', 'artist_better', 'tie'], true) ? $verdict : 'unknown',
            'rationale' => trim((string) ($parsed['rationale'] ?? '')),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function rules(array $violations): array
    {
        return array_values(array_filter(array_map(
            fn ($violation) => is_array($violation)
                ? (string) ($violation['rule'] ?? '')
                : (string) $violation,
            $violations,
        )));
    }

    /**
     * @return array<int, string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => trim((string) $item),
            $value,
        )));
    }
}
