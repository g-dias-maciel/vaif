<?php

namespace App\Services\Finance;

use App\Models\BillingExpense;
use App\Models\FinanceDistribution;
use App\Models\FinancePartner;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persists a month's partner payouts.
 *
 * Profit rows store the final amount staff approved. Reimbursement rows are
 * computed from the selected expenses and mark those expenses as settled so
 * they cannot be reimbursed twice in a later month.
 */
class DistributionRecorder
{
    /**
     * @param  array<string, array{profit_cents?: int|string, expense_ids?: array<int, string>, notes?: string}>  $rows
     */
    public function saveMonth(Carbon $month, array $rows, ?User $actor = null): void
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();

        DB::transaction(function () use ($rows, $monthStart, $actor): void {
            $partners = FinancePartner::query()
                ->whereIn('id', array_keys($rows))
                ->get()
                ->keyBy('id');

            foreach ($rows as $partnerId => $row) {
                $partner = $partners->get($partnerId);

                if ($partner === null) {
                    continue;
                }

                $profit = max(0, (int) ($row['profit_cents'] ?? 0));
                $notes = trim((string) ($row['notes'] ?? '')) ?: null;

                $this->upsert(
                    $partner,
                    $monthStart,
                    FinanceDistribution::KIND_PROFIT,
                    $profit,
                    $notes,
                    $actor,
                );

                $existing = FinanceDistribution::query()
                    ->where('partner_id', $partner->id)
                    ->whereDate('competence_month', $monthStart)
                    ->where('kind', FinanceDistribution::KIND_REIMBURSEMENT)
                    ->first();

                $expenseIds = array_values(array_filter(
                    (array) ($row['expense_ids'] ?? []),
                    fn ($id) => is_string($id) && $id !== '',
                ));

                $expenses = BillingExpense::query()
                    ->whereIn('id', $expenseIds)
                    ->where('paid_by_partner_id', $partner->id)
                    ->where('status', BillingExpense::STATUS_PAID)
                    ->where(function ($query) use ($existing): void {
                        $query->whereNull('reimbursed_at');

                        if ($existing !== null) {
                            $query->orWhere('reimbursed_distribution_id', $existing->id);
                        }
                    })
                    ->get();

                if ($existing !== null) {
                    BillingExpense::query()
                        ->where('reimbursed_distribution_id', $existing->id)
                        ->whereNotIn('id', $expenses->pluck('id')->all() ?: [''])
                        ->update([
                            'reimbursed_distribution_id' => null,
                            'reimbursed_at' => null,
                        ]);
                }

                $total = (int) $expenses->sum('amount_cents');

                if ($total > 0) {
                    $distribution = $this->upsert(
                        $partner,
                        $monthStart,
                        FinanceDistribution::KIND_REIMBURSEMENT,
                        $total,
                        $notes,
                        $actor,
                    );

                    BillingExpense::query()
                        ->whereIn('id', $expenses->pluck('id'))
                        ->update([
                            'reimbursed_distribution_id' => $distribution->id,
                            'reimbursed_at' => now()->toDateString(),
                        ]);
                } elseif ($existing !== null) {
                    $existing->delete();
                }
            }
        });
    }

    private function upsert(
        FinancePartner $partner,
        string $month,
        string $kind,
        int $amountCents,
        ?string $notes,
        ?User $actor,
    ): ?FinanceDistribution {
        $existing = FinanceDistribution::query()
            ->where('partner_id', $partner->id)
            ->whereDate('competence_month', $month)
            ->where('kind', $kind)
            ->first();

        if ($amountCents <= 0) {
            $existing?->delete();

            return null;
        }

        if ($existing !== null) {
            $existing->update([
                'amount_cents' => $amountCents,
                'notes' => $notes,
            ]);

            return $existing;
        }

        return FinanceDistribution::create([
            'partner_id' => $partner->id,
            'competence_month' => $month,
            'kind' => $kind,
            'amount_cents' => $amountCents,
            'paid_at' => now()->toDateString(),
            'notes' => $notes,
            'created_by' => $actor?->id,
        ]);
    }
}
