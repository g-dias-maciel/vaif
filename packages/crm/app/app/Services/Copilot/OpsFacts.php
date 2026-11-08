<?php

namespace App\Services\Copilot;

use App\Models\AgentDraft;
use App\Models\Artist;
use App\Models\Project;
use App\Services\Agent\CalibrationReport;
use App\Services\WahaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic operational facts for the staff Copilot. No LLM here: every
 * number is traceable to the database or the WAHA API.
 */
class OpsFacts
{
    public function __construct(
        private readonly WahaService $waha,
        private readonly CalibrationReport $calibration,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $now = now();
        $hours = (int) config('copilot.windows.ops_hours', 24);
        $since = $now->copy()->subHours($hours);

        return [
            'generated_at' => $now->toIso8601String(),
            'window_hours' => $hours,
            'queue' => $this->queue($now),
            'failed_jobs' => $this->failedJobs($since),
            'webhooks' => $this->webhooks(),
            'handoffs' => $this->handoffs($now),
            'stuck_drafts' => $this->stuckDrafts($now),
            'ingest_drops' => $this->ingestDrops($since),
            'calibration' => $this->calibrationSummary(),
            'artists' => $this->artists(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queue(Carbon $now): array
    {
        try {
            $pending = (int) DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('available_at');
            $reserved = (int) DB::table('jobs')->whereNotNull('reserved_at')->count();

            return [
                'pending' => $pending,
                'reserved' => $reserved,
                'oldest_age_minutes' => $oldest !== null
                    ? max(0, (int) floor(($now->timestamp - (int) $oldest) / 60))
                    : null,
            ];
        } catch (\Throwable $e) {
            return ['pending' => null, 'reserved' => null, 'oldest_age_minutes' => null, 'error' => true];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function failedJobs(Carbon $since): array
    {
        try {
            $recent = DB::table('failed_jobs')
                ->where('failed_at', '>=', $since)
                ->orderByDesc('failed_at')
                ->limit(5)
                ->get(['uuid', 'queue', 'failed_at', 'exception']);

            return [
                'total' => (int) DB::table('failed_jobs')->count(),
                'recent' => $recent->count(),
                'last_failed_at' => optional($recent->first())->failed_at,
                'samples' => $recent->map(fn ($row) => [
                    'uuid' => $row->uuid,
                    'queue' => $row->queue,
                    'failed_at' => $row->failed_at,
                    'exception' => mb_substr(trim((string) $row->exception), 0, 300),
                ])->all(),
            ];
        } catch (\Throwable $e) {
            return ['total' => null, 'recent' => null, 'samples' => [], 'error' => true];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function webhooks(): array
    {
        $crm = rtrim((string) config('transport.waha_webhook_url'), '/');
        $n8n = rtrim((string) config('transport.n8n_webhook_url'), '/');

        $artists = Artist::with('settings')
            ->whereNotNull('wa_session_slug')
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($artists as $artist) {
            $info = $this->waha->webhookInfo($artist);
            $url = $info['url'] ?? null;
            $target = 'unknown';

            if ($url !== null) {
                $url = rtrim($url, '/');
                $target = $url === $crm ? 'crm' : ($url === $n8n ? 'n8n' : 'other');
            }

            $rows[] = [
                'artist' => $artist->name,
                'session' => $artist->wa_session_slug,
                'artist_status' => $artist->status,
                'reply_mode' => $artist->settings?->reply_mode ?? 'draft',
                'session_status' => $info['status'] ?? null,
                'reachable' => $info !== null,
                'webhook' => $target,
                'url' => $url,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function handoffs(Carbon $now): array
    {
        $warning = (int) config('copilot.thresholds.handoff_warning_hours', 1);
        $critical = (int) config('copilot.thresholds.handoff_critical_hours', 4);

        try {
            $projects = Project::with(['artist:id,name', 'lead:id,name'])
                ->where('stage_key', 'aguardando_artista')
                ->whereNull('handoff_resolved_at')
                ->orderBy('handoff_at')
                ->get();

            $rows = $projects->map(function (Project $project) use ($now) {
                $at = $project->handoff_at ?? $project->updated_at;
                $age = $at ? max(0, (int) floor($at->diffInMinutes($now) / 60)) : null;

                return [
                    'project_id' => $project->id,
                    'artist' => $project->artist?->name,
                    'lead' => $project->lead?->name,
                    'reason' => $project->handoff_reason,
                    'age_hours' => $age,
                ];
            })->values();

            return [
                'open' => $rows->count(),
                'over_warning' => $rows->filter(fn ($r) => $r['age_hours'] !== null && $r['age_hours'] >= $warning)->count(),
                'over_critical' => $rows->filter(fn ($r) => $r['age_hours'] !== null && $r['age_hours'] >= $critical)->count(),
                'oldest' => $rows->take(5)->all(),
            ];
        } catch (\Throwable $e) {
            return ['open' => null, 'over_warning' => null, 'over_critical' => null, 'oldest' => [], 'error' => true];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stuckDrafts(Carbon $now): array
    {
        $pendingHours = (int) config('copilot.thresholds.stuck_draft_hours', 24);
        $repliedHours = (int) config('copilot.thresholds.stuck_replied_hours', 2);

        try {
            $pending = AgentDraft::query()
                ->where('status', AgentDraft::STATUS_PENDING)
                ->where('created_at', '<', $now->copy()->subHours($pendingHours))
                ->orderBy('created_at')
                ->limit(10)
                ->get();

            $replied = AgentDraft::query()
                ->where('status', AgentDraft::STATUS_REPLIED)
                ->whereNull('comparison')
                ->where('artist_replied_at', '<', $now->copy()->subHours($repliedHours))
                ->orderBy('artist_replied_at')
                ->limit(10)
                ->get();

            return [
                'pending_over_threshold' => $pending->count(),
                'replied_without_comparison' => $replied->count(),
                'samples' => $pending->map(fn (AgentDraft $d) => [
                    'id' => $d->id,
                    'artist_id' => $d->artist_id,
                    'intent' => $d->intent,
                    'status' => $d->status,
                    'age_hours' => (int) floor($d->created_at->diffInHours($now)),
                ])->merge($replied->map(fn (AgentDraft $d) => [
                    'id' => $d->id,
                    'artist_id' => $d->artist_id,
                    'intent' => $d->intent,
                    'status' => $d->status,
                    'age_hours' => $d->artist_replied_at ? (int) floor($d->artist_replied_at->diffInHours($now)) : null,
                ]))->values()->all(),
            ];
        } catch (\Throwable $e) {
            return ['pending_over_threshold' => null, 'replied_without_comparison' => null, 'samples' => [], 'error' => true];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ingestDrops(Carbon $since): array
    {
        try {
            $byReason = DB::table('agent_ingest_drops')
                ->where('created_at', '>=', $since)
                ->select('reason', DB::raw('count(*) as total'))
                ->groupBy('reason')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row) => ['reason' => $row->reason, 'total' => (int) $row->total])
                ->all();

            return [
                'total' => array_sum(array_column($byReason, 'total')),
                'by_reason' => $byReason,
            ];
        } catch (\Throwable $e) {
            return ['total' => null, 'by_reason' => [], 'error' => true];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function calibrationSummary(): array
    {
        return Artist::with('settings')
            ->orderBy('name')
            ->get()
            ->map(function (Artist $artist) {
                $report = $this->calibration->forArtist($artist, (int) config('copilot.windows.gates_days', 30));

                return [
                    'artist' => $artist->name,
                    'reply_mode' => $report['reply_mode'],
                    'passed' => $report['passed'],
                    'compared' => $report['totals']['compared'],
                    'acceptable_rate' => $report['rates']['acceptable'],
                    'response_rate' => $report['rates']['response'],
                    'warnings' => $report['warnings'],
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function artists(): array
    {
        return Artist::with('settings')
            ->orderBy('name')
            ->get()
            ->map(fn (Artist $artist) => [
                'id' => $artist->id,
                'name' => $artist->name,
                'status' => $artist->status,
                'session' => $artist->wa_session_slug,
                'reply_mode' => $artist->settings?->reply_mode ?? 'draft',
            ])
            ->all();
    }
}
