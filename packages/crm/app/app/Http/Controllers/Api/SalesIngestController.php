<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\IngestProspectRequest;
use App\Models\Campaign;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Calculator/diagnostic form → internal sales pipeline.
 *
 * New prospects enter as `active` / `novo`; a repeat submission updates only
 * the calculator fields and appends an activity, preserving the sales state
 * (status, stage, owner, notes, next action).
 */
class SalesIngestController extends Controller
{
    public function __invoke(IngestProspectRequest $request): JsonResponse
    {
        $data = $request->validated();
        $phone = $data['whatsapp_e164'];

        [$revenue, $revenueFlags] = $this->revenueFor($data);
        [$ticket, $ticketFlags] = $this->ticketFor($data);
        [$sessions, $sessionFlags] = $this->sessionsFor($data);
        $hours = $this->toFloat($data['horas_admin'] ?? null);
        $loss = $this->toCents($data['prejuizo_mensal'] ?? null);
        $potential = $this->toCents($data['potencial_lucro'] ?? null);

        $flags = array_values(array_unique(array_merge(
            $this->flagsFor($data),
            $revenueFlags,
            $ticketFlags,
            $sessionFlags,
        )));

        $campaign = ! empty($data['utm_campaign'])
            ? Campaign::where('utm_campaign', $data['utm_campaign'])->first()
            : null;

        return DB::transaction(function () use (
            $data,
            $phone,
            $revenue,
            $ticket,
            $sessions,
            $hours,
            $loss,
            $potential,
            $flags,
            $campaign,
        ): JsonResponse {
            $prospect = Prospect::where('whatsapp_e164', $phone)->first();

            if ($prospect) {
                $this->updateExisting($prospect, $data, $revenue, $ticket, $sessions, $hours, $loss, $potential, $flags, $campaign);
                $this->log($prospect, true, $data);

                return response()->json([
                    'ok' => true,
                    'prospect_id' => $prospect->id,
                    'duplicate' => true,
                ], 200);
            }

            $prospect = new Prospect;
            $prospect->fill([
                'name' => $data['nome'],
                'whatsapp_e164' => $phone,
                'whatsapp_raw' => $data['whatsapp'],
                'instagram' => $data['instagram'] ?? null,
                'email' => $data['email'] ?? null,
                'studio' => $data['studio'] ?? null,
                'source' => $data['origem'] ?? 'calculadora',
                'status' => Prospect::STATUS_ACTIVE,
                'stage_key' => 'novo',
                'revenue_monthly_cents' => $this->toCents($revenue),
                'ticket_cents' => $this->toCents($ticket),
                'sessions_monthly' => $sessions,
                'admin_hours_day' => $hours,
                'hidden_loss_cents' => $loss,
                'potential_revenue_cents' => $potential,
                'campaign_id' => $campaign?->id,
                'utm_source' => $data['utm_source'] ?? null,
                'utm_medium' => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
                'utm_content' => $data['utm_content'] ?? null,
                'utm_term' => $data['utm_term'] ?? null,
                'next_action_at' => now()->addDay(),
                'import_flags' => $flags,
                'raw_payload' => $data,
            ]);
            $prospect->save();

            $this->log($prospect, false, $data);

            return response()->json([
                'ok' => true,
                'prospect_id' => $prospect->id,
                'duplicate' => false,
            ], 201);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateExisting(
        Prospect $prospect,
        array $data,
        ?float $revenue,
        ?float $ticket,
        ?int $sessions,
        ?float $hours,
        ?int $loss,
        ?int $potential,
        array $flags,
        ?Campaign $campaign,
    ): void {
        $prospect->name = $data['nome'];
        $prospect->whatsapp_raw = $data['whatsapp'];

        foreach ([
            'instagram',
            'email',
            'studio',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
        ] as $field) {
            if (! empty($data[$field])) {
                $prospect->{$field} = $data[$field];
            }
        }

        if ($revenue !== null) {
            $prospect->revenue_monthly_cents = $this->toCents($revenue);
        }

        if ($ticket !== null) {
            $prospect->ticket_cents = $this->toCents($ticket);
        }

        if ($sessions !== null) {
            $prospect->sessions_monthly = $sessions;
        }

        if ($hours !== null) {
            $prospect->admin_hours_day = $hours;
        }

        if ($loss !== null) {
            $prospect->hidden_loss_cents = $loss;
        }

        if ($potential !== null) {
            $prospect->potential_revenue_cents = $potential;
        }

        if ($campaign !== null && $prospect->campaign_id === null) {
            $prospect->campaign_id = $campaign->id;
        }

        $prospect->import_flags = array_values(array_unique(array_merge(
            $prospect->import_flags ?? [],
            $flags,
        )));

        $prospect->raw_payload = $data;
        $prospect->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function log(Prospect $prospect, bool $duplicate, array $data): void
    {
        $prospect->activities()->create([
            'type' => ProspectActivity::TYPE_IMPORT,
            'title' => $duplicate ? 'Nova submissão da calculadora' : 'Lead recebido da calculadora',
            'metadata' => [
                'duplicate' => $duplicate,
                'utm' => array_filter([
                    'source' => $data['utm_source'] ?? null,
                    'medium' => $data['utm_medium'] ?? null,
                    'campaign' => $data['utm_campaign'] ?? null,
                    'content' => $data['utm_content'] ?? null,
                    'term' => $data['utm_term'] ?? null,
                ]),
            ],
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?float, 1: array<int, string>}
     */
    private function revenueFor(array $data): array
    {
        $revenue = $this->toFloat($data['faturamento'] ?? null);
        $ticket = $this->toFloat($data['ticket'] ?? null);
        $flags = [];

        // Safety net for the older form bug that stored "8.000" as 8.00.
        if ($revenue !== null && $revenue > 0 && $revenue < 100 && $ticket !== null && $ticket >= 100) {
            $revenue *= 1000;
            $flags[] = 'receita_corrigida';
        }

        if ($revenue !== null && $revenue <= 0) {
            $revenue = null;
        }

        return [$revenue, $flags];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?float, 1: array<int, string>}
     */
    private function ticketFor(array $data): array
    {
        $ticket = $this->toFloat($data['ticket'] ?? null);
        $flags = [];

        if ($ticket !== null && ($ticket <= 0 || $ticket > 10_000)) {
            $flags[] = 'ticket_outlier';
            $ticket = null;
        }

        return [$ticket, $flags];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?int, 1: array<int, string>}
     */
    private function sessionsFor(array $data): array
    {
        $sessions = $this->toInt($data['sessoes'] ?? null);
        $flags = [];

        if ($sessions !== null && ($sessions <= 0 || $sessions > 60)) {
            $flags[] = 'sessoes_outlier';
            $sessions = null;
        }

        return [$sessions, $flags];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function flagsFor(array $data): array
    {
        $flags = [];
        $revenue = $this->toFloat($data['faturamento'] ?? null);
        $hours = $this->toFloat($data['horas_admin'] ?? null);
        $loss = $this->toFloat($data['prejuizo_mensal'] ?? null);

        if ($revenue !== null && $revenue > 0 && $revenue < 100) {
            $flags[] = 'receita_suspeita_baixa';
        }

        if ($hours !== null && $hours > 16) {
            $flags[] = 'horas_admin_outlier';
        }

        if ($loss !== null && $loss < 0) {
            $flags[] = 'prejuizo_negativo';
        }

        return $flags;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $clean = preg_replace('/[^0-9,.\-]/', '', (string) $value) ?? '';

        if (str_contains($clean, ',')) {
            $clean = str_replace(['.', ','], ['', '.'], $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function toInt(mixed $value): ?int
    {
        $float = $this->toFloat($value);

        return $float === null ? null : (int) round($float);
    }

    private function toCents(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        return (int) round($this->toFloat($value) * 100);
    }
}
