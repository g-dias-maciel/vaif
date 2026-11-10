<?php

namespace App\Console\Commands;

use App\Services\Google\TeamGoogleCalendarSync;
use Illuminate\Console\Command;

class SyncTeamGoogleCalendar extends Command
{
    protected $signature = 'calendar:sync-team-events {--limit=200 : Máximo de eventos por execução}';

    protected $description = 'Sincroniza os compromissos internos com a Google Agenda do responsável';

    public function handle(TeamGoogleCalendarSync $sync): int
    {
        $counts = $sync->syncPending((int) $this->option('limit'));

        $this->line(sprintf(
            'created=%d updated=%d deleted=%d skipped=%d failed=%d',
            $counts['created'],
            $counts['updated'],
            $counts['deleted'],
            $counts['skipped'],
            $counts['failed'],
        ));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
