<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Print the grounded-turn scoreboard for the last N days.
 *
 * This is the rollout gate: enable `shadow` for one artist, run this after a
 * day of traffic, and only switch to `enforce` when the violation and
 * no-claims counts are near zero.
 */
class GroundingReport extends Command
{
    protected $signature = 'crm:grounding-report {--days=7 : How many days to include}';

    protected $description = 'Show grounded-turn quality, repairs and violation rules';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days)->toDateString();

        $quality = DB::table('v_agent_grounding_quality')
            ->where('day', '>=', $since)
            ->orderBy('day')
            ->orderBy('artist_id')
            ->get();

        if ($quality->isEmpty()) {
            $this->warn("No grounded turns since {$since}.");

            return self::SUCCESS;
        }

        $this->table(
            ['day', 'artist', 'mode', 'turns', 'violations', 'repaired', 'canonical', 'kept legacy', 'no claims', 'confidence'],
            $quality->map(fn ($row) => [
                $row->day,
                $row->artist_id,
                $row->mode,
                $row->turns,
                $row->turns_with_violations,
                $row->turns_repaired,
                $row->turns_canonical,
                $row->turns_kept_legacy,
                $row->turns_without_claims,
                $row->avg_confidence,
            ])->all()
        );

        $rules = DB::table('v_agent_violation_rules')
            ->where('day', '>=', $since)
            ->orderByDesc('occurrences')
            ->limit(20)
            ->get();

        if ($rules->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['rule', 'mode', 'occurrences'],
                $rules->map(fn ($row) => [$row->rule, $row->mode, $row->occurrences])->all()
            );
        }

        return self::SUCCESS;
    }
}
