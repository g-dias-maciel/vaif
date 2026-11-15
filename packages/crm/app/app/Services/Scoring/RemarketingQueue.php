<?php

namespace App\Services\Scoring;

use App\Models\Artist;
use App\Models\Lead;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Builds the Remarketing work queue.
 *
 * `retomar`  open Projects that have been silent for N days.
 * `abertos`  every open Project, whatever the silence.
 * `reviver`  lost Projects that got far enough to be worth another attempt.
 */
class RemarketingQueue
{
    public function __construct(
        private readonly LeadScoreService $scores,
        private readonly RemarketingTouchLogger $touches,
    ) {
    }

    /**
     * @param  array{segment: string, tier: ?string, days: int, q: string, page: int}  $filters
     * @return array<string, mixed>
     */
    public function forArtist(Artist $artist, array $filters): array
    {
        $profiles = $this->scores->profiles($artist);
        $leadIds = $profiles->keys()->all();

        $config = (array) config('crm.lead_score.remarketing', []);
        $attemptWindow = (int) ($config['attempt_window_days'] ?? 30);

        $touches = $this->touches->latestForLeads($leadIds);
        $attempts = $this->touches->recentAttempts($leadIds, $attemptWindow);

        $now = Carbon::now();
        $segment = $filters['segment'];
        $q = $this->normalize($filters['q']);

        $allRows = [];

        foreach (['retomar', 'abertos', 'reviver'] as $candidateSegment) {
            $allRows[$candidateSegment] = $this->candidates(
                $profiles,
                $candidateSegment,
                $filters,
                $touches,
                $attempts,
                $now,
                $q,
                $config,
            );
        }

        $rows = $allRows[$segment] ?? [];

        if ($filters['tier'] !== null) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => $row['score']->tier === $filters['tier'],
            ));
        }

        usort($rows, function (array $a, array $b): int {
            $byScore = $b['score']->score <=> $a['score']->score;

            if ($byScore !== 0) {
                return $byScore;
            }

            return ($b['last_activity']?->getTimestamp() ?? 0) <=> ($a['last_activity']?->getTimestamp() ?? 0);
        });

        $pageSize = max(10, (int) ($config['page_size'] ?? 50));
        $page = max(1, (int) $filters['page']);
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $pageSize));
        $page = min($page, $lastPage);
        $items = array_slice($rows, ($page - 1) * $pageSize, $pageSize);

        $tiers = ['hot' => 0, 'warm' => 0, 'cold' => 0];

        foreach ($allRows[$segment] ?? [] as $row) {
            $tier = $row['score']->tier;

            if (isset($tiers[$tier])) {
                $tiers[$tier]++;
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'last_page' => $lastPage,
            'counts' => [
                'segments' => [
                    'retomar' => count($allRows['retomar'] ?? []),
                    'abertos' => count($allRows['abertos'] ?? []),
                    'reviver' => count($allRows['reviver'] ?? []),
                ],
                'tiers' => $tiers,
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, LeadProfile>  $profiles
     * @param  array<string, mixed>  $filters
     * @param  array<string, array{action: string, at: Carbon, payload: array<string, mixed>}>  $touches
     * @param  array<string, int>  $attempts
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    private function candidates(
        $profiles,
        string $segment,
        array $filters,
        array $touches,
        array $attempts,
        Carbon $now,
        string $query,
        array $config,
    ): array {
        $rows = [];

        foreach ($profiles as $profile) {
            $lead = $profile->lead;

            if ($profile->isArchived()) {
                continue;
            }

            if ($query !== '' && ! $this->matchesSearch($lead, $query)) {
                continue;
            }

            $score = null;
            $project = null;
            $lastActivity = $profile->lastActivity;
            $touch = $touches[(string) $lead->id] ?? null;
            $leadAttempts = (int) ($attempts[(string) $lead->id] ?? 0);

            if ($segment === 'reviver') {
                if ($profile->hasOpenProject() || $profile->lastProject?->stage_key !== 'perdido') {
                    continue;
                }

                $score = $profile->revivalScore;
                $project = $profile->lastProject;

                if ($score === null || $project === null || $score->score <= 0) {
                    continue;
                }

                $lostAt = $project->closed_at ?? $project->updated_at;
                $window = (int) ($config['revival']['window_days'] ?? 365);

                if ($lostAt === null || (int) $lostAt->diffInDays($now) > $window) {
                    continue;
                }
            } else {
                if (! $profile->hasOpenProject()) {
                    continue;
                }

                $score = $profile->openScore;
                $project = $profile->openProject;

                if ($score === null || $project === null) {
                    continue;
                }

                if ($segment === 'retomar') {
                    $days = (int) ($filters['days'] ?? $config['default_days'] ?? 7);

                    if ($lastActivity === null || (int) $lastActivity->diffInDays($now) < $days) {
                        continue;
                    }
                }
            }

            // Two unanswered attempts are enough. If the Lead wrote after the
            // last attempt, the counter no longer applies: it is a live
            // conversation again.
            if ($segment !== 'abertos'
                && $leadAttempts >= (int) ($config['max_attempts'] ?? 2)
                && ($touch === null || $lastActivity === null || $touch['at']->greaterThanOrEqualTo($lastActivity))) {
                continue;
            }

            if ($this->isHidden($segment, $touch, $config, $now, $lastActivity)) {
                continue;
            }

            $rows[] = [
                'profile' => $profile,
                'lead' => $lead,
                'project' => $project,
                'score' => $score,
                'last_activity' => $lastActivity,
                'touch' => $touch,
                'attempts' => $leadAttempts,
            ];
        }

        return $rows;
    }

    /**
     * @param  array{action: string, at: Carbon, payload: array<string, mixed>}|null  $touch
     * @param  array<string, mixed>  $config
     */
    private function isHidden(
        string $segment,
        ?array $touch,
        array $config,
        Carbon $now,
        ?Carbon $lastActivity,
    ): bool {
        if ($touch === null) {
            return false;
        }

        // A newer message (usually the Lead replying) clears the cooling-off
        // window: the conversation is live again.
        if ($lastActivity !== null && $lastActivity->greaterThan($touch['at'])) {
            return false;
        }

        $days = max(0, (int) $touch['at']->diffInDays($now));

        return match ($touch['action']) {
            RemarketingTouchLogger::ACTION_DISMISSED => $days < (int) ($config['hide_after_dismissed_days'] ?? 90),
            RemarketingTouchLogger::ACTION_CONTACTED => $segment !== 'abertos'
                && $days < (int) ($config['hide_after_contacted_days'] ?? 14),
            RemarketingTouchLogger::ACTION_WHATSAPP => $segment !== 'abertos'
                && $days < (int) ($config['hide_after_opened_days'] ?? 3),
            default => false,
        };
    }

    private function matchesSearch(Lead $lead, string $query): bool
    {
        $haystack = $this->normalize($lead->displayName().' '.$lead->phone_e164);

        return str_contains($haystack, $query);
    }

    private function normalize(?string $value): string
    {
        return trim(Str::ascii(mb_strtolower((string) $value)));
    }
}
