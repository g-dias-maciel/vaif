<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\Periods;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    // A won project is one that was booked (agendado) or closed (fechado).
    private const WON_STATUSES = ['agendado', 'fechado'];

    public function index(Request $request)
    {
        $artist = $this->activeArtist();

        if (! $artist) {
            return redirect()->route('artists.index');
        }

        $period = Periods::fromRequest($request->query('period'));
        $from = Periods::start($period);

        // A Project is the pipeline card: job + quotes + payments.
        $projects = Project::with(['lead', 'acceptedQuote', 'latestQuote', 'payments'])
            ->where('artist_id', $artist->id)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->get();

        $total = $projects->count();
        $won = $projects->whereIn('stage_key', self::WON_STATUSES)->count();
        $lost = $projects->where('stage_key', 'perdido')->count();

        $conversionRate = $total > 0 ? round($won / $total * 100, 1) : 0.0;
        $lostRate = $total > 0 ? round($lost / $total * 100, 1) : 0.0;

        $bySource = $projects->groupBy(fn (Project $project) => $project->lead?->source)
            ->map->count()
            ->filter(fn ($count, $source) => $source !== null && $source !== '');

        $wonProjects = $projects->whereIn('stage_key', self::WON_STATUSES)
            ->filter(fn (Project $project) => $project->charged_price_cents !== null);

        $avgTicket = $wonProjects->isNotEmpty()
            ? (int) round($wonProjects->avg(fn (Project $project) => $project->charged_price_cents))
            : 0;

        $revenue = (int) $wonProjects->sum(fn (Project $project) => $project->charged_price_cents);

        $byStage = $projects->groupBy('stage_key')->map->count();

        $pipelineStatuses = config('crm.pipeline_statuses');
        $monthly = $this->monthlyTrend($artist->id);

        return view('analytics.index', [
            'artist' => $artist,
            'period' => $period,
            'total' => $total,
            'won' => $won,
            'lost' => $lost,
            'conversionRate' => $conversionRate,
            'lostRate' => $lostRate,
            'avgTicketCents' => $avgTicket,
            'revenueCents' => $revenue,
            'pipelineLabels' => array_values($pipelineStatuses),
            'pipelineCounts' => array_map(fn (string $key) => $byStage[$key] ?? 0, array_keys($pipelineStatuses)),
            'sourceLabels' => $bySource->keys()->values()->all(),
            'sourceCounts' => $bySource->values()->all(),
            'monthLabels' => $monthly['labels'],
            'monthWon' => $monthly['won'],
            'monthRate' => $monthly['rate'],
            'monthRevenue' => $monthly['revenue'],
        ]);
    }

    /**
     * Last 12 months trend. Conversions and revenue are both project-level: a
     * won Project is bucketed by the month the job was created.
     *
     * @return array{labels: array, won: array, rate: array, revenue: array}
     */
    private function monthlyTrend(string $artistId): array
    {
        $ptMonths = [1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr', 5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez'];

        $start = now()->startOfMonth()->subMonths(11);

        $buckets = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $start->copy()->addMonths($i);
            $buckets[$m->format('Y-m')] = [
                'label' => $ptMonths[(int) $m->format('n')].'/'.$m->format('y'),
                'total' => 0,
                'won' => 0,
                'revenue' => 0,
            ];
        }

        $projects = Project::with(['acceptedQuote', 'latestQuote'])
            ->where('artist_id', $artistId)
            ->get();

        foreach ($projects as $project) {
            $key = $project->created_at?->format('Y-m');
            if ($key === null || ! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['total']++;

            if (! in_array($project->stage_key, self::WON_STATUSES, true)) {
                continue;
            }

            $buckets[$key]['won']++;

            if ($project->charged_price_cents !== null) {
                $buckets[$key]['revenue'] += $project->charged_price_cents;
            }
        }

        $labels = [];
        $won = [];
        $rate = [];
        $revenue = [];

        foreach ($buckets as $b) {
            $labels[] = $b['label'];
            $won[] = $b['won'];
            $rate[] = $b['total'] > 0 ? round($b['won'] / $b['total'] * 100, 1) : 0.0;
            $revenue[] = round($b['revenue'] / 100, 2);
        }

        return ['labels' => $labels, 'won' => $won, 'rate' => $rate, 'revenue' => $revenue];
    }
}
