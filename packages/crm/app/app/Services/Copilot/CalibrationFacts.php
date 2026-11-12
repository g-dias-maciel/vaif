<?php

namespace App\Services\Copilot;

use App\Models\AgentDraft;
use App\Models\Artist;
use App\Models\ConversationAudit;
use App\Services\Agent\CalibrationReport;
use App\Services\Agent\MessageText;
use Illuminate\Support\Carbon;

/**
 * Deterministic calibration facts for the weekly Copilot report.
 */
class CalibrationFacts
{
    public function __construct(private readonly CalibrationReport $calibration) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $now = now();
        $days = (int) config('copilot.windows.calibration_days', 7);
        $gatesDays = (int) config('copilot.windows.gates_days', 30);
        $since = $now->copy()->subDays($days);

        $artists = Artist::with('settings')->orderBy('name')->get();
        $rows = [];
        $totals = [
            'drafts' => 0,
            'compared' => 0,
            'unacceptable' => 0,
            'stuck_pending' => 0,
            'stuck_replied' => 0,
        ];

        foreach ($artists as $artist) {
            $drafts = $this->drafts($artist, $since);
            $comparedAll = $drafts->filter(fn (AgentDraft $d) => $d->status === AgentDraft::STATUS_COMPARED);
            $compared = $comparedAll->filter(function (AgentDraft $d) {
                $text = trim((string) $d->artist_media_note) !== ''
                    ? (string) $d->artist_media_note
                    : (string) $d->artist_reply;
                $flag = $d->comparison['comparable'] ?? ! MessageText::isMediaOnly($text);

                return $flag !== false;
            });
            $unacceptable = $compared->filter(fn (AgentDraft $d) => $d->wasAccepted() === false);

            $gates = $this->calibration->forArtist($artist, $gatesDays);
            $row = [
                'artist' => $artist->name,
                'reply_mode' => $gates['reply_mode'],
                'gates' => [
                    'passed' => $gates['passed'],
                    'compared' => $gates['totals']['compared'],
                    'acceptable_rate' => $gates['rates']['acceptable'],
                    'response_rate' => $gates['rates']['response'],
                    'covered_rate' => $gates['rates']['covered_question'],
                    'warnings' => $gates['warnings'],
                    'items' => $gates['gates'],
                ],
                'drafts' => [
                    'total' => $drafts->count(),
                    'compared' => $compared->count(),
                    'media_only' => $comparedAll->count() - $compared->count(),
                    'pending' => $drafts->where('status', AgentDraft::STATUS_PENDING)->count(),
                    'replied' => $drafts->where('status', AgentDraft::STATUS_REPLIED)->count(),
                    'unacceptable' => $unacceptable->count(),
                    'without_claims' => $drafts->filter(fn (AgentDraft $d) => empty($d->claims))->count(),
                ],
                'top_violation_rules' => $this->topRules($drafts, 'draft'),
                'top_artist_rules' => $this->topRules($drafts, 'artist'),
                'top_missing' => $this->topMissing($compared),
                'intents' => $this->intents($drafts, $compared),
                'samples' => $unacceptable->take(3)->map(fn (AgentDraft $d) => $this->sample($d))->values()->all(),
                'prompt_versions' => $drafts->groupBy('prompt_version')->map->count()->all(),
                'stuck' => $this->stuck($artist, $now),
                'audit' => $this->audit($artist, $since),
            ];

            $totals['drafts'] += $row['drafts']['total'];
            $totals['compared'] += $row['drafts']['compared'];
            $totals['unacceptable'] += $row['drafts']['unacceptable'];
            $totals['stuck_pending'] += $row['stuck']['pending'];
            $totals['stuck_replied'] += $row['stuck']['replied'];

            $rows[] = $row;
        }

