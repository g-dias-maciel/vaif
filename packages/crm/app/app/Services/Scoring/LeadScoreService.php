<?php

namespace App\Services\Scoring;

use App\Models\Artist;
use App\Models\Event;
use App\Models\Lead;
use App\Models\Project;
use App\Services\Metrics\TranscriptMetrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Scores every Lead of an Artist for the remarketing queue.
 *
 * Open Projects are scored for chance to close; Leads whose last Project was
 * lost get a separate revival score. Both are heuristics with an explicit
 * reason list: the Artist must always be able to see why a Lead is at the top.
 */
class LeadScoreService
{
    private const WON_STAGES = ['agendado', 'fechado'];

    private const CLOSED_STAGES = ['perdido', 'bloqueado'];

    private const STAGE_RANK = [
        'novo' => 1,
        'qualificando' => 2,
        'orcamento_enviado' => 3,
        'aguardando_deposito' => 4,
        'aguardando_artista' => 4,
        'agendado' => 5,
        'fechado' => 6,
    ];

    private const RANK_STAGE = [
        1 => 'novo',
        2 => 'qualificando',
        3 => 'orcamento_enviado',
        4 => 'aguardando_deposito',
        5 => 'agendado',
    ];

    public function __construct(
        private readonly TranscriptMetrics $transcripts,
    ) {
    }

    /**
     * All Leads of the Artist, keyed by Lead id.
     *
     * @return Collection<string, LeadProfile>
     */
    public function profiles(Artist $artist): Collection
    {
        $leads = Lead::query()
            ->where('artist_id', $artist->id)
            ->whereNull('anonymized_at')
            ->with([
                'projects' => fn ($query) => $query
                    ->orderByDesc('updated_at')
                    ->orderByDesc('created_at'),
                'projects.quotes',
                'projects.payments',
                'projects.calendar',
            ])
            ->get();

        if ($leads->isEmpty()) {
            return collect();
        }

        $leadIds = $leads->pluck('id')->map(fn ($id): string => (string) $id)->all();

        $transcripts = $this->transcripts->available()
            ? $this->transcripts->forLeads($leadIds)
            : [];

        $returning = DB::table('returning_customers')
            ->where('artist_id', $artist->id)
            ->pluck('phone_e164')
            ->flip()
            ->all();

        $stageHistory = $this->stageHistory($artist, $leads);

        $now = Carbon::now();

        return $leads->mapWithKeys(function (Lead $lead) use ($transcripts, $returning, $stageHistory, $now): array {
            $leadId = (string) $lead->id;
            $transcript = $transcripts[$leadId] ?? [];
            $projects = $lead->projects;

            $lastProject = $projects->first();
            $lastActivity = $this->lastActivity($lead, $transcript);

            $openProjects = $projects->filter(
                fn (Project $project): bool => ! in_array((string) $project->stage_key, [...self::WON_STAGES, ...self::CLOSED_STAGES], true),
            );

            $openProject = null;
            $openScore = null;

            foreach ($openProjects as $project) {
                $score = $this->scoreProject($lead, $project, [
                    'transcript' => $transcript,
                    'returning' => isset($returning[(string) $lead->phone_e164]),
                    'last_activity' => $lastActivity,
                    'now' => $now,
                ]);

                if ($openScore === null || $score->score > $openScore->score) {
                    $openProject = $project;
                    $openScore = $score;
                }
            }

            $revivalScore = $openScore === null && $lastProject !== null && $lastProject->stage_key === 'perdido'
                ? $this->revivalScore($lead, $lastProject, [
                    'returning' => isset($returning[(string) $lead->phone_e164]),
                    'stage_history' => $stageHistory[(string) $lastProject->id] ?? [],
                    'now' => $now,
                ])
                : null;

            $closedScore = $openScore === null && $revivalScore === null
                ? $this->closedScore($lead, $lastProject)
                : null;

            return [$leadId => new LeadProfile(
                $lead,
                $openProject,
                $openScore,
                $lastProject,
                $revivalScore ?? $closedScore,
                isset($returning[(string) $lead->phone_e164]),
                $lastActivity,
            )];
        });
    }

