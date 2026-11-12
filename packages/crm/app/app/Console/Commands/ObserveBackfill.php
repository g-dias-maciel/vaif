<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Services\Agent\ConversationBackfiller;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Import an Artist's existing WhatsApp 1:1 conversations into observe mode.
 *
 * Dry-run first; a real run is idempotent by WAHA message id.
 */
class ObserveBackfill extends Command
{
    protected $signature = 'agent:observe-backfill
        {artist : Artist UUID or WAHA session slug}
        {--days=90 : Import conversations with messages in the last N days}
        {--limit=100 : Maximum chats to process}
        {--exclude= : Comma-separated phone numbers to skip}
        {--dry-run : Count what would be imported without writing or calling a model}';

    protected $description = 'Backfill an Artist\'s existing WhatsApp history into observe mode';

    public function handle(ConversationBackfiller $backfiller): int
    {
        $artist = $this->artist();

        if ($artist === null) {
            return self::FAILURE;
        }

        $exclude = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('exclude')),
        )));

        $counts = $backfiller->run(
            $artist,
            max(1, (int) $this->option('days')),
            $exclude,
            max(1, (int) $this->option('limit')),
            (bool) $this->option('dry-run'),
        );

        if (isset($counts['error'])) {
            $this->error('WAHA não respondeu ou a sessão não tem histórico: '.$counts['error']);

            return self::FAILURE;
        }

        $this->table(['métrica', 'valor'], [
            ['modo', $counts['dry_run'] ? 'dry-run' : 'importação'],
            ['janela', $counts['days'].' dias'],
            ['chats encontrados', $counts['chats_fetched']],
            ['chats considerados', $counts['chats_considered']],
            ['mensagens', $counts['messages']],
            ['mensagens recebidas', $counts['inbound_messages']],
            ['mensagens importadas', $counts['imported']],
            ['conversas interpretadas', $counts['interpreted']],
            ['orçamentos', $counts['quotes']],
            ['agendamentos históricos', $counts['bookings']],
            ['resumos', $counts['summaries']],
            ['erros', $counts['errors']],
        ]);

        if ($counts['skipped'] !== []) {
            $this->line('Ignorados:');

            foreach ($counts['skipped'] as $reason => $total) {
                $this->line("  {$reason}: {$total}");
            }
        }

        if ($counts['dry_run']) {
            $this->warn('Dry-run: nenhuma mensagem, orçamento ou agendamento foi gravado.');
        }

        return self::SUCCESS;
    }

    private function artist(): ?Artist
    {
        $key = (string) $this->argument('artist');
        $query = Artist::with('settings')->where('wa_session_slug', $key);

        if (Str::isUuid($key)) {
            $query->orWhere('id', $key);
        }

        $artist = $query->first();

        if ($artist === null) {
            $this->error('Artista não encontrado: '.$key);

            return null;
        }

        if (! $artist->wa_session_slug) {
            $this->error('Este artista não tem sessão WhatsApp configurada.');

            return null;
        }

        return $artist;
    }
}
