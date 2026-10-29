<?php

namespace App\Http\Controllers;

use App\Models\CalendarEntry;
use App\Models\Project;
use App\Support\Periods;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

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
        $artist = $this->activeArtist();

        if (! $artist) {
            return redirect()->route('artists.index');
        }

        $period = Periods::fromRequest($request->query('period'));
        $from = Periods::start($period);
        $closedStatuses = config('crm.closed_pipeline_statuses');

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

        // In-platform alert: requested signals the Artist still needs to
        // confirm after checking the PIX.
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

        return view('dashboard.index', [
            'artist' => $artist,
            'activePromotion' => $artist->activePromotion,
            'projects' => $projects,
            'pendingDeposits' => $pendingDeposits,
            'pipeline' => config('crm.pipeline_statuses'),
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
        ]);
    }
}