    /**
     * The score used by the Clients list and the touch log. It is the best open
     * Project, else the revival/closed result.
     */
    public function forLead(Lead $lead): ?LeadScore
    {
        $profiles = $this->profiles($lead->artist);

        return $profiles->get((string) $lead->id)?->score();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function scoreProject(Lead $lead, Project $project, array $context): LeadScore
    {
        $stage = (string) $project->stage_key;
        $leadId = (string) $lead->id;
        $projectId = (string) $project->id;

        if (in_array($stage, self::WON_STAGES, true)) {
            return new LeadScore(
                $leadId,
                $projectId,
                100,
                LeadScore::TIER_WON,
                [['label' => 'Projeto ganho', 'points' => 100]],
                ['stage' => $stage],
                null,
            );
        }

        if (in_array($stage, self::CLOSED_STAGES, true)) {
            return new LeadScore(
                $leadId,
                $projectId,
                0,
                LeadScore::TIER_CLOSED,
                [['label' => 'Projeto encerrado', 'points' => 0]],
                ['stage' => $stage],
                null,
            );
        }

        $adjustments = (array) config('crm.lead_score.adjustments', []);
        $baseRates = (array) config('crm.lead_score.stage_base_rates', []);
        $base = (int) ($baseRates[$stage] ?? 10);

        $score = $base;
        $reasons = [['label' => 'Etapa '.$project->stage_label, 'points' => $base]];
        $signals = ['stage' => $stage, 'base' => $base];

        $add = function (int $points, string $label) use (&$score, &$reasons): void {
            if ($points === 0) {
                return;
            }

            $score += $points;
            $reasons[] = ['label' => $label, 'points' => $points];
        };

        $latestQuote = $project->quotes->sortByDesc('created_at')->first();

        if ($latestQuote !== null) {
            if ($latestQuote->status === 'accepted') {
                $add((int) ($adjustments['quote_accepted'] ?? 18), 'Orçamento aceito');
            } elseif ($latestQuote->status === 'open' && $latestQuote->isOpen()) {
                $add((int) ($adjustments['quote_open'] ?? 4), 'Orçamento em aberto');
            } elseif ($latestQuote->status === 'open') {
                $add((int) ($adjustments['quote_expired'] ?? -8), 'Orçamento vencido');
            } elseif (in_array($latestQuote->status, ['declined', 'lapsed', 'void'], true)) {
                $add((int) ($adjustments['quote_declined'] ?? -10), 'Orçamento recusado ou expirado');
            }

            $signals['quote_status'] = $latestQuote->status;
            $signals['quote_cents'] = $latestQuote->offered_price_cents;

            $lastInbound = $context['transcript']['last_inbound'] ?? null;
            if ($lastInbound !== null && Carbon::parse($lastInbound)->greaterThan($latestQuote->created_at)) {
                $add((int) ($adjustments['replied_after_quote'] ?? 10), 'Respondeu depois do orçamento');
                $signals['replied_after_quote'] = true;
            }
        }

        $deposit = $project->deposit;
        if ($deposit !== null) {
            if ($deposit->status === 'confirmed') {
                $add((int) ($adjustments['deposit_confirmed'] ?? 20), 'Sinal confirmado');
            } elseif ($deposit->status === 'requested') {
                $add((int) ($adjustments['deposit_requested'] ?? 8), 'Sinal solicitado');
            }

            $signals['deposit_status'] = $deposit->status;
        }

        if ($project->booking !== null) {
            $add((int) ($adjustments['booking_active'] ?? 25), 'Horário agendado');
            $signals['has_booking'] = true;
        }

        $lastActivity = $context['last_activity'];
        if ($lastActivity !== null) {
            $days = max(0, (int) $lastActivity->diffInDays($context['now']));
            $signals['days_since_activity'] = $days;

            if ($days <= 2) {
                $add((int) ($adjustments['recent_2d'] ?? 8), 'Contato nos últimos 2 dias');
            } elseif ($days <= 7) {
                $add((int) ($adjustments['recent_7d'] ?? 5), 'Contato nos últimos 7 dias');
            } elseif ($days > 60) {
                $add((int) ($adjustments['stale_60d'] ?? -12), 'Sem contato há mais de 60 dias');
            } elseif ($days > 30) {
                $add((int) ($adjustments['stale_30d'] ?? -6), 'Sem contato há mais de 30 dias');
            }
        }

        $lastInbound = $context['transcript']['last_inbound'] ?? null;
        $lastOutbound = $context['transcript']['last_outbound'] ?? null;

        if ($lastInbound !== null && $lastOutbound !== null) {
            $inboundAt = Carbon::parse($lastInbound);
            $outboundAt = Carbon::parse($lastOutbound);

            if ($outboundAt->greaterThan($inboundAt)
                && (int) $outboundAt->diffInDays($context['now']) >= 7) {
                $add((int) ($adjustments['ghosted'] ?? -12), 'Última mensagem foi do estúdio');
                $signals['ghosted'] = true;
            }
        }

        $brief = $this->briefCompleteness($project);
        $signals['brief_fields'] = $brief;

        if ($brief >= 6) {
            $add((int) ($adjustments['brief_full'] ?? 8), 'Brief completo');
        } elseif ($brief >= 4) {
            $add((int) ($adjustments['brief_partial'] ?? 4), 'Brief parcial');
        }

        if ($context['returning'] === true) {
            $add((int) ($adjustments['returning'] ?? 10), 'Cliente recorrente');
            $signals['returning'] = true;
        }

        if ($lead->agent_blocked_at !== null) {
            $add((int) ($adjustments['agent_blocked'] ?? -8), 'Agente silenciado');
            $signals['agent_blocked'] = true;
        }

        if ($lead->archived_at !== null) {
            $add((int) ($adjustments['archived'] ?? -20), 'Lead arquivado');
            $signals['archived'] = true;
        }

        $score = max(0, min(100, $score));

        return new LeadScore(
            $leadId,
            $projectId,
            $score,
            LeadScore::tierForScore($score),
            $reasons,
            $signals,
            $this->nextAction($stage, $latestQuote, $deposit, $lastActivity, $context['now']),
        );
    }

    /**
     * The revival score for a Lead whose last Project was lost. It rewards how
     * far the deal got and how recently it died; a "novo" that never replied is
     * not worth reviving.
     *
     * @param  array<string, mixed>  $context
     */
    private function revivalScore(Lead $lead, Project $project, array $context): ?LeadScore
    {
        $config = (array) config('crm.lead_score.revival', []);
        $stagePoints = (array) ($config['stage_points'] ?? []);
        $stageHistory = (array) ($context['stage_history'] ?? []);

        $maxRank = 0;
        foreach (array_keys($stageHistory) as $stage) {
            $maxRank = max($maxRank, self::STAGE_RANK[$stage] ?? 0);
        }

        $hasQuote = $project->quotes->isNotEmpty();
        $hasDeposit = $project->payments->where('kind', 'deposit')->isNotEmpty();
        $hasBooking = $project->calendar->where('kind', 'booking')->isNotEmpty();

        if ($maxRank < 3 && $hasQuote) {
            $maxRank = max($maxRank, 3);
        }
        if ($maxRank < 4 && $hasDeposit) {
            $maxRank = max($maxRank, 4);
        }
        if ($maxRank < 5 && $hasBooking) {
            $maxRank = max($maxRank, 5);
        }

        // A lost "novo" with no quote, deposit or booking is noise.
        if ($maxRank < 2) {
            return null;
        }

        $maxStage = self::RANK_STAGE[$maxRank] ?? 'novo';
        $score = (int) ($stagePoints[$maxStage] ?? 5);
        $reasons = [['label' => 'Chegou até '.$this->stageLabel($maxStage), 'points' => $score]];

        $add = function (int $points, string $label) use (&$score, &$reasons): void {
            if ($points === 0) {
                return;
            }

            $score += $points;
            $reasons[] = ['label' => $label, 'points' => $points];
        };

        if ($hasQuote) {
            $add((int) ($config['quote'] ?? 10), 'Já recebeu orçamento');
        }
        if ($hasDeposit) {
            $add((int) ($config['deposit'] ?? 15), 'Já pediu sinal');
        }
        if ($hasBooking) {
            $add((int) ($config['booking'] ?? 20), 'Já teve horário reservado');
        }
        if ($context['returning'] === true) {
            $add((int) ($config['returning'] ?? 10), 'Cliente recorrente');
        }

        $brief = $this->briefCompleteness($project);
        if ($brief >= 4) {
            $add((int) ($config['brief'] ?? 5), 'Brief preservado');
        }

        $lostAt = $project->closed_at ?? $project->updated_at;
        $days = $lostAt !== null ? max(0, (int) $lostAt->diffInDays($context['now'])) : null;
        $recency = (array) ($config['recency'] ?? []);

        if ($days !== null) {
            if ($days < 14) {
                $add((int) ($recency['under_14'] ?? 5), 'Perdido há menos de 2 semanas');
            } elseif ($days <= 90) {
                $add((int) ($recency['14_90'] ?? 15), 'Perdido entre 2 semanas e 3 meses');
            } elseif ($days <= 180) {
                $add((int) ($recency['91_180'] ?? 8), 'Perdido entre 3 e 6 meses');
            }
        }

        if ($lead->agent_blocked_at !== null) {
            $add((int) ($config['agent_blocked'] ?? -10), 'Agente silenciado');
        }

        $score = max(0, min(100, $score));

        return new LeadScore(
            (string) $lead->id,
            (string) $project->id,
            $score,
            LeadScore::tierForScore($score),
            $reasons,
            [
                'revival' => true,
                'max_stage' => $maxStage,
                'days_since_lost' => $days,
                'has_quote' => $hasQuote,
                'has_deposit' => $hasDeposit,
                'has_booking' => $hasBooking,
            ],
            'Reabrir a conversa',
        );
    }

    private function closedScore(Lead $lead, ?Project $project): ?LeadScore
    {
        if ($project === null) {
            return null;
        }

        $won = in_array((string) $project->stage_key, self::WON_STAGES, true);

        return new LeadScore(
            (string) $lead->id,
            (string) $project->id,
            $won ? 100 : 0,
            $won ? LeadScore::TIER_WON : LeadScore::TIER_CLOSED,
            [['label' => $won ? 'Projeto ganho' : 'Projeto encerrado', 'points' => $won ? 100 : 0]],
            ['stage' => $project->stage_key],
            null,
        );
    }

    /**
     * The last message in either direction. `first_seen_at` keeps a brand-new
     * Lead from looking infinitely stale; Project edits are deliberately not
     * activity, otherwise a back-office stage change would reset the clock.
     *
     * @param  array<string, mixed>  $transcript
     */
    private function lastActivity(Lead $lead, array $transcript): ?Carbon
    {
        $candidates = array_filter([
            $lead->last_message_at,
            $lead->first_seen_at,
            $transcript['last_inbound'] ?? null,
            $transcript['last_outbound'] ?? null,
        ]);

        // A Lead created directly in the CRM may have no first_seen_at; only
        // then does the row's own creation time stand in for activity.
        if ($candidates === []) {
            $candidates = array_filter([$lead->created_at]);
        }

        if ($candidates === []) {
            return null;
        }

        $candidates = array_map(
            fn ($value): Carbon => $value instanceof Carbon ? $value : Carbon::parse((string) $value),
            $candidates,
        );

        usort($candidates, fn ($a, $b): int => $b->getTimestamp() <=> $a->getTimestamp());

        return $candidates[0];
    }

    /**
     * @param  Collection<int, Lead>  $leads
     * @return array<string, array<string, true>>
     */
    private function stageHistory(Artist $artist, Collection $leads): array
    {
        $projectIds = $leads
            ->flatMap(fn (Lead $lead) => $lead->projects->pluck('id'))
            ->unique()
            ->values()
            ->all();

        if ($projectIds === []) {
            return [];
        }

        $history = [];

        $events = Event::query()
            ->where('artist_id', $artist->id)
            ->whereIn('project_id', $projectIds)
            ->where('type', 'stage_changed')
            ->get(['project_id', 'payload']);

        foreach ($events as $event) {
            $stage = is_array($event->payload) ? ($event->payload['stage'] ?? null) : null;

            if (is_string($stage) && $stage !== '') {
                $history[(string) $event->project_id][$stage] = true;
            }
        }

        return $history;
    }

    private function briefCompleteness(Project $project): int
    {
        $fields = 0;

        foreach (['placement', 'body_zone', 'style', 'tattoo_type', 'meaning'] as $field) {
            if (filled($project->{$field})) {
                $fields++;
            }
        }

        if ($project->first_tattoo !== null) {
            $fields++;
        }

        if (! empty($project->reference_pics)) {
            $fields++;
        }

        return $fields;
    }

    private function nextAction(
        string $stage,
        ?\App\Models\Quote $quote,
        ?\App\Models\Payment $deposit,
        ?Carbon $lastActivity,
        Carbon $now,
    ): ?string {
        if ($stage === 'aguardando_artista') {
            return 'Responder ao cliente';
        }

        if ($deposit !== null && $deposit->status === 'requested') {
            return 'Confirmar o sinal';
        }

        if ($quote !== null && $quote->status === 'open' && ! $quote->isOpen()) {
            return 'Renovar o orçamento';
        }

        if ($lastActivity !== null && (int) $lastActivity->diffInDays($now) >= 7) {
            return 'Retomar a conversa';
        }

        return 'Continuar a conversa';
    }

    private function stageLabel(string $stage): string
    {
        return (string) (config('crm.pipeline_statuses')[$stage] ?? ucfirst($stage));
    }
}
