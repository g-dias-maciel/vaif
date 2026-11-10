<?php

namespace App\Services\Google;

use App\Models\TeamEvent;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mirrors internal team events into the responsible user's Google Calendar.
 *
 * The team member shares their calendar with the existing VAIF service account;
 * this service writes to that calendar. If the owner changes, or their calendar
 * is disconnected/replaced, the old Google event is deleted and the event is
 * recreated in the new calendar.
 */
class TeamGoogleCalendarSync
{
    public function __construct(private GoogleCalendarService $google) {}

    /**
     * @return array{created: int, updated: int, deleted: int, skipped: int, failed: int}
     */
    public function syncPending(int $limit = 200): array
    {
        $counts = $this->emptyCounts();

        if (! $this->google->isConfigured()) {
            return $counts;
        }

        $events = TeamEvent::withTrashed()
            ->with(['owner', 'prospect'])
            ->where('google_sync_pending', true)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($events as $event) {
            $this->syncOne($event, $counts);
        }

        return $counts;
    }

    /**
     * Push/reconcile the pending events owned by one user, used after connecting
     * or disconnecting a calendar.
     *
     * @return array{created: int, updated: int, deleted: int, skipped: int, failed: int}
     */
    public function syncUser(User $user, int $limit = 200): array
    {
        $counts = $this->emptyCounts();

        if (! $this->google->isConfigured()) {
            return $counts;
        }

        $events = TeamEvent::query()
            ->with(['owner', 'prospect'])
            ->where('owner_id', $user->id)
            ->where('google_sync_pending', true)
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();

        foreach ($events as $event) {
            $this->syncOne($event, $counts);
        }

        return $counts;
    }

    /**
     * @param  array{created: int, updated: int, deleted: int, skipped: int, failed: int}  $counts
     */
    private function syncOne(TeamEvent $event, array &$counts): void
    {
        try {
            $counts[$this->syncEvent($event)]++;
        } catch (Throwable $e) {
            $counts['failed']++;

            $event->forceFill([
                'google_sync_pending' => true,
                'google_sync_error' => Str::limit($e->getMessage(), 500),
            ])->save();

            report($e);
        }
    }

    /**
     * @return 'created'|'updated'|'deleted'|'skipped'
     */
    private function syncEvent(TeamEvent $event): string
    {
        if ($event->trashed()) {
            if ($event->google_event_id && $event->google_calendar_id) {
                $this->google->deleteEvent($event->google_calendar_id, $event->google_event_id);
            }

            $event->forceDelete();

            return 'deleted';
        }

        $owner = $event->owner;
        $target = $owner?->google_calendar_id;

        if (blank($target)) {
            if ($event->google_event_id && $event->google_calendar_id) {
                $this->google->deleteEvent($event->google_calendar_id, $event->google_event_id);
                $this->mark($event, null, null);

                return 'deleted';
            }

            $event->forceFill([
                'google_sync_pending' => false,
                'google_sync_error' => null,
            ])->save();

            return 'skipped';
        }

        // The event used to live in another calendar (owner changed or the
        // user connected a different calendar). Delete the old copy first.
        if ($event->google_event_id
            && $event->google_calendar_id
            && $event->google_calendar_id !== $target) {
            $this->google->deleteEvent($event->google_calendar_id, $event->google_event_id);
            $event->forceFill([
                'google_event_id' => null,
                'google_calendar_id' => null,
            ])->save();
        }

        $payload = $this->eventPayload($event, $owner);

        if ($event->google_event_id) {
            try {
                $this->google->updateEvent($target, $event->google_event_id, $payload);
                $this->mark($event, $event->google_event_id, $target);

                return 'updated';
            } catch (GoogleCalendarException $e) {
                if (! $e->isNotFound()) {
                    throw $e;
                }

                // Somebody deleted the Google event; recreate it below.
                $event->forceFill(['google_event_id' => null])->save();
            }
        }

        $created = $this->google->createEvent($target, $payload);
        $eventId = $created['id'] ?? null;

        if (! is_string($eventId) || $eventId === '') {
            throw new GoogleCalendarException('O Google não devolveu o id do evento criado.');
        }

        $this->mark($event, $eventId, $target);

        return 'created';
    }

    private function mark(TeamEvent $event, ?string $eventId, ?string $calendarId): void
    {
        $event->forceFill([
            'google_event_id' => $eventId,
            'google_calendar_id' => $calendarId,
            'google_sync_pending' => false,
            'google_synced_at' => now(),
            'google_sync_error' => null,
        ])->save();
    }

    /**
     * The Google Calendar event body. Kept public so the shape is testable.
     *
     * @return array<string, mixed>
     */
    public function eventPayload(TeamEvent $event, User $owner): array
    {
        $timezone = $owner->calendarTimezone();
        $start = $event->starts_at->copy()->setTimezone($timezone);
        $end = $event->ends_at->copy()->setTimezone($timezone);

        $lines = [];

        if ($event->prospect) {
            $lines[] = 'Prospect: '.$event->prospect->name;
        }

        if ($event->meeting_url) {
            $lines[] = 'Link: '.$event->meeting_url;
        }

        if ($event->notes) {
            $lines[] = $event->notes;
        }

        $payload = [
            'summary' => $event->title,
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
                    ['method' => 'popup', 'minutes' => 60],
                ],
            ],
            'extendedProperties' => [
                'private' => [
                    'vaif_team_event_id' => $event->id,
                ],
            ],
        ];

        if (filled($event->location)) {
            $payload['location'] = $event->location;
        }

        return $payload;
    }

    /**
     * @return array{created: int, updated: int, deleted: int, skipped: int, failed: int}
     */
    private function emptyCounts(): array
    {
        return ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'failed' => 0];
    }
}
