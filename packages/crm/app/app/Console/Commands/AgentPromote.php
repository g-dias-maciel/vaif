<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Services\Agent\CalibrationReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Move an artist between off → draft → auto.
 *
 * Promotion to auto is gated by the calibration report unless --force is used.
 */
class AgentPromote extends Command
{
    protected $signature = 'agent:promote
        {artist : Artist UUID or WAHA session slug}
        {--to=auto : off, observe, draft or auto}
        {--days=30 : Calibration window used by the gate}
        {--force : Promote to auto even when the hard gates fail}';

    protected $description = 'Promote or demote an artist reply mode using the calibration gates';

    public function handle(CalibrationReport $report): int
    {
        if (! Schema::hasColumn('artist_settings', 'reply_mode')) {
            $this->error('Calibration schema is missing. Apply packages/crm/db/migrations/0033_agent_calibration.sql.');

            return self::FAILURE;
        }

        $target = (string) $this->option('to');

        if (! in_array($target, ['off', 'observe', 'draft', 'auto'], true)) {
            $this->error('--to must be off, observe, draft or auto.');

            return self::FAILURE;
        }

        if ($target === 'observe'
            && (! Schema::hasTable('observed_turns') || ! Schema::hasTable('lead_summaries'))) {
            $this->error('Observe schema is missing. Apply packages/crm/db/migrations/0042_observe_mode.sql.');

            return self::FAILURE;
        }

        $artist = $this->artist();

        if ($artist === null) {
            return self::FAILURE;
        }

        if ($target === 'auto' && ! $this->option('force')) {
            $data = $report->forArtist($artist, max(1, (int) $this->option('days')));

            $this->table(['gate', 'actual', 'target', 'result'], array_map(fn ($gate) => [
                $gate['label'],
                (string) $gate['actual'],
                $gate['target'],
                $gate['pass'] ? 'PASS' : 'FAIL',
            ], $data['gates']));

            if (! $data['passed']) {
                $this->error('Calibration gates did not pass. Use --force only with explicit approval.');

                return self::FAILURE;
            }
        }

        $previous = $artist->settings?->reply_mode ?? 'draft';

        ArtistSetting::updateOrCreate(
            ['artist_id' => $artist->id],
            ['reply_mode' => $target],
        );

        $this->info("{$artist->name}: reply_mode {$previous} → {$target}");

        if ($target === 'auto') {
            $this->warn('Auto mode sends replies. Confirm the WAHA webhook points at the CRM and the agent env keys are set.');
        }

        return self::SUCCESS;
    }

    private function artist(): ?Artist
    {
        $key = (string) $this->argument('artist');
        $query = Artist::with('settings')->where('wa_session_slug', $key);

        if (Str::isUuid($key)) {
            $query->orWhere('id', $key);
        }

        $artist = $query->first();

        if ($artist === null) {
            $this->error('Artist not found.');
        }

        return $artist;
    }
}
