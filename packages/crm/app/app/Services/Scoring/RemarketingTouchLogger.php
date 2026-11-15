<?php

namespace App\Services\Scoring;

use App\Models\Artist;
use App\Models\Event;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records a remarketing attempt in the append-only `events` stream.
 *
 * No dedicated table in phase 1: the event is enough to hide a Lead for a
 * cooling-off window and to show the touch on the Lead timeline. The AI phase
 * will add `remarketing_touches` for the suggestion/send workflow.
 */
class RemarketingTouchLogger
{
    public const ACTION_WHATSAPP = 'whatsapp_opened';

    public const ACTION_CONTACTED = 'marked_contacted';

    public const ACTION_DISMISSED = 'dismissed';

    public function log(
        Artist $artist,
        Lead $lead,
        ?Project $project,
        string $action,
        string $segment,
        ?LeadScore $score,
        ?User $user,
    ): void {
        // `events` is partitioned by month in Postgres. The partition helper is
        // idempotent and granted to the runtime role; on SQLite it is a no-op.
        if (DB::connection()->getDriverName() === 'pgsql') {
            try {
                DB::statement('select create_event_partitions(2)');
            } catch (\Throwable $e) {
                report($e);
            }
        }

        Event::create([
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'project_id' => $project?->id,
            'actor' => $user?->isStaff() ? 'staff' : 'artist',
            'type' => 'remarketing_touch',
            'payload' => [
                'action' => $action,
                'segment' => $segment,
                'score' => $score?->score,
                'tier' => $score?->tier,
                'next_action' => $score?->nextAction,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * Latest touch per Lead, limited to the freshness window the queue cares
     * about.
     *
     * @param  array<int, string>  $leadIds
     * @return array<string, array{action: string, at: Carbon, payload: array<string, mixed>}>
     */
    public function latestForLeads(array $leadIds, int $days = 120): array
    {
        if ($leadIds === []) {
            return [];
        }

        $touches = [];

        $events = Event::query()
            ->where('type', 'remarketing_touch')
            ->whereIn('lead_id', $leadIds)
            ->where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('created_at')
            ->get(['lead_id', 'payload', 'created_at']);

        foreach ($events as $event) {
            $leadId = (string) $event->lead_id;

            if (isset($touches[$leadId])) {
                continue;
            }

            $payload = is_array($event->payload) ? $event->payload : [];
            $action = (string) ($payload['action'] ?? '');

            if ($action === '') {
                continue;
            }

            $touches[$leadId] = [
                'action' => $action,
                'at' => $event->created_at,
                'payload' => $payload,
            ];
        }

        return $touches;
    }

    /**
     * Number of real attempts (WhatsApp opened or "já falei") per Lead.
     *
     * @param  array<int, string>  $leadIds
     * @return array<string, int>
     */
    public function recentAttempts(array $leadIds, int $days = 30): array
    {
        if ($leadIds === []) {
            return [];
        }

        $attempts = [];

        $events = Event::query()
            ->where('type', 'remarketing_touch')
            ->whereIn('lead_id', $leadIds)
            ->where('created_at', '>=', now()->subDays($days))
            ->get(['lead_id', 'payload']);

        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $action = (string) ($payload['action'] ?? '');

            if (! in_array($action, [self::ACTION_WHATSAPP, self::ACTION_CONTACTED], true)) {
                continue;
            }

            $leadId = (string) $event->lead_id;
            $attempts[$leadId] = ($attempts[$leadId] ?? 0) + 1;
        }

        return $attempts;
    }
}
