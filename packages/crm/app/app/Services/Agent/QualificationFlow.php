<?php

namespace App\Services\Agent;

/**
 * Glue between the model's pending-field answer and the deterministic
 * qualification state.
 */
class QualificationFlow
{
    /**
     * @param  array<string, mixed>  $state
     * @return array{field: string, question: string}|null
     */
    public static function pendingInput(array $state): ?array
    {
        $field = $state['pending_field'] ?? null;

        if (! is_string($field) || $field === '') {
            return null;
        }

        return [
            'field' => $field,
            'question' => (string) ($state['pending_question'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>|null  $pending
     * @return array{skipped: array<int, string>, attempts: array<string, int>, stuck: bool}
     */
    public static function applyPending(array $state, ?array $pending): array
    {
        $skipped = self::skipped($state);
        $attempts = is_array($state['pending_attempts'] ?? null) ? $state['pending_attempts'] : [];
        $field = $pending['field'] ?? null;
        $status = $pending['status'] ?? null;

        if (! is_string($field) || $field === '' || ! is_string($status)) {
            return ['skipped' => $skipped, 'attempts' => $attempts, 'stuck' => false];
        }

        if ($status === 'answered') {
            unset($attempts[$field]);
        } elseif (in_array($status, ['unclear', 'off_topic'], true)) {
            $attempts[$field] = (int) ($attempts[$field] ?? 0) + 1;
        } elseif ($status === 'declined') {
            if (in_array($field, ['meaning', 'first_tattoo'], true)) {
                $skipped[] = $field;
                unset($attempts[$field]);
            } else {
                $attempts[$field] = (int) ($attempts[$field] ?? 0) + 1;
            }
        }

        $stuck = ! in_array($field, $skipped, true)
            && (int) ($attempts[$field] ?? 0) >= 2;

        return [
            'skipped' => array_values(array_unique($skipped)),
            'attempts' => $attempts,
            'stuck' => $stuck,
        ];
    }

    /**
     * The pending field for the next turn, based on what the final reply
     * actually asked.
     *
     * @param  array<string, mixed>  $project
     * @param  array<string, array{value: mixed, confidence: float}>  $assumptions
     * @param  array<string, mixed>  $state
     * @return array{pending_field: ?string, pending_question: ?string, pending_attempts: array<string, int>, skipped_fields: array<int, string>}
     */
    public static function afterReply(
        array $project,
        array $assumptions,
        array $state,
        string $reply,
        string $resolution,
    ): array {
        $skipped = self::skipped($state);
        $attempts = is_array($state['pending_attempts'] ?? null) ? $state['pending_attempts'] : [];
        $qualification = new QualificationState($project, $assumptions, $skipped, $project['tattoo_type'] ?? null);
        $next = $qualification->nextField();

        $handoff = in_array($resolution, [
            'canonical_cover_up',
            'canonical_coverup_refused',
            'canonical_cover_handoff',
            'canonical_aftercare',
            'canonical_defer',
            'canonical_price_unknown',
        ], true) || str_starts_with($resolution, 'canonical_cover');

        if ($next === null || $handoff || ! $qualification->asks($reply, $next)) {
            return [
                'pending_field' => null,
                'pending_question' => null,
                'pending_attempts' => $attempts,
                'skipped_fields' => $skipped,
            ];
        }

        return [
            'pending_field' => $next,
            'pending_question' => $qualification->questionFor($next),
            'pending_attempts' => $attempts,
            'skipped_fields' => $skipped,
        ];
    }

    public static function explainedProcess(string $reply): bool
    {
        $reply = \Illuminate\Support\Str::ascii(mb_strtolower($reply));

        return preg_match('/processo de criacao|projeto exclusivo|vai sentar (junto )?com voce|encaixe perfeito/u', $reply) === 1;
    }

    public static function askedAboutDoubts(string $reply): bool
    {
        $reply = \Illuminate\Support\Str::ascii(mb_strtolower($reply));

        return preg_match('/alguma duvida|ficou .{0,20}duvida|duvidas sobre o processo/u', $reply) === 1;
    }

    /**
     * A price lookup was possible for this placement/size, but the artist has
     * no row. In that case the agent must hand off instead of continuing
     * discovery.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function priceMissing(array $facts): bool
    {
        $project = is_array($facts['project'] ?? null) ? $facts['project'] : [];
        $pricing = is_array($facts['pricing'] ?? null) ? $facts['pricing'] : [];
        $type = $project['tattoo_type'] ?? null;

        if (in_array($type, ['cover_up', 'rework'], true)) {
            return false;
        }

        return ! empty($project['placement'])
            && ! empty($project['body_zone'])
            && ($pricing['status'] ?? null) !== 'ready';
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<int, string>
     */
    private static function skipped(array $state): array
    {
        $skipped = $state['skipped_fields'] ?? [];

        return is_array($skipped)
            ? array_values(array_filter($skipped, 'is_string'))
            : [];
    }
}
