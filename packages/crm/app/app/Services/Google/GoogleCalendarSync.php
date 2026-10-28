<?php

namespace App\Services\Google;

use App\Models\Artist;
use App\Models\CalendarEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mirrors CRM bookings into the Artist's Google Calendar.
 *
 * Bookings are created by both the Laravel app and the WhatsApp agent (through
 * the `book_slot` RPC), so this is a reconcile loop rather than a controller
 * side effect: every pending calendar entry is pushed, and reschedules and
 * cancellations update/delete the same Google event.
 */
class GoogleCalendarSync
{
    public function __construct(private GoogleCalendarService $google)
    {
    }

    /**
     * @return array{created: int, updated: int, deleted: int, skipped: int, failed: int}
     */
    public function syncArtist(Artist $artist, int $limit = 200): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'failed' => 0];

        if (! $artist->hasGoogleCalendar()) {
            return $counts;
        }

        // During a rolling deploy two containers can run the scheduler at the
        // same time. A Postgres advisory lock makes one of them skip the Artist
        // instead of creating duplicate Google events.
        $lockKey = null;
        if (DB::connection()->getDriverName() === 'pgsql') {
            $lockKey = crc32($artist->id);
            $acquired = DB::selectOne('select pg_try_advisory_lock(?) as acquired', [$lockKey])->acquired;

            if (! $acquired) {
                return $counts;
            }
        }

        try {
            $from = now()->subDays((int) config('crm.google_calendar_past_days', 30));
            $to = now()->addDays((int) config('crm.google_calendar_future_days', 730));

            $entries = CalendarEntry::with(['lead', 'project'])
                ->where('artist_id', $artist->id)
                ->where('kind', 'booking')
                ->where('google_sync_pending', true)
                ->where(function ($query) use ($from, $to) {
                    $query->where(function ($window) use ($from, $to) {
                        $window->where('active', true)->whereBetween('start_at', [$from, $to]);
                    })->orWhere('active', false);
                })
                ->orderBy('start_at')
                ->limit($limit)
                ->get();

            foreach ($entries as $entry) {
                try {
                    $action = $this->syncEntry($artist, $entry);
                    $counts[$action]++;
                } catch (Throwable $e) {
                    $counts['failed']++;

                    $entry->forceFill([
                        'google_sync_error' => Str::limit($e->getMessage(), 500),
                    ])->save();

                    Log::warning('Google Calendar sync failed', [
                        'artist_id' => $artist->id,
                        'booking_id' => $entry->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            if ($lockKey !== null) {
                DB::select('select pg_advisory_unlock(?)', [$lockKey]);
            }
        }

        return $counts;
    }

    /**
     * @return 'created'|'updated'|'deleted'|'skipped'
     */
    private function syncEntry(Artist $artist, CalendarEntry $entry): string
    {
        $calendarId = (string) $artist->google_calendar_id;

        if (! $entry->active) {
            if (! $entry->google_event_id) {
                $this->mark($entry, null);

                return 'skipped';
            }

            $this->google->deleteEvent($calendarId, $entry->google_event_id);
            $this->mark($entry, null);

            return 'deleted';
        }

        $event = $this->eventPayload($artist, $entry);

        if ($entry->google_event_id) {
            try {
                $this->google->updateEvent($calendarId, $entry->google_event_id, $event);
                $this->mark($entry, $entry->google_event_id);

                return 'updated';
            } catch (GoogleCalendarException $e) {
                if (! $e->isNotFound()) {
                    throw $e;
                }

                // Somebody deleted the event in Google; recreate it below.
                $entry->forceFill(['google_event_id' => null])->save();
            }
        }

        $created = $this->google->createEvent($calendarId, $event);
        $eventId = $created['id'] ?? null;

        if (! is_string($eventId) || $eventId === '') {
            throw new GoogleCalendarException('O Google não devolveu o id do evento criado.');
        }

        $this->mark($entry, $eventId);

        return 'created';
    }

    private function mark(CalendarEntry $entry, ?string $eventId): void
    {
        $entry->forceFill([
            'google_event_id' => $eventId,
            'google_sync_pending' => false,
            'google_synced_at' => now(),
            'google_sync_error' => null,
        ])->save();
    }

    /**
     * The Google Calendar event body. Kept public so the shape is easy to test.
     *
     * @return array<string, mixed>
     */
    public function eventPayload(Artist $artist, CalendarEntry $entry): array
    {
        $lead = $entry->lead;
        $project = $entry->project;
        $timezone = $artist->calendarTimezone();

        $start = $entry->start_at->copy()->setTimezone($timezone);
        $end = $entry->end_at->copy()->setTimezone($timezone);

        $name = $lead?->displayName() ?? 'Cliente';
        $lines = [];

        if ($lead?->phone_e164) {
            $lines[] = 'Telefone: '.$lead->phone_e164;
        }

        if ($project) {
            $placement = collect([$project->placement_label, $project->body_zone_label])
                ->filter()
                ->implode(' · ');

            if ($placement !== '') {
                $lines[] = 'Local: '.$placement;
            }

            if ($project->style) {
                $lines[] = 'Estilo: '.$project->style;
            }
        }

        if ($lead) {
            $lines[] = 'Agendamento no CRM: '.route('leads.show', $lead);
        }

        return [
            'summary' => 'Tatuagem — '.$name,
            'description' => implode("\n", $lines),
            'start' => [
                'dateTime' => $start->format('Y-m-d\TH:i:sP'),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $end->format('Y-m-d\TH:i:sP'),
                'timeZone' => $timezone,
            ],
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'popup', 'minutes' => 1440],
                    ['method' => 'popup', 'minutes' => 120],
                ],
            ],
            'extendedProperties' => [
                'private' => [
                    'vaif_booking_id' => $entry->id,
                    'vaif_artist_id' => $artist->id,
                ],
            ],
        ];
    }
}
