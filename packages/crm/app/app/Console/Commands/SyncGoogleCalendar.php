<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Services\Google\GoogleCalendarSync;
use Illuminate\Console\Command;
use Throwable;

class SyncGoogleCalendar extends Command
{
    protected $signature = 'calendar:sync-google
        {--artist= : Sync only one Artist UUID}
        {--limit=200 : Maximum pending bookings per Artist}';

    protected $description = 'Push pending CRM bookings to each Artist\'s shared Google Calendar';

    public function handle(GoogleCalendarSync $sync): int
    {
        $query = Artist::query()->whereNotNull('google_calendar_id');

        if ($artistId = $this->option('artist')) {
            $query->whereKey($artistId);
        }

        $artists = $query->get();

        if ($artists->isEmpty()) {
            $this->info('No Artist has a Google Calendar connected.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $totals = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($artists as $artist) {
            try {
                $counts = $sync->syncArtist($artist, $limit);
            } catch (Throwable $e) {
                $this->error($artist->displayName().': '.$e->getMessage());

                continue;
            }

            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }

            if (array_sum($counts) > 0) {
                $this->line(sprintf(
                    '%s: %d created, %d updated, %d deleted, %d skipped, %d failed',
                    $artist->displayName(),
                    $counts['created'],
                    $counts['updated'],
                    $counts['deleted'],
                    $counts['skipped'],
                    $counts['failed'],
                ));
            }
        }

        $this->info(sprintf(
            'Google Calendar sync: %d created, %d updated, %d deleted, %d skipped, %d failed.',
            $totals['created'],
            $totals['updated'],
            $totals['deleted'],
            $totals['skipped'],
            $totals['failed'],
        ));

        return self::SUCCESS;
    }
}
