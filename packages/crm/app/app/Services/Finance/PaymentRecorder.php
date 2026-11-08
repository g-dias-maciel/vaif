<?php

namespace App\Services\Finance;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\ProspectContract;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records money received. An invoice payment recalculates the invoice; a card
 * charge (invoice_id = null) is recorded against the contract and advances the
 * next card charge date.
 */
class PaymentRecorder
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function record(array $data, ?User $actor = null): BillingPayment
    {
        return DB::transaction(function () use ($data, $actor): BillingPayment {
            $invoice = isset($data['invoice_id'])
                ? BillingInvoice::find($data['invoice_id'])
                : null;

            if (isset($data['invoice_id']) && $invoice === null) {
                throw ValidationException::withMessages(['invoice_id' => 'Cobrança não encontrada.']);
            }

            if ($invoice?->isCancelled()) {
                throw ValidationException::withMessages(['invoice_id' => 'Cobrança cancelada não recebe pagamento.']);
            }

            $contract = $this->contractFor($data, $invoice);
            $prospectId = $data['prospect_id'] ?? $invoice?->prospect_id ?? $contract?->prospect_id;

            if ($prospectId === null) {
                throw ValidationException::withMessages(['prospect_id' => 'Informe o cliente do pagamento.']);
            }

            $amount = $this->amountFor($data, $invoice, $contract);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
            }

            $paidAt = $this->date($data['paid_at'] ?? null) ?? now()->startOfDay();

            $payment = BillingPayment::create([
                'invoice_id' => $invoice?->id,
                'contract_id' => $contract?->id,
                'prospect_id' => $prospectId,
                'amount_cents' => $amount,
                'method' => $data['method'] ?? ($contract?->payment_method ?: ($contract?->isCard()
                    ? BillingPayment::METHOD_CREDIT_CARD
                    : BillingPayment::METHOD_PIX)),
                'paid_at' => $paidAt->toDateString(),
                'reference' => $data['reference'] ?? null,
                'external_ref' => $data['external_ref'] ?? null,
                'source' => $data['source'] ?? BillingPayment::SOURCE_MANUAL,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor?->id,
            ]);

            if ($invoice !== null) {
                $this->refreshInvoice($invoice);
            }

            if ($contract !== null && $contract->isCard() && $payment->method === BillingPayment::METHOD_CREDIT_CARD) {
                $this->advanceCard($contract, $paidAt);
            }

            return $payment;
        });
    }

    public function refreshInvoice(BillingInvoice $invoice): void
    {
        $paid = (int) $invoice->payments()->sum('amount_cents');

        $invoice->paid_cents = $paid;

        if ($paid >= $invoice->amount_cents) {
            $invoice->status = BillingInvoice::STATUS_PAID;
        } elseif ($invoice->status === BillingInvoice::STATUS_PAID) {
            $invoice->status = $invoice->due_date?->isPast()
                ? BillingInvoice::STATUS_OVERDUE
                : BillingInvoice::STATUS_SENT;
        }

        $invoice->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function contractFor(array $data, ?BillingInvoice $invoice): ?ProspectContract
    {
        if (isset($data['contract_id'])) {
            return ProspectContract::find($data['contract_id']);
        }

        return $invoice?->contract;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function amountFor(array $data, ?BillingInvoice $invoice, ?ProspectContract $contract): int
    {
        if (isset($data['amount_cents']) && $data['amount_cents'] !== '') {
            return (int) $data['amount_cents'];
        }

        if (isset($data['amount']) && $data['amount'] !== '') {
            return (int) round(((float) $data['amount']) * 100);
        }

        if ($invoice !== null) {
            return $invoice->balanceCents();
        }

        if ($contract !== null) {
            return $contract->isMembersArea()
                ? $contract->total_value_cents
                : $contract->monthly_value_cents;
        }

        return 0;
    }

    private function advanceCard(ProspectContract $contract, Carbon $paidAt): void
    {
        $next = $contract->next_charge_at;

        if ($next === null || $next->copy()->startOfDay()->lte($paidAt)) {
            $contract->next_charge_at = $paidAt->copy()->addMonthNoOverflow();
            $contract->save();
        }
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
