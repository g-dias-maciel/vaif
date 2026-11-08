<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Services\Agent\ReplyBackfiller;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * One-off import of artist replies from the WAHA NOWEB store.
 *
 * WAHA only has history when `noweb.store.enabled` was on for the session.
 * Without it, the command reports the chats as having no history.
 */
class AgentBackfillReplies extends Command
{
    protected $signature = 'agent:backfill-replies
        {artist : Artist UUID or WAHA session slug}
        {--days=7 : How far back to import}
        {--dry-run : Count what would be imported without writing}
        {--no-compare : Import and pair, but do not run the judge}';

    protected $description = 'Import historical artist replies from WAHA and pair them with drafts';

    public function handle(ReplyBackfiller $backfiller): int
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

        $result = $backfiller->backfill(
            $artist,
            max(1, (int) $this->option('days')),
            (bool) $this->option('dry-run'),
            ! (bool) $this->option('no-compare'),
        );

        $this->table(
            ['metric', 'value'],
            [
                ['artist', $artist->name],
                ['session', $artist->wa_session_slug],
                ['chats', $result['chats']],
                ['chats without WAHA history', $result['chats_without_history']],
                ['artist messages found', $result['messages_found']],
                ['imported', $result['imported']],
                ['duplicates', $result['duplicates']],
                ['paired with a draft', $result['paired']],
                ['without a matching draft', $result['without_draft']],
                ['dry run', $result['dry_run'] ? 'yes' : 'no'],
            ],
        );

        if ($result['chats_without_history'] > 0) {
            $this->warn('Some chats returned no history. WAHA only stores history when noweb.store.enabled is on; enabling it on an already-paired session may require a re-link/QR.');
        }

        if ($result['dry_run']) {
            $this->warn('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }
}
