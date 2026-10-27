<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\CalendarSlot;
use App\Models\Event;
use App\Models\Lead;
use Carbon\CarbonImmutable;
use RuntimeException;

class BookingService
{
    // ISO-8601 weekday (1=Mon..7=Sun) → the VAIF `working_hours` key.
    private const DOW_KEYS = [1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sab', 7 => 'dom'];

    /**
     * Free slots derived from the Artist's weekly working hours, excluding
     * periods already booked or blocked. Mirrors the agent's availability
     * semantics but lives in the app layer (see the auth ADR: the app does not
     * depend on the agent's Postgres functions).
     *
     * @return array<int, array{start_at: CarbonImmutable, end_at: CarbonImmutable}>
     */
    public function availableSlots(string $artistId, CarbonImmutable $from, CarbonImmutable $to, int $durationMin): array
    {
        $artist = Artist::find($artistId);

        if (! $artist || empty($artist->working_hours)) {
            return [];
        }

        $duration = max(30, $durationMin);
        $slots = [];
        $day = $from->startOfDay();

        while ($day->lte($to->endOfDay())) {
            $blocks = $artist->working_hours[self::DOW_KEYS[$day->isoWeekday()]] ?? [];

            foreach ($blocks as $block) {
                [$startHm, $endHm] = array_pad(explode('-', (string) $block), 2, '');

                if ($startHm === '' || $endHm === '') {
                    continue;
                }

                $blockStart = CarbonImmutable::parse($day->format('Y-m-d').' '.$startHm);
                $blockEnd = CarbonImmutable::parse($day->format('Y-m-d').' '.$endHm);

                $slotStart = $blockStart;
                while ($slotStart->addMinutes($duration)->lte($blockEnd)) {
                    $slotEnd = $slotStart->addMinutes($duration);

                    if ($slotStart->gte($from) && $slotEnd->lte($to)) {
                        $busy = CalendarSlot::query()
                            ->where('artist_id', $artistId)
                            ->whereIn('type', ['booked', 'blocked'])
                            ->where('start_at', '<', $slotEnd)
                            ->where('end_at', '>', $slotStart)
                            ->exists();

                        if (! $busy) {
                            $slots[] = [
                                'start_at' => $slotStart->copy(),
                                'end_at' => $slotEnd->copy(),
                            ];
                        }
                    }

                    $slotStart = $slotStart->addHour();
                }
            }

            $day = $day->addDay();
        }

        return $slots;
    }

    /**
     * Move an existing booking to a new free slot.
     *
     * @throws RuntimeException when the target slot is not a real, free slot.
     */
    public function reschedule(CalendarSlot $booking, CarbonImmutable $newStart): void
    {
        $artistId = $booking->artist_id;
        $durationMin = max(30, (int) $booking->start_at->diffInMinutes($booking->end_at));
        $newEnd = $newStart->addMinutes($durationMin);

        if ($newStart->lessThan(now())) {
            throw new RuntimeException('O novo horário deve ser no futuro.');
        }

        $available = $this->availableSlots(
            $artistId,
            $newStart->subHour(),
            $newEnd->addHour(),
            $durationMin,
        );

        $free = collect($available)->contains(
            fn (array $slot) => $slot['start_at']->equalTo($newStart)
        );

        if (! $free) {
            throw new RuntimeException('Este horário não está disponível.');
        }

        $previousStart = $booking->start_at;

        $booking->update([
            'start_at' => $newStart,
            'end_at' => $newEnd,
        ]);

        if ($booking->lead_id) {
            Lead::whereKey($booking->lead_id)->update([
                'booked_date' => $newStart,
                'session_duration_min' => $durationMin,
                'updated_at' => now(),
            ]);
        }

        Event::create([
            'lead_id' => $booking->lead_id,
            'artist_id' => $artistId,
            'event_type' => 'booking_rescheduled',
            'payload' => [
                'start_at' => $newStart->toIso8601String(),
                'end_at' => $newEnd->toIso8601String(),
                'duration_min' => $durationMin,
                'previous_start_at' => $previousStart?->toIso8601String(),
            ],
        ]);
    }
}
