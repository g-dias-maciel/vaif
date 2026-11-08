<?php

namespace App\Http\Controllers;

use App\Models\CalendarEntry;
use App\Models\ChatMessage;
use App\Models\Project;
use App\Support\Periods;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Artist home (or the selected artist for staff): the agent toggle, a week
     * view of scheduled appointments, the faturamento summary, and the Project
     * pipeline — all in one dashboard. Staff without a selected artist go to
     * the Artists list.
     *
     * The pipeline is time-filtered: open stages are always shown, while closed
     * stages (fechado/perdido) only appear within the selected period, so a
     * long history does not bury the active work.
     */
    public function __invoke(Request $request)
    {
        if ($request->user()?->isSales()) {
            return redirect()->route('sales.dashboard');
        }

        $artist = $this->activeArtist();

        if (! $artist) {
            return redirect()->route('artists.index');
        }

        $period = Periods::fromRequest($request->query('period'));
        $from = Periods::start($period);
        $closedStatuses = config('crm.closed_pipeline_statuses');
        $pipeline = config('crm.pipeline_statuses');

        $projects = Project::with(['lead', 'acceptedQuote', 'latestQuote', 'payments', 'calendar'])
            ->where('artist_id', $artist->id)
            ->where(function ($query) use ($from, $closedStatuses) {
                // Open stages are always visible — a live deal must never
                // disappear because it is old.
                $query->where(function ($open) use ($closedStatuses) {
                    $open->whereNull('stage_key')
                        ->orWhereNotIn('stage_key', $closedStatuses);
                });

                // Closed history is windowed by when it closed.
                if ($from !== null) {
                    $query->orWhere(function ($closed) use ($from, $closedStatuses) {
                        $closed->whereIn('stage_key', $closedStatuses)
                            ->where('closed_at', '>=', $from);
                    });
                } else {
                    $query->orWhereIn('stage_key', $closedStatuses);
                }
            })
            ->orderByDesc('updated_at')
            ->get();

        // In-platform alert: the agent handed this client to the Artist and
        // the Artist has not resolved the handoff yet. Resolving happens when
        // the artist replies on WhatsApp (take_chat_control) or clicks
        // Assumir / Devolver à Beatriz on the dashboard.
        $pendingHandoffs = Project::with(['lead'])
            ->where('artist_id', $artist->id)
            ->where('stage_key', 'aguardando_artista')
            ->whereNull('handoff_resolved_at')
            ->orderByDesc('handoff_at')
            ->orderByDesc('updated_at')
            ->get();

        // In-platform alert: requested signals the Artist still needs to
        // confirm after checking the PIX.
        // Saved contacts the agent is holding until the artist decides whether
        // it may answer.
        $pendingContactGates = \App\Models\Lead::query()
            ->where('artist_id', $artist->id)
            ->where('contact_gate', 'pending')
            ->orderByDesc('updated_at')
            ->get();

        // Inbound messages the agent deliberately did not answer (paused
        // artist, unknown session). The table is the durable evidence; this
        // badge is how the artist sees it without waiting for the e-mail.
        // Keep the badge visible for a day, not just until the digest e-mail
        // goes out: the drop is still a client waiting.
        $ingestDrops = DB::table('agent_ingest_drops')
            ->where('artist_id', $artist->id)
            ->where('created_at', '>=', now()->subDay())
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $pendingDeposits = Project::with(['lead', 'payments', 'calendar'])
            ->where('artist_id', $artist->id)
            ->whereNotIn('stage_key', ['fechado', 'perdido'])
            ->whereHas('payments', function ($query) {
                $query->where('kind', 'deposit')->where('status', 'requested');
            })
            ->orderByDesc('updated_at')
            ->get();

        $realizadoCents = 0; // fechado — finished
        $agendadoCents = 0;  // agendado — booked, upcoming
        $esperadoCents = 0;  // active, sinal not paid — floating

        foreach ($projects as $project) {
            $price = $project->charged_price_cents;
            if ($price === null) {
                continue;
            }

            if ($project->stage_key === 'fechado') {
                $realizadoCents += $price;
            } elseif ($project->stage_key === 'agendado') {
                $agendadoCents += $price;
            } elseif ($project->stage_key !== 'perdido' && $project->deposit_status !== 'confirmado') {
                $esperadoCents += $price;
            }
        }

        $ref = now()->toImmutable();
        if ($week = $request->query('week')) {
            try {
                $ref = CarbonImmutable::parse($week);
            } catch (\Throwable) {
                // ignore an invalid week param
            }
        }

        $weekStart = $ref->startOfWeek(CarbonImmutable::MONDAY);
        $todayWeekStart = now()->toImmutable()->startOfWeek(CarbonImmutable::MONDAY);

        $slots = CalendarEntry::with(['lead', 'project'])
            ->where('artist_id', $artist->id)
            ->where('active', true)
            ->whereIn('kind', ['booking', 'block'])
            ->where('start_at', '>=', $weekStart)
            ->where('start_at', '<', $weekStart->addDays(7))
            ->orderBy('start_at')
            ->get()
            ->groupBy(fn (CalendarEntry $slot) => $slot->start_at->toDateString());

        $names = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

        $days = [];
        $weekCount = 0;
        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->addDays($i);
            $daySlots = $slots->get($date->toDateString()) ?? collect();
            $weekCount += $daySlots->where('kind', 'booking')->count();
            $days[] = [
                'date' => $date,
                'label' => $names[$date->dayOfWeekIso],
                'isToday' => $date->isSameDay(now()),
                'slots' => $daySlots,
            ];
        }

        $lastMessageAt = ChatMessage::latestTimesForLeads(
            $projects->pluck('lead_id')->unique()->filter()->all()
        );

        // Three friendly lanes instead of one wall of nine columns.
        $liveStages = ['novo', 'qualificando', 'orcamento_enviado', 'aguardando_deposito', 'agendado', 'aguardando_artista'];
        $closedStages = ['fechado', 'perdido'];
        $blockedStages = ['bloqueado'];

        $lanes = [
            'live' => [
                'label' => 'Em atendimento',
                'pipeline' => array_intersect_key($pipeline, array_flip($liveStages)),
                'open' => true,
            ],
            'closed' => [
                'label' => 'Encerrados',
                'pipeline' => array_intersect_key($pipeline, array_flip($closedStages)),
                'open' => false,
            ],
            'blocked' => [
                'label' => 'Bloqueados',
                'pipeline' => array_intersect_key($pipeline, array_flip($blockedStages)),
                'open' => false,
            ],
        ];

        $laneCounts = [];
        foreach ($lanes as $laneKey => $lane) {
            $laneCounts[$laneKey] = $projects
                ->whereIn('stage_key', array_keys($lane['pipeline']))
                ->count();
        }

        $needsAttention = $pendingHandoffs->count()
            + $pendingContactGates->count()
            + $pendingDeposits->count()
            + $ingestDrops->count();
        $inConversation = $projects
            ->whereIn('stage_key', ['novo', 'qualificando', 'orcamento_enviado', 'aguardando_deposito', 'aguardando_artista'])
            ->count();
        $scheduledCount = $projects->where('stage_key', 'agendado')->count();

        return view('dashboard.index', [
            'artist' => $artist,
            'lastMessageAt' => $lastMessageAt,
            'activePromotion' => $artist->activePromotion,
            'projects' => $projects,
            'pendingHandoffs' => $pendingHandoffs,
            'pendingContactGates' => $pendingContactGates,
            'pendingDeposits' => $pendingDeposits,
            'ingestDrops' => $ingestDrops,
            'pipeline' => $pipeline,
            'lanes' => $lanes,
            'laneCounts' => $laneCounts,
            'needsAttention' => $needsAttention,
            'inConversation' => $inConversation,
            'scheduledCount' => $scheduledCount,
            'period' => $period,
            'periodOptions' => Periods::options(),
            'periodLabel' => Periods::label($period),
            'realizadoCents' => $realizadoCents,
            'agendadoCents' => $agendadoCents,
            'esperadoCents' => $esperadoCents,
            'days' => $days,
            'weekCount' => $weekCount,
            'weekRange' => $weekStart->format('d/m').' – '.$weekStart->addDays(6)->format('d/m/Y'),
            'prevWeek' => $weekStart->subDays(7)->toDateString(),
            'nextWeek' => $weekStart->addDays(7)->toDateString(),
            'isCurrentWeek' => $weekStart->toDateString() === $todayWeekStart->toDateString(),
        ]);
    }
}
