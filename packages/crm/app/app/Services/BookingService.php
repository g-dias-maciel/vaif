<?php

namespace App\Services;

use App\Models\CalendarEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BookingService
{
    /**
     * Free slots derived from the Artist's working hours and current calendar.
     * Availability is computed by the database (`check_availability`), the same
     * function the agent calls, so the app and the agent can never disagree.
     *
     * @return array<int, array{start_at: CarbonImmutable, end_at: CarbonImmutable}>
     */
    public function availableSlots(string $artistId, CarbonImmutable $from, CarbonImmutable $to, int $durationMin): array
    {
        $duration = max(30, $durationMin);

        $rows = DB::select(
            'select start_at, end_at from check_availability(?, ?, ?, ?)',
            [$artistId, $from, $to, $duration],
        );

        $tz = config('app.timezone');

        return array_map(fn (object $row) => [
            'start_at' => CarbonImmutable::parse($row->start_at)->setTimezone($tz),
            'end_at' => CarbonImmutable::parse($row->end_at)->setTimezone($tz),
        ], $rows);
    }

    /**
     * Move an existing booking to a new slot through `reschedule_booking`, so
     * the calendar exclusion constraint decides whether the target is free.
     *
     * @throws RuntimeException when the target slot is taken.
     */
    public function reschedule(CalendarEntry $booking, CarbonImmutable $newStart): void
    {
        if ($newStart->lessThan(now())) {
            throw new RuntimeException('O novo horário deve ser no futuro.');
        }

        $rows = DB::select(
            'select id from reschedule_booking(?, ?, ?, ?)',
            [$booking->artist_id, $booking->id, $newStart, 'artist'],
        );

        if ($rows === []) {
            throw new RuntimeException('Este horário não está disponível.');
        }
    }
}
