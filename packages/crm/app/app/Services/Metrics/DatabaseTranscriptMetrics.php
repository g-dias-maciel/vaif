<?php

namespace App\Services\Metrics;

use Illuminate\Support\Facades\DB;

/**
 * Postgres transcript metrics. Only `human` (lead) and `ai` (agent or artist)
 * rows count; tool envelopes and empty LangChain bookkeeping are ignored.
 */
class DatabaseTranscriptMetrics implements TranscriptMetrics
{
    public function available(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    public function forLeads(array $leadIds): array
    {
        if ($leadIds === [] || ! $this->available()) {
            return [];
        }

        try {
            $rows = DB::table('agent_memory.chat_memory')
                ->select('session_id')
                ->selectRaw("min(created_at) filter (where message->>'type' = 'human') as first_inbound")
                ->selectRaw("min(created_at) filter (where message->>'type' = 'ai') as first_outbound")
                ->selectRaw("max(created_at) filter (where message->>'type' = 'human') as last_inbound")
                ->selectRaw("max(created_at) filter (where message->>'type' = 'ai') as last_outbound")
                ->whereIn('session_id', $leadIds)
                ->groupBy('session_id')
                ->get();
        } catch (\Throwable $e) {
            // An unmigrated or unavailable memory schema must not break the
            // dashboard; response metrics simply stay empty.
            report($e);

            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->session_id] = [
                'first_inbound' => $row->first_inbound !== null ? (string) $row->first_inbound : null,
                'first_outbound' => $row->first_outbound !== null ? (string) $row->first_outbound : null,
                'last_inbound' => $row->last_inbound !== null ? (string) $row->last_inbound : null,
                'last_outbound' => $row->last_outbound !== null ? (string) $row->last_outbound : null,
            ];
        }

        return $out;
    }
}
