<?php

namespace App\Console\Commands;

use App\Models\CopilotReport;
use App\Services\Copilot\CopilotMailer;
use App\Services\Copilot\CopilotReporter;
use Illuminate\Console\Command;

class CopilotReportCommand extends Command
{
    protected $signature = 'copilot:report
        {kind : ops or calibration}
        {--no-mail : Generate without sending the staff digest}';

    protected $description = 'Generate the daily ops or weekly calibration Copilot report';

    public function handle(CopilotReporter $reporter, CopilotMailer $mailer): int
    {
        $kind = (string) $this->argument('kind');

        if (! in_array($kind, [CopilotReport::KIND_OPS, CopilotReport::KIND_CALIBRATION], true)) {
            $this->error('kind must be ops or calibration.');

            return self::FAILURE;
        }

        if (! (bool) config('copilot.enabled', true)) {
            $this->warn('Copilot is disabled (COPILOT_ENABLED).');

            return self::SUCCESS;
        }

        $report = $reporter->generate($kind);

        $this->info($report->label().' · '.$report->period_key.' · '.$report->status);

        if ($report->narrative) {
            $this->line($report->narrative);
        }

        if (! empty($report->findings)) {
            $this->table(
                ['severity', 'finding', 'suggested action'],
                array_map(fn ($finding) => [
                    $finding['severity'] ?? 'info',
                    $finding['title'] ?? '',
                    $finding['suggested_action'] ?? '',
                ], $report->findings),
            );
        }

        if ($report->status === CopilotReport::STATUS_FAILED) {
            $this->error('Report generation failed.');

            return self::FAILURE;
        }

        if (! $this->option('no-mail')) {
            $sent = $mailer->send($report);
            $this->line($sent > 0 ? "E-mail sent to {$sent} recipient(s)." : 'No staff e-mail sent.');
        }

        return self::SUCCESS;
    }
}
