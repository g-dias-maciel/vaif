<?php

namespace App\Services\Copilot;

use App\Models\CopilotReport;
use Illuminate\Support\Carbon;

/**
 * Builds a Copilot report from deterministic facts + the analyst narrative.
 * It never sends e-mail and never executes an action.
 */
class CopilotReporter
{
    public function __construct(
        private readonly OpsFacts $ops,
        private readonly CalibrationFacts $calibration,
        private readonly CopilotAnalyst $analyst,
    ) {
    }

    /**
     * @return array{key: string, start: Carbon, end: Carbon}
     */
    public function period(string $kind, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy();

        if ($kind === CopilotReport::KIND_OPS) {
            return [
                'key' => 'ops:'.$now->toDateString(),
                'start' => $now->copy()->subHours((int) config('copilot.windows.ops_hours', 24)),
                'end' => $now,
            ];
        }

        return [
            'key' => 'calibration:'.$now->format('o-\WW'),
            'start' => $now->copy()->subDays((int) config('copilot.windows.calibration_days', 7)),
            'end' => $now,
        ];
    }

    public function generate(string $kind, ?string $periodKey = null, ?Carbon $now = null): CopilotReport
    {
        $period = $this->period($kind, $now);
        $key = $periodKey ?: $period['key'];

        $report = CopilotReport::firstOrNew([
            'kind' => $kind,
            'period_key' => $key,
        ]);

        $report->forceFill([
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'status' => CopilotReport::STATUS_READY,
        ]);

        try {
            $facts = $kind === CopilotReport::KIND_OPS
                ? $this->ops->collect()
                : $this->calibration->collect();

            $analysis = $this->analyst->analyze($kind, $facts);

            $report->forceFill([
                'facts' => $facts,
                'narrative' => $analysis['narrative'],
                'findings' => $analysis['findings'],
                'model' => $analysis['model'],
                'generated_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            report($e);

            $report->forceFill([
                'status' => CopilotReport::STATUS_FAILED,
                'narrative' => 'Não foi possível gerar este relatório: '.$e->getMessage(),
                'facts' => [],
                'findings' => [],
                'generated_at' => now(),
            ])->save();
        }

        return $report;
    }
}
