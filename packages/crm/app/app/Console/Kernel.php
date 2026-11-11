<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // The WhatsApp agent writes bookings straight through `book_slot`, so
        // polling for pending rows is the only source-independent sync.
        $schedule->command('calendar:sync-google')
            ->everyMinute()
            ->withoutOverlapping(10);

        // Mirror internal team meetings into the responsible user's Google
        // Calendar. Same reconcile-loop idea as the Artist bookings above.
        $schedule->command('calendar:sync-team-events')
            ->everyMinute()
            ->withoutOverlapping(10);

        // Read the Artist's own Google events as blocks so the agent never
        // offers a slot already filled outside the CRM.
        $schedule->command('calendar:import-google-blocks')
            ->everyFiveMinutes()
            ->withoutOverlapping(10);

        // E-mail the artist when a lead is waiting for a handoff or a PIX
        // confirmation. The notified_at columns dedupe each item.
        $schedule->command('crm:notify-artist-attention')
            ->everyMinute()
            ->withoutOverlapping(10);

        // A message the agent deliberately did not answer must stay visible.
        $schedule->command('crm:notify-ingest-drops')
            ->everyFiveMinutes()
            ->withoutOverlapping(10);

        // Retrospective media descriptions for calibration. Runs on the mock
        // queue and never touches a live turn.
        $schedule->command('agent:describe-media --all --days=2 --limit=100')
            ->hourly()
            ->withoutOverlapping(30);

        // Staff Copilot: deterministic ops facts + narrative.
        if (config('copilot.enabled', true)) {
            $copilotTz = (string) config('copilot.timezone', 'America/Sao_Paulo');

            $schedule->command('copilot:report ops')
                ->dailyAt((string) config('copilot.daily_time', '08:00'))
                ->timezone($copilotTz)
                ->withoutOverlapping(20);

            $schedule->command('copilot:report calibration')
                ->weeklyOn((int) config('copilot.weekly_day', 1), (string) config('copilot.weekly_time', '08:10'))
                ->timezone($copilotTz)
                ->withoutOverlapping(30);
        }

        // Finance: generate the PIX invoices that are due and flag overdue
        // balances. Card charges are recorded manually or by the MP webhook.
        $schedule->command('finance:generate-invoices')
            ->dailyAt('06:00')
            ->timezone('America/Sao_Paulo')
            ->withoutOverlapping(30);

        $schedule->command('finance:mark-overdue')
            ->dailyAt('06:10')
            ->timezone('America/Sao_Paulo')
            ->withoutOverlapping(30);

        $schedule->command('finance:generate-recurring-expenses')
            ->dailyAt('06:20')
            ->timezone('America/Sao_Paulo')
            ->withoutOverlapping(30);
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
