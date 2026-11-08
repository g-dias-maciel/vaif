<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-mail the admin when the agent dropped an inbound message.
 *
 * A suspended artist used to swallow messages: `resolve_artist()` returned
 * nothing, the workflow took the "artist not found" branch and the message left
 * no trace. The workflow now writes every intentional drop to
 * `agent_ingest_drops`; this command makes it visible outside the database.
 *
 * Runs every five minutes. `notified_at` dedupes.
 */
class NotifyIngestDrops extends Command
{
    protected $signature = 'crm:notify-ingest-drops';

    protected $description = 'E-mail the admin about inbound messages the agent did not answer';

    public function handle(): int
    {
        $drops = DB::table('agent_ingest_drops as d')
            ->leftJoin('artists as a', 'a.id', '=', 'd.artist_id')
            ->whereNull('d.notified_at')
            ->orderByDesc('d.created_at')
            ->limit(200)
            ->get([
                'd.id',
                'd.artist_id',
                'a.name as artist_name',
                'd.session_slug',
                'd.phone_e164',
                'd.chat_id',
                'd.reason',
                'd.created_at',
            ]);

        if ($drops->isEmpty()) {
            return self::SUCCESS;
        }

        $to = (string) config('crm.admin_email');

        if ($to === '') {
            $this->warn('No admin e-mail configured (crm.admin_email / VAIF_ADMIN_EMAIL); drops stay in the dashboard.');

            return self::SUCCESS;
        }

        $lines = $drops->map(function ($drop) {
            $when = $drop->created_at;
            $phone = $drop->phone_e164 ?: $drop->chat_id ?: 'número desconhecido';

            return sprintf(
                '- %s | %s | %s | %s | %s',
                $when,
                $drop->artist_name ?: 'Artista desconhecido',
                $phone,
                $drop->reason,
                $drop->session_slug ?: '-'
            );
        })->implode("\n");

        $count = $drops->count();

        try {
            Mail::raw(
                "O agente não respondeu {$count} mensagem(ns) e registrou o motivo abaixo.\n\n"
                ."Nada foi respondido automaticamente. Verifique o artista e o número.\n\n"
                .$lines."\n",
                function ($message) use ($to, $count) {
                    $message->to($to)
                        ->subject("[VAIF] {$count} mensagem(ns) sem resposta automática");
                }
            );
        } catch (Throwable $e) {
            report($e);
            $this->error('Failed to send the ingest-drop digest: '.$e->getMessage());

            return self::FAILURE;
        }

        DB::table('agent_ingest_drops')
            ->whereIn('id', $drops->pluck('id'))
            ->update(['notified_at' => now()->utc()]);

        $this->info("Notified {$to} about {$count} dropped message(s).");

        return self::SUCCESS;
    }
}
