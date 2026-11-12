<?php

namespace App\Services\Metrics;

use App\Models\Artist;
use App\Models\CalendarEntry;
use App\Models\Event;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Quote;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The conversation funnel for one Artist and one period.
 *
 * Definitions are fixed here so the Artist page and the staff comparison use
 * the same numbers:
 *
 *   new leads        Leads whose first contact is inside the period
 *   responded        new leads with an outbound message after the first inbound
 *   first response   median minutes from first inbound to first outbound
 *   qualified        project reached `qualificando` or beyond
 *   quoted           a quote was created in the period
 *   booked           a new lead has an active booking (agendamento)
 *   closed / lost    project reached `fechado` / `perdido` in the period
 *   ghosted          studio spoke last, no booking/close/loss, 7+ days silent
 *   closing rate     booked leads ÷ new leads
 */
class ConversationFunnel
{
    public const GHOST_DAYS = 7;

    /** Stages that mean discovery has started or the project is past it. */
    private const QUALIFIED_STAGES = [
        'qualificando',
        'orcamento_enviado',
        'aguardando_deposito',
        'agendado',
        'aguardando_artista',
        'fechado',
    ];

    public function __construct(
        private readonly TranscriptMetrics $transcripts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forArtist(Artist $artist, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $from = $from !== null ? Carbon::parse($from) : null;
        $to = $to !== null ? Carbon::parse($to) : Carbon::now();

        $leadsQuery = Lead::query()->where('artist_id', $artist->id);

        if ($from !== null) {
            $leadsQuery->whereRaw('coalesce(first_seen_at, created_at) >= ?', [$from]);
        }

        $leadsQuery->whereRaw('coalesce(first_seen_at, created_at) <= ?', [$to]);

        $leads = $leadsQuery->get(['id', 'first_seen_at', 'created_at']);
        $leadIds = $leads->pluck('id')->all();

        $projects = $leadIds === []
            ? collect()
            : Project::query()
                ->where('artist_id', $artist->id)
                ->whereIn('lead_id', $leadIds)
                ->get(['id', 'lead_id', 'stage_key', 'closed_at', 'created_at']);

        $projectById = $projects->keyBy('id');
        $projectIds = $projects->pluck('id')->all();

        $quotedLeads = $this->quotedLeads($artist, $projectIds, $projectById, $from, $to);
        $bookings = $this->bookings($artist, $projectIds, $projectById);

        $bookedLeads = [];
        foreach ($bookings as $booking) {
            $leadId = $booking->lead_id
                ?? ($projectById[$booking->project_id]->lead_id ?? null);

            if ($leadId !== null) {
                $bookedLeads[(string) $leadId] = true;
            }
        }

        [$qualifiedLeads, $closedLeads, $lostLeads] = $this->stageSets(
            $artist,
            $projects,
            $projectById,
            $from,
            $to,
        );

        $transcripts = $this->transcripts->available()
            ? $this->transcripts->forLeads($leadIds)
            : [];

        [$responded, $responseMinutes, $ghosted] = $this->messageMetrics(
            $leads,
            $transcripts,
            $bookedLeads,
            $closedLeads,
            $lostLeads,
            $to,
        );

        $leadCount = count($leadIds);
        $quotedCount = count($quotedLeads);
        $bookedCount = count($bookedLeads);

        $bookingsCreated = $bookings->filter(fn ($booking) => $this->within($booking->created_at, $from, $to))->count();
        $upcoming = $bookings->filter(
            fn ($booking) => $booking->start_at !== null && Carbon::parse($booking->start_at)->greaterThanOrEqualTo($to)
        )->count();

        return [
            'period' => [
                'from' => $from?->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'leads' => $leadCount,
            'responded' => $responded,
            'first_response_minutes' => $this->median($responseMinutes),
            'qualified' => count($qualifiedLeads),
            'quoted' => $quotedCount,
            'booked' => $bookedCount,
            'bookings_created' => $bookingsCreated,
            'upcoming_bookings' => $upcoming,
            'closed' => count($closedLeads),
            'lost' => count($lostLeads),
            'ghosted' => $ghosted,
            'rates' => [
                'response' => $this->rate($responded, $leadCount),
                'closing' => $this->rate($bookedCount, $leadCount),
                'quote_to_booking' => $this->rate($bookedCount, $quotedCount),
                'booking_to_closed' => $this->rate(count($closedLeads), $bookedCount),
                'ghosted' => $this->rate($ghosted, $leadCount),
            ],
        ];
    }

    /**
     * @param  array<int, string>  $projectIds
     * @param  \Illuminate\Support\Collection<string, Project>  $projectById
     * @return array<string, true>
     */
    private function quotedLeads(
        Artist $artist,
        array $projectIds,
        $projectById,
        ?Carbon $from,
        Carbon $to,
    ): array {
        if ($projectIds === []) {
            return [];
        }

        $query = Quote::query()
            ->where('artist_id', $artist->id)
            ->whereIn('project_id', $projectIds)
            ->where('created_at', '<=', $to);

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        $out = [];

        foreach ($query->get(['id', 'project_id']) as $quote) {
            $leadId = $projectById[$quote->project_id]->lead_id ?? null;

            if ($leadId !== null) {
                $out[(string) $leadId] = true;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $projectIds
     * @param  \Illuminate\Support\Collection<string, Project>  $projectById
     * @return \Illuminate\Support\Collection<int, CalendarEntry>
     */
    private function bookings(Artist $artist, array $projectIds, $projectById)
    {
        if ($projectIds === []) {
            return collect();
        }

        return CalendarEntry::query()
            ->where('artist_id', $artist->id)
            ->where('kind', 'booking')
            ->where('active', true)
            ->whereIn('project_id', $projectIds)
            ->get(['id', 'project_id', 'lead_id', 'start_at', 'created_at']);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Project>  $projects
     * @param  \Illuminate\Support\Collection<string, Project>  $projectById
     * @return array{0: array<string, true>, 1: array<string, true>, 2: array<string, true>}
     */
    private function stageSets(
        Artist $artist,
        $projects,
        $projectById,
        ?Carbon $from,
        Carbon $to,
    ): array {
        $qualified = [];
        $closed = [];
        $lost = [];

        $events = $projectById->isEmpty()
            ? collect()
            : Event::query()
                ->where('artist_id', $artist->id)
                ->whereIn('project_id', $projectById->keys()->all())
                ->where('type', 'stage_changed')
                ->get(['project_id', 'payload', 'created_at']);

        foreach ($events as $event) {
            $stage = is_array($event->payload) ? ($event->payload['stage'] ?? null) : null;
            $leadId = $projectById[$event->project_id]->lead_id ?? null;

            if ($leadId === null || ! is_string($stage)) {
                continue;
            }

            $leadId = (string) $leadId;

            if (in_array($stage, self::QUALIFIED_STAGES, true)) {
                $qualified[$leadId] = true;
            }

            if (! $this->within($event->created_at, $from, $to)) {
                continue;
            }

            if ($stage === 'fechado') {
                $closed[$leadId] = true;
            }

            if ($stage === 'perdido') {
                $lost[$leadId] = true;
            }
        }

        // Current state is the fallback for projects that predate the event
        // log or were imported without history.
        foreach ($projects as $project) {
            $leadId = (string) $project->lead_id;

            if (in_array($project->stage_key, self::QUALIFIED_STAGES, true)) {
                $qualified[$leadId] = true;
            }

            if (! $this->within($project->closed_at, $from, $to)) {
                continue;
            }

            if ($project->stage_key === 'fechado') {
                $closed[$leadId] = true;
            }

            if ($project->stage_key === 'perdido') {
                $lost[$leadId] = true;
            }
        }

        return [$qualified, $closed, $lost];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Lead>  $leads
     * @param  array<string, array<string, ?string>>  $transcripts
     * @param  array<string, true>  $booked
     * @param  array<string, true>  $closed
     * @param  array<string, true>  $lost
     * @return array{0: int, 1: array<int, int>, 2: int}
     */
    private function messageMetrics(
        $leads,
        array $transcripts,
        array $booked,
        array $closed,
        array $lost,
        Carbon $to,
    ): array {
        $responded = 0;
        $minutes = [];
        $ghosted = 0;
        $ghostBefore = $to->copy()->subDays(self::GHOST_DAYS);

        foreach ($leads as $lead) {
            $leadId = (string) $lead->id;
            $entry = $transcripts[$leadId] ?? null;
            $firstSeen = $lead->first_seen_at ?? $lead->created_at;
            $notGhostable = isset($booked[$leadId]) || isset($closed[$leadId]) || isset($lost[$leadId]);

            if ($entry === null) {
                // With the transcript available but no messages, a stale lead
                // that never booked is ghosted.
                if ($this->transcripts->available()
                    && ! $notGhostable
                    && $firstSeen !== null
                    && Carbon::parse($firstSeen)->lessThan($ghostBefore)) {
                    $ghosted++;
                }

                continue;
            }

            $firstIn = $this->time($entry['first_inbound'] ?? null);
            $firstOut = $this->time($entry['first_outbound'] ?? null);
            $lastIn = $this->time($entry['last_inbound'] ?? null);
            $lastOut = $this->time($entry['last_outbound'] ?? null);

            if ($firstIn !== null && $firstOut !== null && $firstOut->greaterThan($firstIn)) {
                $responded++;
                $minutes[] = (int) round($firstIn->diffInSeconds($firstOut) / 60);
            }

            if ($notGhostable || ($lastIn !== null && $lastOut !== null && $lastIn->greaterThan($lastOut))) {
                continue;
            }

            $lastActivity = collect([$firstIn, $firstOut, $lastIn, $lastOut, $firstSeen])
                ->filter()
                ->map(fn ($value) => $value instanceof CarbonInterface ? $value : Carbon::parse($value))
                ->sortDesc()
                ->first();

            if ($lastActivity !== null && $lastActivity->lessThan($ghostBefore)) {
                $ghosted++;
            }
        }

        return [$responded, $minutes, $ghosted];
    }

    private function time(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function within(mixed $value, ?Carbon $from, Carbon $to): bool
    {
        if ($value === null) {
            return false;
        }

        $at = $value instanceof CarbonInterface ? Carbon::parse($value) : $this->time((string) $value);

        if ($at === null) {
            return false;
        }

        if ($from !== null && $at->lessThan($from)) {
            return false;
        }

        return $at->lessThanOrEqualTo($to);
    }

    /**
     * @param  array<int, int>  $values
     */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = (int) floor($count / 2);

        return $count % 2 === 0
            ? (int) round(($values[$middle - 1] + $values[$middle]) / 2)
            : (int) $values[$middle];
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 4) : 0.0;
    }
}
