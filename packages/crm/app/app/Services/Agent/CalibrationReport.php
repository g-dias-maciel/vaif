<?php

namespace App\Services\Agent;

use App\Models\AgentDraft;
use App\Models\Artist;

/**
 * Calibration scoreboard and graduation gates for copilot mode.
 *
 * Hard gates block auto-send; intent coverage is a warning so a low-volume
 * artist is visible without making the gate impossible.
 */
class CalibrationReport
{
    /**
     * @return array<string, mixed>
     */
    public function forArtist(Artist $artist, int $days = 30): array
    {
        try {
            $all = AgentDraft::query()
                ->where('artist_id', $artist->id)
                ->where('source', '!=', AgentDraft::SOURCE_SIMULATED)
                ->where('created_at', '>=', now()->subDays(max(1, $days)))
                ->orderBy('created_at')
                ->get();
        } catch (\Throwable $e) {
            // The calibration migration may not have been applied yet.
            return $this->emptyReport($artist, $days);
        }

        $eligible = $all->reject(fn (AgentDraft $d) => $d->status === AgentDraft::STATUS_SUPERSEDED);
        $answered = $eligible->filter(fn (AgentDraft $d) => $d->artist_reply !== null);
        $compared = $eligible->filter(
            fn (AgentDraft $d) => $d->status === AgentDraft::STATUS_COMPARED && is_array($d->comparison)
        );

        $acceptable = $compared->filter(fn (AgentDraft $d) => $d->wasAccepted() === true)->count();
        $covered = $compared->filter(
            fn (AgentDraft $d) => (bool) ($d->comparison['judge']['covered_lead_question'] ?? false)
        )->count();
        $tones = $compared
            ->map(fn (AgentDraft $d) => $d->comparison['judge']['tone'] ?? null)
            ->filter(fn ($tone) => $tone !== null);
        $missingHandoff = $compared->filter(
            fn (AgentDraft $d) => (bool) ($d->comparison['judge']['missing_handoff'] ?? false)
        )->count();
        $unsafeDrafts = $eligible->filter(fn (AgentDraft $d) => ! $this->draftSafe($d))->count();
        $artistViolations = $compared->filter(
            fn (AgentDraft $d) => ! empty($d->artist_validation['violations'] ?? [])
        )->count();

        $eligibleCount = $eligible->count();
        $answeredCount = $answered->count();
        $comparedCount = $compared->count();

        $responseRate = $eligibleCount > 0 ? $answeredCount / $eligibleCount : 0.0;
        $acceptableRate = $comparedCount > 0 ? $acceptable / $comparedCount : 0.0;
        $coveredRate = $comparedCount > 0 ? $covered / $comparedCount : 0.0;

        $intents = $eligible->groupBy(fn (AgentDraft $d) => $d->intent ?: 'unknown')
            ->map(fn ($rows) => $rows->count())
            ->sortDesc()
            ->all();

        $verdicts = $compared->groupBy(fn (AgentDraft $d) => $d->judgeVerdict() ?: 'unknown')
            ->map(fn ($rows) => $rows->count())
            ->all();

        $timings = $answered->pluck('response_seconds')->filter(fn ($v) => $v !== null)->sort()->values();
        $medianResponse = $timings->isEmpty() ? null : (int) $timings[(int) floor(($timings->count() - 1) / 2)];

        $cal = (array) config('agent.calibration', []);
        $minCompared = (int) ($cal['min_compared'] ?? 40);
        $minAcceptable = (float) ($cal['min_acceptable_rate'] ?? 0.9);
        $minResponse = (float) ($cal['min_response_rate'] ?? 0.9);
        $coreIntents = (array) ($cal['core_intents'] ?? []);
        $minPerIntent = (int) ($cal['min_per_intent'] ?? 5);

        $gates = [
            $this->gate('sample', 'Pares comparados', $comparedCount, '>= '.$minCompared, $comparedCount >= $minCompared, true),
            $this->gate('factual', 'Rascunhos sem fato inventado', $unsafeDrafts, '= 0', $unsafeDrafts === 0, true),
            $this->gate('handoff', 'Handoffs perdidos', $missingHandoff, '= 0', $missingHandoff === 0, true),
            $this->gate('acceptance', 'Rascunhos aceitáveis', round($acceptableRate, 2), '>= '.$minAcceptable, $acceptableRate >= $minAcceptable, true),
            $this->gate('response', 'Drafts respondidos', round($responseRate, 2), '>= '.$minResponse, $responseRate >= $minResponse, true),
        ];

        $warnings = [];
        foreach ($coreIntents as $intent => $target) {
            $target = (int) $target ?: $minPerIntent;
            $count = (int) ($intents[$intent] ?? 0);
            if ($count < $target) {
                $warnings[] = "Cobertura de '{$intent}': {$count}/{$target}";
            }
        }

        return [
            'artist_id' => $artist->id,
            'artist' => $artist->name,
            'reply_mode' => $artist->settings?->reply_mode ?? 'draft',
            'days' => $days,
            'totals' => [
                'drafts' => $all->count(),
                'eligible' => $eligibleCount,
                'answered' => $answeredCount,
                'compared' => $comparedCount,
                'pending' => $all->where('status', AgentDraft::STATUS_PENDING)->count(),
                'replied' => $all->where('status', AgentDraft::STATUS_REPLIED)->count(),
                'expired' => $all->where('status', AgentDraft::STATUS_EXPIRED)->count(),
                'superseded' => $all->where('status', AgentDraft::STATUS_SUPERSEDED)->count(),
                'unsafe_drafts' => $unsafeDrafts,
                'artist_violations' => $artistViolations,
                'median_response_seconds' => $medianResponse,
            ],
            'rates' => [
                'response' => round($responseRate, 3),
                'acceptable' => round($acceptableRate, 3),
                'covered_question' => round($coveredRate, 3),
                'tone_avg' => $tones->isEmpty() ? null : round((float) $tones->avg(), 2),
            ],
            'intents' => $intents,
            'verdicts' => $verdicts,
            'prompt_versions' => $eligible->groupBy('prompt_version')->map->count()->all(),
            'gates' => $gates,
            'warnings' => $warnings,
            'passed' => collect($gates)->every(fn ($gate) => ! $gate['hard'] || $gate['pass']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyReport(Artist $artist, int $days): array
    {
        return [
            'artist_id' => $artist->id,
            'artist' => $artist->name,
            'reply_mode' => $artist->settings?->reply_mode ?? 'draft',
            'days' => $days,
            'totals' => [
                'drafts' => 0,
                'eligible' => 0,
                'answered' => 0,
                'compared' => 0,
                'pending' => 0,
                'replied' => 0,
                'expired' => 0,
                'superseded' => 0,
                'unsafe_drafts' => 0,
                'artist_violations' => 0,
                'median_response_seconds' => null,
            ],
            'rates' => [
                'response' => 0.0,
                'acceptable' => 0.0,
                'covered_question' => 0.0,
                'tone_avg' => null,
            ],
            'intents' => [],
            'verdicts' => [],
            'prompt_versions' => [],
            'gates' => [],
            'warnings' => [],
            'passed' => false,
        ];
    }

    /**
     * A draft is safe when its final reply is accepted or canonically rebuilt.
     * A canonical reply can still carry the original violation (for audit), but
     * it was never the text that would be sent.
     */
    private function draftSafe(AgentDraft $draft): bool
    {
        $validation = is_array($draft->validation) ? $draft->validation : [];

        if (! empty($validation['ok'])) {
            return true;
        }

        if (! empty($validation['repaired'])) {
            return true;
        }

        $resolution = (string) ($validation['resolution'] ?? '');

        return str_starts_with($resolution, 'canonical_');
    }

    /**
     * @return array<string, mixed>
     */
    private function gate(string $key, string $label, mixed $actual, string $target, bool $pass, bool $hard): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'actual' => $actual,
            'target' => $target,
            'pass' => $pass,
            'hard' => $hard,
        ];
    }
}
