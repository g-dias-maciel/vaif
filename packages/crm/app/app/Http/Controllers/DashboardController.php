<?php

namespace App\Http\Controllers;

use App\Models\CalendarSlot;
use App\Models\Tattoo;
use App\Support\Periods;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Artist home (or the selected artist for staff): the agent toggle, a week
     * view of scheduled appointments, the faturamento summary, and the Tattoo
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

        $tattoos = Tattoo::with('lead')
            ->where('artist_id', $artist->id)
            ->where(function ($query) use ($from, $closedStatuses) {
                // Open stages are always visible — a live deal must never
                // disappear because it is old.
                $query->where(function ($open) use ($closedStatuses) {
                    $open->whereNull('pipeline_status')
                        ->orWhereNotIn('pipeline_status', $closedStatuses);
                });

                // Closed history is windowed by last activity.
                if ($from !== null) {
                    $query->orWhere(function ($closed) use ($from, $closedStatuses) {
                        $closed->whereIn('pipeline_status', $closedStatuses)
                            ->where('updated_at', '>=', $from);
                    });
                } else {
                    $query->orWhereIn('pipeline_status', $closedStatuses);
                }
            })
            ->orderByDesc('updated_at')
            ->get();

        $realizadoCents = 0; // fechado — finished
        $agendadoCents = 0;  // agendado — booked, upcoming
        $esperadoCents = 0;  // active, sinal not paid — floating

        foreach ($tattoos as $tattoo) {
            $price = $tattoo->charged_price_cents;
            if ($price === null) {
                continue;
            }

            if ($tattoo->pipeline_status === 'fechado') {
                $realizadoCents += $price;
            } elseif ($tattoo->pipeline_status === 'agendado') {
                $agendadoCents += $price;
            } elseif ($tattoo->pipeline_status !== 'perdido' && $tattoo->deposit_status !== 'confirmado') {
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

        $slots = CalendarSlot::with(['lead', 'tattoo'])
            ->where('artist_id', $artist->id)
            ->whereIn('type', ['booked', 'blocked'])
            ->where('start_at', '>=', $weekStart)
            ->where('start_at', '<', $weekStart->addDays(7))
            ->orderBy('start_at')
            ->get()
            ->groupBy(fn (CalendarSlot $slot) => $slot->start_at->toDateString());

        $names = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

        $days = [];
        $weekCount = 0;
        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->addDays($i);
            $daySlots = $slots->get($date->toDateString()) ?? collect();
            $weekCount += $daySlots->where('type', 'booked')->count();
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
            'tattoos' => $tattoos,
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
