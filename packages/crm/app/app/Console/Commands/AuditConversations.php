<?php

namespace App\Console\Commands;

use App\Jobs\AuditConversation;
use App\Services\Agent\QuietConversationScanner;
use Illuminate\Console\Command;

/**
 * Queue methodology audits for observe-mode conversations that have been quiet
 * long enough. Idempotency is enforced again inside the job by transcript hash.
 */
class AuditConversations extends Command
{
    protected $signature = 'agent:audit-conversations
        {--limit=50 : Maximum conversations to inspect in this run}';

    protected $description = 'Queue post-hoc methodology audits for quiet observe-mode conversations';

    public function handle(QuietConversationScanner $scanner): int
    {
        $candidates = $scanner->candidates((int) $this->option('limit'));

        foreach ($candidates as $candidate) {
            AuditConversation::dispatch(
                $candidate['artist_id'],
                $candidate['lead_id'],
            )->onQueue((string) config('agent.queue', 'agent'));
        }

        $this->info(count($candidates).' conversa(s) enviada(s) para auditoria.');

        return self::SUCCESS;
    }
}
