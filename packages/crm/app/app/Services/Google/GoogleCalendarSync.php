<?php

namespace App\Services\Google;

use App\Models\Artist;
use App\Models\CalendarEntry;
use Carbon\CarbonImmutable;
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
     * Mirror the Artist's external Google events as blocks so the agent will
     * not offer a slot the artist already filled in Google.
     *
     * CRM-created bookings are skipped (they already have a row), and only
     * events that would actually block the Artist are imported: cancelled and
     * "free" (transparency=transparent) events are ignored.
     *
     * @return array{deleted: int, inserted: int, updated: int}
     */
    public function syncBlocks(Artist $artist, int $days = 60): array
    {
        $counts = ['deleted' => 0, 'inserted' => 0, 'updated' => 0];

        if (! $artist->hasGoogleCalendar()) {
            return $counts;
        }

        $from = now()->startOfDay();
        $to = now()->addDays(max(1, $days))->endOfDay();

        $events = $this->google->listEvents((string) $artist->google_calendar_id, $from, $to);

        /** @var array<string, array{0: CarbonImmutable, 1: CarbonImmutable}> $wanted */
        $wanted = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            if (($event['status'] ?? '') === 'cancelled') {
                continue;
            }

            if (($event['transparency'] ?? '') === 'transparent') {
                continue;
            }

            // A CRM booking: it already lives in `calendar_entries`.
            if (! empty($event['extendedProperties']['private']['vaif_booking_id'])) {
                continue;
            }

            $id = (string) ($event['id'] ?? '');

            if ($id === '') {
                continue;
            }

            [$start, $end] = $this->eventRange($event, (string) $artist->timezone);

            if ($start === null || $end === null) {
                continue;
            }

            $wanted[$id] = [$start, $end];
        }

        $existing = CalendarEntry::where('artist_id', $artist->id)
            ->where('kind', 'block')
            ->whereNotNull('google_event_id')
            ->get()
            ->keyBy('google_event_id');

        // Imported blocks whose Google event disappeared.
        foreach ($existing as $eventId => $entry) {
            if (! array_key_exists((string) $eventId, $wanted)) {
                $entry->delete();
                $counts['deleted']++;
            }
        }

        foreach ($wanted as $eventId => [$start, $end]) {
            $entry = $existing->get($eventId);

            if ($entry) {
                if ($entry->start_at->equalTo($start) && $entry->end_at->equalTo($end)) {
                    continue;
                }

                // A moved event that now collides with a booking replaces the
                // mirror with nothing; the CRM entry wins.
                if ($this->collides($artist, $start, $end, (string) $entry->id)) {
                    $entry->delete();
                    $counts['deleted']++;

                    continue;
                }

                try {
                    $entry->forceFill(['start_at' => $start, 'end_at' => $end])->save();
                    $counts['updated']++;
                } catch (Throwable $e) {
                    report($e);
                }

                continue;
            }

            if ($this->collides($artist, $start, $end)) {
                continue;
            }

            try {
                CalendarEntry::create([
                    'artist_id' => $artist->id,
                    'kind' => 'block',
                    'start_at' => $start,
                    'end_at' => $end,
                    'reason' => 'Google Agenda',
                    'created_by' => 'artist',
                    'google_event_id' => $eventId,
                    // Read-only mirror: never push it back to Google.
                    'google_sync_pending' => false,
                ]);
                $counts['inserted']++;
            } catch (Throwable $e) {
                if ($this->isExclusionViolation($e)) {
                    continue;
                }

                throw $e;
            }
        }

        return $counts;
    }

    /**
     * Whether an active calendar entry overlaps the range (optionally ignoring
     * one entry, e.g. the block being moved).
     */
    private function collides(Artist $artist, CarbonImmutable $start, CarbonImmutable $end, ?string $ignoreId = null): bool
    {
        return CalendarEntry::where('artist_id', $artist->id)
            ->where('active', true)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->exists();
    }

    private function isExclusionViolation(Throwable $e): bool
    {
        return ($e instanceof \Illuminate\Database\QueryException && (string) $e->getCode() === '23P01')
            || str_contains($e->getMessage(), 'calendar_no_overlap');
    }

    /**
     * Start/end of a Google event. All-day events block the whole day in the
     * Artist's timezone; Google's all-day `end.date` is exclusive.
     *
     * @param  array<string, mixed>  $event
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function eventRange(array $event, string $timezone): array
    {
        $start = $event['start'] ?? [];
        $end = $event['end'] ?? [];
        $appTz = (string) config('app.timezone', 'UTC');

        if (isset($start['dateTime'])) {
            $startAt = CarbonImmutable::parse((string) $start['dateTime'])->setTimezone($appTz);
            $endAt = isset($end['dateTime'])
                ? CarbonImmutable::parse((string) $end['dateTime'])->setTimezone($appTz)
                : $startAt;

            return [$startAt, $endAt];
        }

        if (isset($start['date'])) {
            $tz = $timezone !== '' ? $timezone : 'UTC';
            $startAt = CarbonImmutable::parse((string) $start['date'], $tz)
                ->startOfDay()
                ->setTimezone($appTz);
            $endAt = isset($end['date'])
                ? CarbonImmutable::parse((string) $end['date'], $tz)->startOfDay()->setTimezone($appTz)
                : $startAt->addDay();

            if ($endAt <= $startAt) {
                $endAt = $startAt->addDay();
            }

            return [$startAt, $endAt];
        }

        return [null, null];
    }

    /**
     * Delete the Google events of a lead that is about to be removed.
     *
     * The lead deletion cascades to `calendar_entries`, so the reconcile loop
     * never sees them; without this the events stay orphaned in Google. Best
     * effort: a Google failure must not block the deletion.
     */
    public function deleteForLead(\App\Models\Lead $lead): int
    {
        $artist = $lead->artist;

        if (! $artist || ! $artist->hasGoogleCalendar()) {
            return 0;
        }

        $calendarId = (string) $artist->google_calendar_id;
        $deleted = 0;

        $entries = CalendarEntry::where('artist_id', $lead->artist_id)
            ->where('lead_id', $lead->id)
            ->whereNotNull('google_event_id')
            ->get();

        foreach ($entries as $entry) {
            try {
                $this->google->deleteEvent($calendarId, (string) $entry->google_event_id);
                $deleted++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $deleted;
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
