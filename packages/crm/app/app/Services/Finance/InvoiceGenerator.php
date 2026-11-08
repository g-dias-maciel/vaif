<?php

namespace App\Services\Finance;

use App\Models\BillingInvoice;
use App\Models\ProspectContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates the next PIX invoice for a contract and advances its billing date.
 *
 * Idempotent by (contract_id, period_start): running twice returns the existing
 * invoice instead of duplicating it.
 */
class InvoiceGenerator
{
    public function __construct(private readonly InvoiceNumberGenerator $numbers) {}

    public function generateForContract(ProspectContract $contract, ?Carbon $asOf = null): ?BillingInvoice
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();

        if ($contract->status !== ProspectContract::STATUS_ACTIVE || $contract->isCard()) {
            return null;
        }

        $next = $contract->next_invoice_at;

        if ($next === null || $next->copy()->startOfDay()->gt($asOf)) {
            return null;
        }

        $periodStart = $next->copy()->startOfDay();
        $amount = $contract->isMembersArea()
            ? $contract->total_value_cents
            : $contract->monthly_value_cents;

        if ($amount <= 0) {
            return null;
        }

        $periodEnd = $contract->isMembersArea()
            ? $periodStart->copy()
            : $periodStart->copy()->addMonthNoOverflow()->subDay();

        $existing = BillingInvoice::query()
            ->where('contract_id', $contract->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->first();

        if ($existing !== null) {
            $this->advance($contract, $periodStart);

            return $existing;
        }

        return DB::transaction(function () use ($contract, $periodStart, $periodEnd, $amount): BillingInvoice {
            $invoice = BillingInvoice::create([
                'number' => $this->numbers->next($periodStart),
                'contract_id' => $contract->id,
                'prospect_id' => $contract->prospect_id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'issue_date' => $periodStart->toDateString(),
                'due_date' => $this->dueDate($contract, $periodStart)->toDateString(),
                'amount_cents' => $amount,
                'paid_cents' => 0,
                'status' => BillingInvoice::STATUS_SENT,
                'payment_method' => BillingInvoice::METHOD_PIX,
                'notes' => $contract->isMembersArea()
                    ? 'Plano 697 — área de membros.'
                    : null,
            ]);

            $invoice->items()->create([
                'description' => $this->description($contract, $periodStart, $periodEnd),
                'quantity' => 1,
                'unit_amount_cents' => $amount,
                'total_cents' => $amount,
                'sort_order' => 0,
            ]);

            $this->advance($contract, $periodStart);

            return $invoice;
        });
    }

    /**
     * Generate every invoice that is due, capped to avoid an accidental loop.
     *
     * @return array<int, BillingInvoice>
     */
    public function generateDue(ProspectContract $contract, ?Carbon $asOf = null, int $limit = 24): array
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $created = [];

        for ($i = 0; $i < $limit; $i++) {
            $invoice = $this->generateForContract($contract, $asOf);

            if ($invoice === null) {
                break;
            }

            $created[] = $invoice;
        }

        return $created;
    }

    private function advance(ProspectContract $contract, Carbon $periodStart): void
    {
        if ($contract->isMembersArea()) {
            $contract->next_invoice_at = null;
            $contract->invoice_status = 'pending';
            $contract->save();

            return;
        }

        $next = $periodStart->copy()->addMonthNoOverflow();

        if ($contract->end_date !== null && $next->gt($contract->end_date->copy()->startOfDay())) {
            $next = null;
        }

        $contract->next_invoice_at = $next;
        $contract->invoice_status = 'pending';
        $contract->save();
    }

    private function dueDate(ProspectContract $contract, Carbon $periodStart): Carbon
    {
        $day = $contract->billing_day ?: null;

        if ($day === null) {
            return $periodStart->copy();
        }

        $due = $periodStart->copy()->day(min($day, $periodStart->daysInMonth));

        return $due->lt($periodStart) ? $periodStart->copy() : $due;
    }

    private function description(ProspectContract $contract, Carbon $start, Carbon $end): string
    {
        if ($contract->isMembersArea()) {
            return 'Área de membros — acesso';
        }

        return sprintf(
            'Plano %s — %s a %s',
            $contract->planLabel(),
            $start->format('d/m/Y'),
            $end->format('d/m/Y'),
        );
    }
}
