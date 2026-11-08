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
