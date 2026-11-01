<?php

namespace App\Console\Commands;

use App\Mail\ArtistAttention;
use App\Models\Artist;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-mail the artist when a lead is waiting for them:
 *   - a project in `aguardando_artista` (handoff);
 *   - a requested deposit that needs the PIX to be confirmed.
 *
 * Runs every minute. The notified_at columns prevent duplicate e-mails.
 */
class NotifyArtistAttention extends Command
{
    protected $signature = 'crm:notify-artist-attention';

    protected $description = 'E-mail artists about handoffs and deposits waiting for them';

    public function handle(): int
    {
        // A project that left the handoff stage can notify again if it comes
        // back later.
        DB::table('projects')
            ->where('stage_key', '!=', 'aguardando_artista')
            ->whereNotNull('handoff_notified_at')
            ->update(['handoff_notified_at' => null]);

        $handoffs = $this->pendingHandoffs();
        $deposits = $this->pendingDeposits();

        $byArtist = [];

        foreach ($handoffs as $project) {
            $byArtist[$project->artist_id]['handoffs'][] = $project;
        }

        foreach ($deposits as $payment) {
            if ($payment->project) {
                $byArtist[$payment->project->artist_id]['deposits'][] = $payment;
            }
        }

        foreach ($byArtist as $artistId => $bucket) {
            $artist = Artist::with('settings')->find($artistId);

            if (! $artist) {
                continue;
            }

            $emails = $artist->users()
                ->where('role', User::ROLE_ARTIST)
                ->pluck('email')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($emails === []) {
                $this->warn("Artist {$artist->displayName()} ({$artistId}) has no login e-mail; skipping.");
                continue;
            }

            $handoffItems = collect($bucket['handoffs'] ?? [])
                ->map(fn (Project $project) => [
                    'name' => $project->lead?->displayName() ?: 'Cliente',
                    'phone' => $project->lead?->phone_e164,
                    'reason' => $this->handoffReason($project->handoff_reason),
                    'url' => $project->lead
                        ? route('leads.show', $project->lead)
                        : route('dashboard'),
                ])
                ->values()
                ->all();

            $depositItems = collect($bucket['deposits'] ?? [])
                ->map(fn (Payment $payment) => [
                    'name' => $payment->project?->lead?->displayName() ?: 'Cliente',
                    'amount' => number_format($payment->amount_cents / 100, 2, ',', '.'),
                    'url' => $payment->project?->lead
                        ? route('leads.show', $payment->project->lead)
                        : route('dashboard'),
                ])
                ->values()
                ->all();

            try {
                Mail::to($emails)->send(new ArtistAttention($artist, $handoffItems, $depositItems));
            } catch (Throwable $e) {
                report($e);
                $this->error("Failed to e-mail {$artist->displayName()} ({$artistId}): {$e->getMessage()}");
                continue;
            }

            if ($handoffItems !== []) {
                DB::table('projects')
                    ->whereIn('id', collect($bucket['handoffs'])->pluck('id'))
                    ->update(['handoff_notified_at' => now()]);
            }

            if ($depositItems !== []) {
                DB::table('payments')
                    ->whereIn('id', collect($bucket['deposits'])->pluck('id'))
                    ->update(['deposit_notified_at' => now()]);
            }

            $this->info(sprintf(
                'Notified %s (%s): %d handoff(s), %d deposit(s).',
                $artist->displayName(),
                $artistId,
                count($handoffItems),
                count($depositItems),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Project>
     */
    private function pendingHandoffs(): Collection
    {
        $query = Project::with(['lead', 'artist.settings'])
            ->where('stage_key', 'aguardando_artista')
            ->whereNull('handoff_notified_at');

        // A lead the artist already answered no longer needs the alert. The
        // table is Postgres-only (n8n's memory/chat control).
        if (DB::connection()->getDriverName() === 'pgsql') {
            $query->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('chat_sessions')
                    ->whereColumn('chat_sessions.lead_id', 'projects.lead_id')
                    ->whereColumn('chat_sessions.artist_id', 'projects.artist_id')
                    ->where('chat_sessions.owner', 'artist');
            });
        }

        return $query->get();
    }

    /**
     * @return Collection<int, Payment>
     */
    private function pendingDeposits(): Collection
    {
        return Payment::with(['project.lead', 'project.artist.settings'])
            ->where('kind', 'deposit')
            ->where('status', 'requested')
            ->whereNull('deposit_notified_at')
            ->whereHas('project', fn ($query) => $query->whereNotIn('stage_key', ['fechado', 'perdido']))
            ->get();
    }

    private function handoffReason(?string $reason): string
    {
        return [
            'cover_up' => 'Cover-up',
            'reforma' => 'Reforma',
            'lead_requested_artist' => 'Pediu o artista',
            'below_piso' => 'Abaixo do piso',
            'vague' => 'Descrição vaga',
            'audio_2x' => 'Áudio repetido',
            'no_availability' => 'Sem horário disponível',
            'pix_missing' => 'PIX não configurado',
            'no_price_row' => 'Sem preço para o local',
        ][$reason] ?? ($reason ?: 'Atendimento solicitado');
    }
}
