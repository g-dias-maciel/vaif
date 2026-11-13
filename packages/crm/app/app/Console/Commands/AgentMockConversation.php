<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\Lead;
use App\Services\Agent\MockDraftGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AgentMockConversation extends Command
{
    protected $signature = 'agent:mock-conversation
        {artist : Artist UUID or WAHA session slug}
        {--lead= : Lead UUID}
        {--limit=20 : Maximum number of missing messages to simulate}
        {--regenerate : Rebuild existing simulated drafts with the current prompt}';

    protected $description = 'Generate retrospective Beatriz drafts for customer messages without one';

    public function handle(MockDraftGenerator $generator): int
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

        $leadId = (string) $this->option('lead');

        if ($leadId === '') {
            $this->error('Pass --lead=<uuid>.');

            return self::FAILURE;
        }

        $lead = Lead::query()
            ->where('artist_id', $artist->id)
            ->find($leadId);

        if ($lead === null) {
            $this->error('Lead not found for this artist.');

            return self::FAILURE;
        }

        $regenerate = (bool) $this->option('regenerate');
        $result = $generator->generateForLead($artist, $lead, max(1, (int) $this->option('limit')), $regenerate);

        $this->table(['metric', 'value'], [
            ['artist', $artist->name],
            ['lead', $lead->name],
            [$regenerate ? 'to regenerate' : 'missing before', $result['missing']],
            ['generated', $result['generated']],
            ['regenerated', $result['regenerated'] ?? 0],
            ['skipped', $result['skipped']],
        ]);

        return self::SUCCESS;
    }
}
