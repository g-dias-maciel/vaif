<?php

namespace App\Console\Commands;

use App\Models\AgentDraft;
use App\Models\Artist;
use App\Services\Agent\CalibrationReport;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Calibration scoreboard and graduation gates for copilot mode.
 */
class AgentCalibrationReport extends Command
{
    protected $signature = 'agent:calibration-report
        {artist? : Artist UUID or WAHA session slug}
        {--days=30 : Window in days}
        {--json : Output the raw report as JSON}
        {--strict : Exit non-zero when the hard gates do not pass}';

    protected $description = 'Show draft-vs-artist calibration metrics and graduation gates';

    public function handle(CalibrationReport $report): int
    {
        $days = max(1, (int) $this->option('days'));
        $artists = $this->artists();

        if ($artists === null) {
            return self::FAILURE;
        }

        if ($artists->isEmpty()) {
            $this->warn('No artist with agent drafts found.');

            return self::SUCCESS;
        }

        $reports = $artists->map(fn (Artist $artist) => $report->forArtist($artist, $days))->all();

        if ($this->option('json')) {
            $this->line(json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $this->strictExit($reports);
        }

        foreach ($reports as $data) {
            $this->render($data);
        }

        return $this->strictExit($reports);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Artist>|null
     */
    private function artists()
    {
        $key = (string) $this->argument('artist');

        if ($key !== '') {
            $query = Artist::with('settings')->where('wa_session_slug', $key);

            if (Str::isUuid($key)) {
                $query->orWhere('id', $key);
            }

            $artists = $query->get();

            if ($artists->isEmpty()) {
                $this->error('Artist not found.');

                return null;
            }

            return $artists;
        }

        return Artist::with('settings')
            ->whereIn('id', AgentDraft::query()->select('artist_id')->distinct())
            ->orderBy('name')
            ->get();
    }

    private function render(array $data): void
    {
        $this->newLine();
        $this->info(sprintf(
            '%s · mode=%s · last %d day(s) · %s',
            $data['artist'],
            $data['reply_mode'],
            $data['days'],
            $data['passed'] ? 'CALIBRATED' : 'NOT CALIBRATED',
        ));

        $totals = $data['totals'];
        $this->table(['drafts', 'answered', 'compared', 'pending', 'expired', 'superseded', 'median reply'], [[
            $totals['drafts'],
            $totals['answered'],
            $totals['compared'],
            $totals['pending'],
            $totals['expired'],
            $totals['superseded'],
            $totals['median_response_seconds'] !== null ? $totals['median_response_seconds'].'s' : '—',
        ]]);

        $this->table(['gate', 'actual', 'target', 'result', 'type'], array_map(fn ($gate) => [
            $gate['label'],
            (string) $gate['actual'],
            $gate['target'],
            $gate['pass'] ? 'PASS' : 'FAIL',
            $gate['hard'] ? 'hard' : 'soft',
        ], $data['gates']));

        $this->line(sprintf(
            'Aceitáveis: %d%% · pergunta coberta: %d%% · tom: %s',
            (int) round($data['rates']['acceptable'] * 100),
            (int) round($data['rates']['covered_question'] * 100),
            $data['rates']['tone_avg'] !== null ? $data['rates']['tone_avg'].'/5' : '—',
        ));

        if ($data['intents'] !== []) {
            $this->line('Intents: '.collect($data['intents'])->map(fn ($n, $i) => "{$i}={$n}")->implode('  '));
        }

        if ($data['verdicts'] !== []) {
            $this->line('Verdicts: '.collect($data['verdicts'])->map(fn ($n, $v) => "{$v}={$n}")->implode('  '));
        }

        foreach ($data['warnings'] as $warning) {
            $this->warn('Coverage warning: '.$warning);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $reports
     */
    private function strictExit(array $reports): int
    {
        if (! $this->option('strict')) {
            return self::SUCCESS;
        }

        $failed = collect($reports)->contains(fn (array $data) => ! $data['passed']);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
