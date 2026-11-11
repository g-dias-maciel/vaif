<?php

namespace App\Console\Commands;

use App\Models\AgentDraft;
use App\Models\Artist;
use App\Services\Agent\DraftComparator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Re-run the judge on already compared drafts.
 *
 * Comparisons made before the judge received conversation history (and before
 * media-only replies were flagged) scored the SDR against an incomplete
 * picture. This command recomputes them with the current rubric.
 */
class AgentRecompare extends Command
{
    protected $signature = 'agent:recompare
        {artist : Artist UUID or WAHA session slug}
        {--days=7 : Only drafts compared in this window}
        {--dry-run : List the drafts without calling the judge}';

    protected $description = 'Re-run the draft comparison with the current judge prompt';

    public function handle(DraftComparator $comparator): int
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

        $drafts = AgentDraft::query()
            ->where('artist_id', $artist->id)
            ->where('source', '!=', AgentDraft::SOURCE_SIMULATED)
            ->where('status', AgentDraft::STATUS_COMPARED)
            ->whereNotNull('artist_reply')
            ->where('compared_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))
            ->orderBy('created_at')
            ->get();

        if ($this->option('dry-run')) {
            $this->table(
                ['id', 'intent', 'artist reply'],
                $drafts->map(fn (AgentDraft $d) => [
                    $d->id,
                    $d->intent,
                    mb_substr(trim((string) $d->artist_reply), 0, 60),
                ])->all(),
            );

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($drafts->count());
        $failed = 0;

        foreach ($drafts as $draft) {
            try {
                $comparator->compare($draft);
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn("Draft {$draft->id}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Recompared {$drafts->count()} drafts ({$failed} failed).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
