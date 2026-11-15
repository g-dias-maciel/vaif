<?php

namespace App\Services\Scoring;

/**
 * The chance-to-close score for one Project (and the Lead that owns it).
 *
 * The score is a queue order, not a gate: it tells the Artist which stalled
 * conversation is most likely to turn into a booking, and the reasons explain
 * exactly why. A low score never blocks a human from working the Lead.
 */
final class LeadScore
{
    public const TIER_HOT = 'hot';

    public const TIER_WARM = 'warm';

    public const TIER_COLD = 'cold';

    public const TIER_WON = 'won';

    public const TIER_CLOSED = 'closed';

    /**
     * @param  array<int, array{label: string, points: int}>  $reasons
     * @param  array<string, mixed>  $signals
     */
    public function __construct(
        public readonly string $leadId,
        public readonly ?string $projectId,
        public readonly int $score,
        public readonly string $tier,
        public readonly array $reasons = [],
        public readonly array $signals = [],
        public readonly ?string $nextAction = null,
    ) {
    }

    public static function tierForScore(int $score): string
    {
        $thresholds = (array) config('crm.lead_score.tier_thresholds', []);

        if ($score >= (int) ($thresholds['hot'] ?? 70)) {
            return self::TIER_HOT;
        }

        if ($score >= (int) ($thresholds['warm'] ?? 40)) {
            return self::TIER_WARM;
        }

        return self::TIER_COLD;
    }

    public function tierLabel(): string
    {
        return (string) (config('crm.lead_score.tier_labels')[$this->tier] ?? ucfirst($this->tier));
    }

    /**
     * Whether this is a live Project that can still be worked.
     */
    public function isOpen(): bool
    {
        return in_array($this->tier, [self::TIER_HOT, self::TIER_WARM, self::TIER_COLD], true);
    }

    public function isHot(): bool
    {
        return $this->tier === self::TIER_HOT;
    }

    /**
     * The most relevant reasons, biggest absolute contribution first.
     *
     * @return array<int, array{label: string, points: int}>
     */
    public function topReasons(int $limit = 5): array
    {
        $reasons = array_values(array_filter(
            $this->reasons,
            fn (array $reason): bool => (int) ($reason['points'] ?? 0) !== 0,
        ));

        usort($reasons, fn (array $a, array $b): int => abs((int) $b['points']) <=> abs((int) $a['points']));

        return array_slice($reasons, 0, max(0, $limit));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lead_id' => $this->leadId,
            'project_id' => $this->projectId,
            'score' => $this->score,
            'tier' => $this->tier,
            'tier_label' => $this->tierLabel(),
            'reasons' => $this->reasons,
            'signals' => $this->signals,
            'next_action' => $this->nextAction,
        ];
    }
}
