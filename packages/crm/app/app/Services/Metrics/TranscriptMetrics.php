<?php

namespace App\Services\Metrics;

/**
 * Message-level metrics for a set of Leads.
 *
 * The transcript lives in `agent_memory.chat_memory` on Postgres. This seam
 * keeps the funnel testable on SQLite and lets other channels plug in later.
 */
interface TranscriptMetrics
{
    public function available(): bool;

    /**
     * @param  array<int, string>  $leadIds
     * @return array<string, array{
     *     first_inbound: ?string,
     *     first_outbound: ?string,
     *     last_inbound: ?string,
     *     last_outbound: ?string
     * }>
     */
    public function forLeads(array $leadIds): array;
}
