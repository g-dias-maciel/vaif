<?php

namespace App\Services\Sales;

use App\Models\Artist;
use App\Models\OnboardingTask;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\ProspectContract;
use App\Models\User;
use App\Services\ArtistInviteService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Converts a Prospect into a client: Artist, CRM login, contract and the
 * D0–D7 onboarding checklist — or, for the 697, a contract plus the three
 * members-area tasks (no Artist record).
 */
class ProspectHandoffService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{contract: ProspectContract, artist: ?Artist, invite_sent: bool}
     */
    public function handoff(Prospect $prospect, array $data, User $actor): array
    {
        if ($prospect->artist_id !== null || $prospect->handoff_at !== null) {
            throw ValidationException::withMessages([
                'handoff' => 'Este prospect já foi convertido em cliente.',
            ]);
        }

        return DB::transaction(function () use ($prospect, $data, $actor): array {
            $plan = (string) $data['plan'];
            $start = Carbon::parse($data['start_date'])->startOfDay();
            $billingDay = isset($data['billing_day']) ? (int) $data['billing_day'] : 10;

            if ($plan === ProspectContract::PLAN_MEMBERS_AREA) {
                return $this->handoffMembersArea($prospect, $actor, $start);
            }

            return $this->handoffRetainer($prospect, $actor, $data, $plan, $start, $billingDay);
        });
    }

    /**
     * @return array{contract: ProspectContract, artist: ?Artist, invite_sent: bool}
     */
    private function handoffMembersArea(Prospect $prospect, User $actor, Carbon $start): array
    {
        $contract = $prospect->contracts()->create([
            'artist_id' => null,
            'plan' => ProspectContract::PLAN_MEMBERS_AREA,
            'monthly_value_cents' => 0,
            'total_value_cents' => 69_700,
            'start_date' => $start->toDateString(),
            'end_date' => null,
            'status' => ProspectContract::STATUS_ACTIVE,
            'signed_at' => now(),
            'billing_day' => null,
            'next_invoice_at' => null,
        ]);

        $tasks = [
            ['grant_access', 'Liberar acesso à área de membros', 0],
            ['call_1', 'Call 1 — diagnóstico e plano', 7],
            ['call_2', 'Call 2 — revisão e próximo passo', 30],
        ];

        foreach ($tasks as [$key, $title, $days]) {
            $prospect->activities()->create([
                'user_id' => $actor->id,
                'type' => ProspectActivity::TYPE_TASK,
                'title' => $title,
                'due_at' => $start->copy()->addDays($days),
                'metadata' => ['key' => $key, 'plan' => ProspectContract::PLAN_MEMBERS_AREA],
            ]);
        }

        $prospect->update([
            'stage_key' => 'ganho',
            'artist_id' => null,
            'handoff_at' => now(),
            'lost_reason' => null,
            'next_action_at' => null,
        ]);

        $this->log($prospect, $actor, null, $contract, ProspectContract::PLAN_MEMBERS_AREA, true);

        return ['contract' => $contract, 'artist' => null, 'invite_sent' => true];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{contract: ProspectContract, artist: ?Artist, invite_sent: bool}
     */
    private function handoffRetainer(
        Prospect $prospect,
        User $actor,
        array $data,
        string $plan,
        Carbon $start,
        int $billingDay,
    ): array {
        $monthlyCents = $this->toCents($data['monthly_value'] ?? null);

        if ($monthlyCents === null || $monthlyCents <= 0) {
            throw ValidationException::withMessages([
                'monthly_value' => 'Informe o valor mensal.',
            ]);
        }

        $months = match ($plan) {
            ProspectContract::PLAN_SEMESTRAL => 6,
            ProspectContract::PLAN_TWELVE_MONTHS => 12,
            ProspectContract::PLAN_FULL_SERVICE => 6,
            default => 1,
        };

        $artist = Artist::create([
            'name' => $prospect->name,
            'status' => 'onboarding',
            'timezone' => $data['timezone'] ?? 'America/Sao_Paulo',
            'wa_session_slug' => $this->uniqueSlug($prospect->name),
        ]);

        $artist->settings()->create([
            'display_name' => $prospect->name,
            'whatsapp_number' => preg_replace('/\D+/', '', (string) ($data['whatsapp_number'] ?? $prospect->whatsapp_e164)) ?: null,
            'instagram_handle' => ltrim((string) ($data['instagram_handle'] ?? $prospect->instagram), '@') ?: null,
            'deposit_type' => 'percent',
            'deposit_value' => 30,
            'working_hours' => $this->defaultWorkingHours(),
        ]);

        try {
            $user = User::create([
                'name' => $prospect->name,
                'email' => mb_strtolower(trim((string) $data['artist_email'])),
                'password' => Str::random(64),
                'role' => User::ROLE_ARTIST,
                'artist_id' => $artist->id,
            ]);
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'artist_email' => 'Já existe um login com esse e-mail.',
            ]);
        }

        $inviteSent = app(ArtistInviteService::class)->send($user);

        $contract = $prospect->contracts()->create([
            'artist_id' => $artist->id,
            'plan' => $plan,
            'monthly_value_cents' => $monthlyCents,
            'total_value_cents' => $monthlyCents * $months,
            'start_date' => $start->toDateString(),
            'end_date' => $months > 1 ? $start->copy()->addMonthsNoOverflow($months)->toDateString() : null,
            'status' => ProspectContract::STATUS_ACTIVE,
            'signed_at' => now(),
            'billing_day' => $billingDay,
            'next_invoice_at' => $start->copy()->addMonthNoOverflow()->toDateString(),
        ]);

        $tasks = [
            [OnboardingTask::KEY_WELCOME, 'Boas-vindas e acessos', 0],
            [OnboardingTask::KEY_BRIEFING, 'Briefing preenchido', 1],
            [OnboardingTask::KEY_STRATEGY_CALL, 'Call de estratégia', 2],
            [OnboardingTask::KEY_CREATIVES, 'Gravação de criativos', 3],
            [OnboardingTask::KEY_SETUP, 'Setup de anúncios, SDR e CRM', 4],
            [OnboardingTask::KEY_GO_LIVE, 'Go-live', 7],
            [OnboardingTask::KEY_MILESTONE_60, 'Revisão de marco (60 dias)', 60],
        ];

        foreach ($tasks as [$key, $title, $days]) {
            OnboardingTask::create([
                'artist_id' => $artist->id,
                'key' => $key,
                'title' => $title,
                'due_at' => $start->copy()->addDays($days),
                'owner_id' => $actor->id,
            ]);
        }

        $prospect->update([
            'stage_key' => 'ganho',
            'artist_id' => $artist->id,
            'handoff_at' => now(),
            'lost_reason' => null,
            'next_action_at' => null,
        ]);

        $this->log($prospect, $actor, $artist, $contract, $plan, $inviteSent);

        return ['contract' => $contract, 'artist' => $artist, 'invite_sent' => $inviteSent];
    }

    private function log(
        Prospect $prospect,
        User $actor,
        ?Artist $artist,
        ProspectContract $contract,
        string $plan,
        bool $inviteSent,
    ): void {
        $prospect->activities()->create([
            'user_id' => $actor->id,
            'type' => ProspectActivity::TYPE_HANDOFF,
            'title' => 'Handoff concluído',
            'body' => $artist ? 'Cliente criado: '.$artist->name : 'Área de membros',
            'metadata' => [
                'artist_id' => $artist?->id,
                'contract_id' => $contract->id,
                'plan' => $plan,
                'invite_sent' => $inviteSent,
            ],
            'completed_at' => now(),
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'artist';
        $slug = $base;
        $i = 2;

        while (Artist::where('wa_session_slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function toCents(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function defaultWorkingHours(): array
    {
        return [
            'seg' => ['09:00-12:00', '14:00-18:00'],
            'ter' => ['09:00-12:00', '14:00-18:00'],
            'qua' => ['09:00-12:00', '14:00-18:00'],
            'qui' => ['09:00-12:00', '14:00-18:00'],
            'sex' => ['09:00-12:00', '14:00-18:00'],
            'sab' => ['09:00-13:00'],
        ];
    }
}
