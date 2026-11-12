<?php

namespace App\Services\Agent;

use App\Models\Artist;
use App\Models\ChatMessage;
use App\Models\ConversationAudit;
use App\Models\Lead;

/**
 * Post-hoc audit of a whole conversation against the VAIF methodology.
 *
 * The audit is staff-only and never reaches the Lead or the Artist. It is
 * idempotent by transcript hash: the same conversation is not sent to the
 * model twice, and a new message produces a new hash.
 */
class ConversationAuditor
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly AgentStore $store,
    ) {}

    /**
     * @return array{lines: array<int, string>, hash: string, message_count: int, last_message_at: ?string}|null
     */
    public function prepare(Artist $artist, Lead $lead): ?array
    {
        $limit = (int) config('agent.audit.max_messages', 80);
        $history = $this->store->history((string) $lead->id, max(4, $limit));

        $lines = [];
        foreach ($history as $message) {
            $content = trim((string) ($message['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            $lines[] = (($message['role'] ?? 'user') === 'assistant' ? 'Estúdio' : 'Lead').': '.$content;
        }

        if (count($lines) < max(2, (int) config('agent.audit.min_messages', 6))) {
            return null;
        }

        $last = null;
        try {
            $last = ChatMessage::latestTimesForLeads([(string) $lead->id])[(string) $lead->id] ?? null;
        } catch (\Throwable $e) {
            // The transcript is Postgres-only; a missing timestamp is fine.
            $last = null;
        }

        return [
            'lines' => $lines,
            'hash' => hash('sha256', implode("\n", $lines)),
            'message_count' => count($lines),
            'last_message_at' => $last !== null ? $last->toIso8601String() : null,
        ];
    }

    /**
     * @param  array{lines: array<int, string>, hash: string, message_count: int, last_message_at: ?string}  $prepared
     * @return array<string, mixed>
     */
    public function review(Artist $artist, Lead $lead, array $prepared): array
    {
        $system = (string) file_get_contents(resource_path('prompts/audit-conversation.md'));
        $model = (string) config('agent.models.audit');

        $content = $this->ai->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => 'TRANSCRITO (mais antigo primeiro):'."\n".implode("\n", $prepared['lines'])],
        ], $model);

        $parsed = Json::parse($content);
        $steps = $this->steps(is_array($parsed['steps'] ?? null) ? $parsed['steps'] : []);
        $score = $this->score($steps);

        return [
            'transcript_hash' => $prepared['hash'],
            'message_count' => $prepared['message_count'],
            'last_message_at' => $prepared['last_message_at'],
            'steps' => $steps,
            'score' => $score,
            'summary' => mb_substr(trim((string) ($parsed['summary'] ?? '')), 0, 600),
            'model' => $model,
        ];
    }

    /**
     * @param  array<int, mixed>  $raw
     * @return array<int, array<string, mixed>>
     */
    private function steps(array $raw): array
    {
        $byKey = [];

        foreach ($raw as $step) {
            if (is_array($step) && isset($step['key'])) {
                $byKey[(string) $step['key']] = $step;
            }
        }

        $out = [];

        foreach (ConversationAudit::STEP_LABELS as $key => $label) {
            $step = $byKey[$key] ?? [];
            $status = (string) ($step['status'] ?? ConversationAudit::STATUS_NA);

            if (! in_array($status, [
                ConversationAudit::STATUS_PASS,
                ConversationAudit::STATUS_FAIL,
                ConversationAudit::STATUS_NA,
            ], true)) {
                $status = ConversationAudit::STATUS_NA;
            }

            $evidence = trim((string) ($step['evidence'] ?? ''));
            $note = trim((string) ($step['note'] ?? ''));

            if ($status === ConversationAudit::STATUS_FAIL && $evidence === '') {
                $evidence = $note !== '' ? $note : '(sem trecho literal)';
            }

            $out[] = [
                'key' => $key,
                'label' => $label,
                'status' => $status,
                'evidence' => mb_substr($evidence, 0, 400),
                'note' => mb_substr($note, 0, 300),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function score(array $steps): float
    {
        $pass = 0;
        $fail = 0;

        foreach ($steps as $step) {
            if (($step['status'] ?? null) === ConversationAudit::STATUS_PASS) {
                $pass++;
            } elseif (($step['status'] ?? null) === ConversationAudit::STATUS_FAIL) {
                $fail++;
            }
        }

        $applicable = $pass + $fail;

        return $applicable > 0 ? round($pass / $applicable * 100, 1) : 0.0;
    }
}
