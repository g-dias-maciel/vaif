<?php

namespace App\Console\Commands;

use App\Mail\ArtistAttention;
use App\Models\Artist;
use App\Models\Lead;
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
 *
 * Handoffs now have an SLA:
 *   - first e-mail immediately;
 *   - reminder after 45 minutes;
 *   - escalation after 4 hours.
 *
 * All timestamps are written in UTC. The app timezone is America/Sao_Paulo,
 * and writing `now()` into a timestamptz column made `handoff_notified_at`
 * three hours earlier than `handoff_at`.
 */
class NotifyArtistAttention extends Command
{
    protected $signature = 'crm:notify-artist-attention';

    protected $description = 'E-mail artists about handoffs (with SLA reminders) and deposits waiting for them';

    private const REMINDER_MINUTES = 45;

    private const ESCALATION_MINUTES = 240;

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
        $contacts = $this->pendingContactGates();

        $byArtist = [];

        foreach ($handoffs as $project) {
            $byArtist[$project->artist_id]['handoffs'][] = $project;
        }

        foreach ($deposits as $payment) {
            if ($payment->project) {
                $byArtist[$payment->project->artist_id]['deposits'][] = $payment;
            }
        }

        foreach ($contacts as $lead) {
            $byArtist[$lead->artist_id]['contacts'][] = $lead;
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

            $stage = $this->handoffStage($bucket['handoffs'] ?? []);

            $handoffItems = collect($bucket['handoffs'] ?? [])
                ->map(fn (Project $project) => [
                    'name' => $project->lead?->displayName() ?: 'Cliente',
                    'phone' => $project->lead?->phone_e164,
                    'reason' => $this->handoffReason($project->handoff_reason),
                    'url' => $project->lead
                        ? route('leads.show', $project->lead)
                        : route('dashboard'),
                    'age' => $this->handoffAgeLabel($project),
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

            $contactItems = collect($bucket['contacts'] ?? [])
                ->map(fn (Lead $lead) => [
                    'name' => $lead->displayName() ?: 'Contato',
                    'phone' => $lead->phone_e164,
                    'url' => route('leads.show', $lead),
                ])
                ->values()
                ->all();

            try {
                Mail::to($emails)->send(new ArtistAttention(
                    $artist,
                    $handoffItems,
                    $depositItems,
                    $contactItems,
                    $stage,
                ));
            } catch (Throwable $e) {
                report($e);
                $this->error("Failed to e-mail {$artist->displayName()} ({$artistId}): {$e->getMessage()}");
                continue;
            }

            $now = now()->utc();

            foreach ($bucket['handoffs'] ?? [] as $project) {
                $updates = [];

                if ($project->handoff_notified_at === null) {
                    $updates['handoff_notified_at'] = $now;
                }

                if ($project->handoff_reminded_at === null
                    && $project->handoff_at !== null
                    && $project->handoff_at->lte($now->copy()->subMinutes(self::REMINDER_MINUTES))) {
                    $updates['handoff_reminded_at'] = $now;
                }

                if ($project->handoff_escalated_at === null
                    && $project->handoff_at !== null
                    && $project->handoff_at->lte($now->copy()->subMinutes(self::ESCALATION_MINUTES))) {
                    $updates['handoff_escalated_at'] = $now;
                }

                if ($updates !== []) {
                    DB::table('projects')->where('id', $project->id)->update($updates);
                }
            }

            if ($depositItems !== []) {
                DB::table('payments')
                    ->whereIn('id', collect($bucket['deposits'])->pluck('id'))
                    ->update(['deposit_notified_at' => $now]);
            }

            if ($contactItems !== []) {
                DB::table('leads')
                    ->whereIn('id', collect($bucket['contacts'])->pluck('id'))
                    ->update(['contact_gate_notified_at' => $now]);
            }

            $this->info(sprintf(
                'Notified %s (%s) [%s]: %d handoff(s), %d deposit(s), %d saved contact(s).',
                $artist->displayName(),
                $artistId,
                $stage,
                count($handoffItems),
                count($depositItems),
                count($contactItems),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Open handoffs that are due for a first notice, a reminder or an
     * escalation. Due-ness is decided in SQL against `handoff_at` so a command
     * missed during a deploy still catches up on the next run.
     *
     * @return Collection<int, Project>
     */
    private function pendingHandoffs(): Collection
    {
        $reminderBefore = now()->utc()->subMinutes(self::REMINDER_MINUTES);
        $escalationBefore = now()->utc()->subMinutes(self::ESCALATION_MINUTES);

        return Project::with(['lead', 'artist.settings'])
            ->where('stage_key', 'aguardando_artista')
            ->whereNull('handoff_resolved_at')
            ->where(function ($query) use ($reminderBefore, $escalationBefore) {
                $query->whereNull('handoff_notified_at')
                    ->orWhere(function ($q) use ($reminderBefore) {
                        $q->whereNull('handoff_reminded_at')
                            ->whereNotNull('handoff_at')
                            ->where('handoff_at', '<=', $reminderBefore);
                    })
                    ->orWhere(function ($q) use ($escalationBefore) {
                        $q->whereNull('handoff_escalated_at')
                            ->whereNotNull('handoff_at')
                            ->where('handoff_at', '<=', $escalationBefore);
                    });
            })
            ->get();
    }

    /**
     * Most urgent stage among the handoffs in this e-mail.
     *
     * @param  array<int, Project>  $handoffs
     */
    private function handoffStage(array $handoffs): string
    {
        $stage = 'first';
        $now = now()->utc();

        foreach ($handoffs as $project) {
            if ($project->handoff_escalated_at === null
                && $project->handoff_at !== null
                && $project->handoff_at->lte($now->copy()->subMinutes(self::ESCALATION_MINUTES))) {
                return 'escalation';
            }

            if ($project->handoff_reminded_at === null
                && $project->handoff_at !== null
                && $project->handoff_at->lte($now->copy()->subMinutes(self::REMINDER_MINUTES))) {
                $stage = 'reminder';
            }
        }

        return $stage;
    }

    private function handoffAgeLabel(Project $project): string
    {
        if ($project->handoff_at === null) {
            return '';
        }

        $minutes = (int) $project->handoff_at->diffInMinutes(now()->utc());

        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? $hours.' h' : $hours.' h '.$rest.' min';
    }

    /**
     * Saved contacts the agent is holding for a decision.
     *
     * @return Collection<int, Lead>
     */
    private function pendingContactGates(): Collection
    {
        return Lead::query()
            ->where('contact_gate', 'pending')
            ->whereNull('contact_gate_notified_at')
            ->get();
    }

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
            'gate_stuck' => 'Preço não liberado',
            'ambiguous_reference' => 'Referência ambígua',
            'client_service' => 'Cliente antigo / pós-tatuagem',
        ][$reason] ?? ($reason ?: 'Atendimento solicitado');
    }
}
