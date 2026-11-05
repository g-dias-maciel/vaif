<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Services\Google\GoogleCalendarSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import external Google Calendar events as blocks so the agent does not offer
 * a slot the artist already filled directly in Google.
 *
 * Runs on a slower cadence than the outbound sync: it is a read of the whole
 * upcoming window, not a per-row retry.
 */
class ImportGoogleBlocks extends Command
{
    protected $signature = 'calendar:import-google-blocks
        {--artist= : Import only one Artist UUID}
        {--days=60 : Days ahead to import}';

    protected $description = 'Mirror external Google Calendar events as blocks (prevents double booking)';

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

        $days = max(1, (int) $this->option('days'));

        foreach ($artists as $artist) {
            try {
                $counts = $sync->syncBlocks($artist, $days);
            } catch (Throwable $e) {
                $this->error($artist->displayName().': '.$e->getMessage());

                continue;
            }

            if (array_sum($counts) > 0) {
                $this->line(sprintf(
                    '%s: %d importado(s), %d atualizado(s), %d removido(s)',
                    $artist->displayName(),
                    $counts['inserted'],
                    $counts['updated'],
                    $counts['deleted'],
                ));
            }
        }

        return self::SUCCESS;
    }
}
