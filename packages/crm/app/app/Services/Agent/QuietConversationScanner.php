<?php

namespace App\Services\Agent;

use App\Models\ConversationAudit;
use App\Models\Lead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Finds observe-mode conversations that have been quiet long enough to audit
 * and have no audit for their latest message.
 */
class QuietConversationScanner
{
    /**
     * @return array<int, array{artist_id: string, lead_id: string, last_at: string}>
     */
    public function candidates(int $limit = 50): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        $quietBefore = Carbon::now()->subHours(max(1, (int) config('agent.audit.quiet_hours', 24)));

        try {
            $rows = DB::table('agent_memory.chat_memory')
                ->select('session_id')
                ->selectRaw('max(created_at) as last_at')
                ->whereRaw("message->>'type' in ('human', 'ai')")
                ->groupBy('session_id')
                ->havingRaw('max(created_at) <= ?', [$quietBefore])
                ->orderByDesc('last_at')
                ->limit(max(1, $limit))
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        if ($rows->isEmpty()) {
            return [];
        }

        $sessionIds = $rows->pluck('session_id')->map(fn ($id) => (string) $id)->all();

        $leads = Lead::query()
            ->with(['artist.settings'])
            ->whereIn('id', $sessionIds)
            ->get()
            ->keyBy(fn (Lead $lead) => (string) $lead->id);

        $latestAudits = ConversationAudit::query()
            ->whereIn('chat_id', $sessionIds)
            ->orderByDesc('generated_at')
            ->get()
            ->unique('chat_id')
            ->keyBy('chat_id');

        $out = [];

        foreach ($rows as $row) {
            $leadId = (string) $row->session_id;
            $lead = $leads[$leadId] ?? null;

            if ($lead === null || $lead->artist === null) {
                continue;
            }

            if (($lead->artist->settings?->reply_mode ?? '') !== 'observe') {
                continue;
            }

            if ($lead->artist->status !== 'live') {
                continue;
            }

            $audit = $latestAudits[$leadId] ?? null;

            if ($audit !== null
                && $audit->last_message_at !== null
                && Carbon::parse($audit->last_message_at)->greaterThanOrEqualTo(Carbon::parse($row->last_at))) {
                continue;
            }

            $out[] = [
                'artist_id' => (string) $lead->artist_id,
                'lead_id' => $leadId,
                'last_at' => (string) $row->last_at,
            ];
        }

        return $out;
    }
}
