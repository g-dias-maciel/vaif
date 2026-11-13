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
        private readonly ?AgentStore $store = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function compare(AgentDraft $draft): array
    {
        $facts = is_array($draft->facts) ? $draft->facts : [];
        $artistReply = trim((string) $draft->artist_reply);
        $mediaNote = MediaNote::dedupe((string) $draft->artist_media_note);

        // Staff can describe an image/video the artist sent. The description is
        // the comparable answer; the original placeholder is kept for audit.
        $comparableText = $mediaNote !== '' && MessageText::isMediaOnly($artistReply)
            ? $mediaNote
            : $artistReply;
        $fromMediaNote = $comparableText !== $artistReply;

        $artistClaims = [];
        $artistValidation = [];

        if ($comparableText !== '') {
            try {
                $artistClaims = $this->claims->extract($comparableText, $facts);
                $artistValidation = $this->validator->validate($facts, $artistClaims, $comparableText);
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

        // The artist answers many turns with an image or a short emoji. There
        // is nothing to compare, so keep the draft out of the acceptance and
        // factual denominators instead of scoring a `tie` by default.
        $comparable = ! MessageText::isMediaOnly($comparableText);

        $judge = $comparable ? $this->judge($draft, $deterministic, $comparableText, $fromMediaNote) : null;

        $comparison = [
            'comparable' => $comparable,
            'reason' => $comparable
                ? ($fromMediaNote ? 'artist_media_note' : null)
                : 'artist_reply_media_only',
            'artist_media_note' => $mediaNote !== '' ? $mediaNote : null,
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
    /**
     * Judge a hypothetical reply against the artist reply without writing to
     * the database. Used by the real-conversation regression suite.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<string, mixed>
     */
    public function review(
        array $facts,
        string $leadText,
        string $draftReply,
        string $artistReply,
        array $history = [],
    ): array {
        $draft = new AgentDraft([
            'facts' => $facts,
            'lead_text' => $leadText,
            'draft_reply' => $draftReply,
            'artist_reply' => $artistReply,
        ]);

        $artistClaims = [];
        $artistValidation = [];

        if (trim($artistReply) !== '') {
            try {
                $artistClaims = $this->claims->extract($artistReply, $facts);
                $artistValidation = $this->validator->validate($facts, $artistClaims, $artistReply);
            } catch (\Throwable $e) {
                Log::warning('Regression artist validation failed', ['error' => $e->getMessage()]);
            }
        }

        $deterministic = [
            'ok' => (bool) ($artistValidation['ok'] ?? false),
            'violations' => $this->rules($artistValidation['violations'] ?? []),
            'claims' => $artistClaims,
        ];

        $comparable = ! MessageText::isMediaOnly($artistReply);
        $judge = $comparable
            ? $this->judge($draft, $deterministic, $artistReply, false, $this->formatHistory($history))
            : null;

        return [
            'comparable' => $comparable,
            'deterministic' => $deterministic,
            'judge' => $judge,
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     */
    private function formatHistory(array $history): string
    {
        $lines = [];

        foreach ($history as $message) {
            $content = trim((string) ($message['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            $lines[] = (($message['role'] ?? 'user') === 'assistant' ? 'Estúdio' : 'Lead').': '.$content;
        }

        return $lines === [] ? '_(sem histórico)_' : implode("\n", $lines);
    }

    private function judge(AgentDraft $draft, array $deterministic, string $artistReply, bool $fromMediaNote, ?string $historyText = null): ?array
    {
        $system = (string) file_get_contents(resource_path('prompts/compare-draft.md'));

        $replyLabel = $fromMediaNote
            ? 'DESCRIÇÃO DA MÍDIA ENVIADA PELO ARTISTA (preenchida pela equipe)'
            : 'RESPOSTA REAL DO ARTISTA';

        try {
            $content = $this->ai->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'FATOS:\n'.Json::string($draft->facts)
                    .'\n\nHISTÓRICO DA CONVERSA (mais antigo primeiro):\n'.($historyText ?? $this->history($draft))
                    .'\n\nMENSAGEM DO LEAD:\n'.$draft->lead_text
                    .'\n\nRASCUNHO DA BEATRIZ (não enviado):\n'.$draft->draft_reply
                    .'\n\n'.$replyLabel.':\n'.$artistReply
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
     * The conversation before this turn. The judge must know what the lead has
     * already answered, otherwise it flags legitimate memory as invention.
     */
    private function history(AgentDraft $draft): string
    {
        if ($this->store === null || $draft->lead_id === null || $draft->lead_msg_at === null) {
            return '_(sem histórico)_';
        }

        try {
            $messages = $this->store->history(
                (string) $draft->lead_id,
                16,
                $draft->lead_msg_at->toIso8601String(),
            );
        } catch (\Throwable $e) {
            return '_(sem histórico)_';
        }

        $current = trim((string) $draft->lead_text);
        $lines = [];

        foreach ($messages as $message) {
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '' || $content === $current) {
                continue;
            }

            $role = ($message['role'] ?? 'user') === 'assistant' ? 'Estúdio' : 'Lead';
            $lines[] = $role.': '.$content;
        }

        return $lines === [] ? '_(sem histórico)_' : implode("\n", $lines);
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
