<?php

namespace App\Console\Commands;

use App\Jobs\GenerateMockDrafts;
use App\Models\AgentDraft;
use App\Models\Artist;
use App\Models\Lead;
use App\Services\Agent\CalibrationConversation;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Queue retrospective Beatriz drafts for every customer message that has no
 * draft yet, across all leads of one artist.
 */
class AgentMockAll extends Command
{
    protected $signature = 'agent:mock-all
        {artist : Artist UUID or WAHA session slug}
        {--lead= : Limit to one Lead UUID}
        {--per-job=5 : Mock drafts per queue job}
        {--max=200 : Maximum missing messages to schedule}
        {--regenerate : Rebuild existing simulated drafts with the current prompt}
        {--dry-run : Only print what would be queued}';

    protected $description = 'Queue retrospective Beatriz drafts for every customer message without one';

    public function handle(CalibrationConversation $conversation): int
    {
        $key = (string) $this->argument('artist');
        $query = Artist::query()->where('wa_session_slug', $key);

        if (Str::isUuid($key)) {
            $query->orWhere('id', $key);
        }

        $artist = $query->first();

        if ($artist === null) {
            $this->error('Artist not found.');

            return self::FAILURE;
        }

        $leadQuery = Lead::query()->where('artist_id', $artist->id);

        if ($leadId = (string) $this->option('lead')) {
            $leadQuery->whereKey($leadId);
        } else {
            $leadQuery->whereIn('id', AgentDraft::query()
                ->where('artist_id', $artist->id)
                ->whereNotNull('lead_id')
                ->pluck('lead_id'));
        }

        $leads = $leadQuery->orderBy('name')->get();
        $regenerate = (bool) $this->option('regenerate');
        $perJob = max(1, (int) $this->option('per-job'));
        $max = max(1, (int) $this->option('max'));
        $remaining = $max;
        $jobs = 0;
        $scheduledMissing = 0;
        $rows = [];

        foreach ($leads as $lead) {
            if ($remaining <= 0) {
                break;
            }

            $missing = collect($conversation->forLead($lead, 500))
                ->filter(function ($block) use ($regenerate) {
                    if (empty($block['message']['id'])) {
                        return false;
                    }

                    if ($block['draft'] === null) {
                        return true;
                    }

                    return $regenerate && ($block['draft']['is_simulated'] ?? false);
                })
                ->count();

            if ($missing === 0) {
                continue;
            }

            $take = min($missing, $remaining);
            $batches = (int) ceil($take / $perJob);

            if (! $this->option('dry-run')) {
                for ($i = 0; $i < $batches; $i++) {
                    GenerateMockDrafts::dispatch($artist->id, $lead->id, $perJob, $regenerate);
                    $jobs++;
                }
            }

            $scheduledMissing += $take;
            $remaining -= $take;
            $rows[] = [$lead->name ?: '(sem nome)', $missing, $take, $batches];
        }

        $this->table(['lead', $regenerate ? 'to regenerate' : 'missing', 'scheduled', 'jobs'], $rows);

        $this->info(sprintf(
            '%s: %d missing message(s) scheduled, %d job(s)%s.',
            $artist->name,
            $scheduledMissing,
            $jobs,
            $this->option('dry-run') ? ' (dry run — nothing queued)' : '',
        ));

        return self::SUCCESS;
    }
}