        return [
            'generated_at' => $now->toIso8601String(),
            'window_days' => $days,
            'gates_days' => $gatesDays,
            'totals' => $totals,
            'artists' => $rows,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, AgentDraft>
     */
    private function drafts(Artist $artist, Carbon $since)
    {
        try {
            return AgentDraft::query()
                ->where('artist_id', $artist->id)
                ->where('source', '!=', AgentDraft::SOURCE_SIMULATED)
                ->where('created_at', '>=', $since)
                ->orderByDesc('created_at')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AgentDraft>  $drafts
     * @return array<int, array{rule: string, total: int}>
     */
    private function topRules($drafts, string $side): array
    {
        $counts = [];

        foreach ($drafts as $draft) {
            $source = $side === 'artist'
                ? ($draft->artist_validation['violations'] ?? [])
                : ($draft->validation['original_violations'] ?? $draft->validation['violations'] ?? []);

            if (! is_array($source)) {
                continue;
            }

            foreach ($source as $violation) {
                $rule = is_array($violation)
                    ? (string) ($violation['rule'] ?? '')
                    : (string) $violation;

                if ($rule !== '') {
                    $counts[$rule] = ($counts[$rule] ?? 0) + 1;
                }
            }
        }

        arsort($counts);

        return collect($counts)->take(8)->map(fn ($total, $rule) => ['rule' => $rule, 'total' => $total])->values()->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AgentDraft>  $compared
     * @return array<int, array{missing: string, total: int}>
     */
    private function topMissing($compared): array
    {
        $counts = [];

        foreach ($compared as $draft) {
            $missing = $draft->comparison['judge']['missing'] ?? [];

            if (! is_array($missing)) {
                continue;
            }

            foreach ($missing as $item) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $counts[$item] = ($counts[$item] ?? 0) + 1;
                }
            }
        }

        arsort($counts);

        return collect($counts)->take(8)->map(fn ($total, $missing) => ['missing' => $missing, 'total' => $total])->values()->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AgentDraft>  $drafts
     * @param  \Illuminate\Support\Collection<int, AgentDraft>  $compared
     * @return array<int, array{intent: string, total: int, unacceptable: int}>
     */
    private function intents($drafts, $compared): array
    {
        $rows = $drafts->groupBy(fn (AgentDraft $d) => $d->intent ?: 'unknown')
            ->map(fn ($group, $intent) => [
                'intent' => $intent,
                'total' => $group->count(),
                'unacceptable' => $compared->filter(
                    fn (AgentDraft $d) => ($d->intent ?: 'unknown') === $intent && $d->wasAccepted() === false
                )->count(),
            ])
            ->values()
            ->all();

        usort($rows, fn ($a, $b) => ($b['unacceptable'] <=> $a['unacceptable'])
            ?: ($b['total'] <=> $a['total']));

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function sample(AgentDraft $draft): array
    {
        $judge = $draft->comparison['judge'] ?? [];

        return [
            'id' => $draft->id,
            'intent' => $draft->intent,
            'acceptable' => $judge['acceptable'] ?? null,
            'verdict' => $judge['verdict'] ?? null,
            'rationale' => $judge['rationale'] ?? null,
            'missing' => $judge['missing'] ?? [],
            'missing_handoff' => $judge['missing_handoff'] ?? false,
            'draft' => mb_substr(trim((string) $draft->draft_reply), 0, 240),
            'artist' => mb_substr(trim((string) $draft->artist_reply), 0, 240),
            'draft_rules' => $this->rules($draft->validation['original_violations'] ?? $draft->validation['violations'] ?? []),
            'artist_rules' => $this->rules($draft->artist_validation['violations'] ?? []),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function rules(mixed $violations): array
    {
        if (! is_array($violations)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($violation) => is_array($violation)
                ? (string) ($violation['rule'] ?? '')
                : (string) $violation,
            $violations,
        )));
    }

    /**
     * Methodology audits of observe-mode conversations in the window.
     *
     * @return array<string, mixed>
     */
    private function audit(Artist $artist, Carbon $since): array
    {
        try {
            $audits = ConversationAudit::query()
                ->where('artist_id', $artist->id)
                ->where('status', ConversationAudit::STATUS_READY)
                ->where('generated_at', '>=', $since)
                ->orderByDesc('generated_at')
                ->get();
        } catch (\Throwable $e) {
            return [
                'count' => 0,
                'avg_score' => null,
                'top_missed' => [],
                'latest' => null,
            ];
        }

        $missed = [];

        foreach ($audits as $audit) {
            foreach ($audit->failedSteps() as $step) {
                $key = (string) ($step['key'] ?? '');
                if ($key !== '') {
                    $missed[$key] = ($missed[$key] ?? 0) + 1;
                }
            }
        }

        arsort($missed);

        $latest = $audits->first();

        return [
            'count' => $audits->count(),
            'avg_score' => $audits->count() > 0 ? round((float) $audits->avg('score'), 1) : null,
            'top_missed' => collect($missed)
                ->take(5)
                ->map(fn (int $total, string $key) => [
                    'step' => $key,
                    'label' => ConversationAudit::STEP_LABELS[$key] ?? $key,
                    'total' => $total,
                ])
                ->values()
                ->all(),
            'latest' => $latest !== null ? [
                'score' => $latest->score,
                'summary' => $latest->summary,
                'generated_at' => $latest->generated_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @return array{pending: int, replied: int}
     */
    private function stuck(Artist $artist, Carbon $now): array
    {
        $pendingHours = (int) config('copilot.thresholds.stuck_draft_hours', 24);
        $repliedHours = (int) config('copilot.thresholds.stuck_replied_hours', 2);

        try {
            return [
                'pending' => AgentDraft::query()
                    ->where('artist_id', $artist->id)
                    ->where('source', '!=', AgentDraft::SOURCE_SIMULATED)
                    ->where('status', AgentDraft::STATUS_PENDING)
                    ->where('created_at', '<', $now->copy()->subHours($pendingHours))
                    ->count(),
                'replied' => AgentDraft::query()
                    ->where('artist_id', $artist->id)
                    ->where('source', '!=', AgentDraft::SOURCE_SIMULATED)
                    ->where('status', AgentDraft::STATUS_REPLIED)
                    ->whereNull('comparison')
                    ->where('artist_replied_at', '<', $now->copy()->subHours($repliedHours))
                    ->count(),
            ];
        } catch (\Throwable $e) {
            return ['pending' => 0, 'replied' => 0];
        }
    }
}
