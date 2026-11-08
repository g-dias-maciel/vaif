<?php

namespace App\Console\Commands;

use App\Models\Artist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turn past bookings into `returning_customers` rows so the grounded agent can
 * recognise an existing client even when the appointment was made outside the
 * CRM.
 */
class BackfillReturningCustomers extends Command
{
    protected $signature = 'crm:backfill-returning-customers {--artist= : Artist UUID}';

    protected $description = 'Backfill returning customers from past bookings';

    public function handle(): int
    {
        $artistId = $this->option('artist');

        if ($artistId !== null && ! Artist::whereKey($artistId)->exists()) {
            $this->error("Unknown artist: {$artistId}");

            return self::FAILURE;
        }

        $count = DB::selectOne(
            'select backfill_returning_customers($1::uuid) as count',
            [$artistId]
        )->count;

        $this->info("Backfilled {$count} returning customer(s).");

        return self::SUCCESS;
    }
}
