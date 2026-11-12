<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\ConversationAudit;
use App\Services\Metrics\ConversationFunnel;
use App\Support\Periods;
use Illuminate\Http\Request;

/**
 * Staff-only side-by-side conversation funnel for every Artist.
 */
class StaffMetricsController extends Controller
{
    public function index(Request $request, ConversationFunnel $funnel)
    {
        $period = Periods::fromRequest($request->query('period'));
        $from = Periods::start($period);

        $rows = Artist::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Artist $artist) => [
                'artist' => $artist,
                'metrics' => $funnel->forArtist($artist, $from, now()),
                'audit' => $this->auditSummary($artist, $from),
            ]);

        return view('metrics.index', [
            'period' => $period,
            'rows' => $rows,
        ]);
    }

    /**
     * @return array{count: int, avg_score: ?float}
     */
    private function auditSummary(Artist $artist, mixed $from): array
    {
        try {
            $query = ConversationAudit::query()
                ->where('artist_id', $artist->id)
                ->where('status', ConversationAudit::STATUS_READY);

            if ($from !== null) {
                $query->where('generated_at', '>=', $from);
            }

            $audits = $query->get();
        } catch (\Throwable $e) {
            return ['count' => 0, 'avg_score' => null];
        }

        return [
            'count' => $audits->count(),
            'avg_score' => $audits->count() > 0 ? round((float) $audits->avg('score'), 1) : null,
        ];
    }
}
