<?php

namespace App\Services\Finance;

use App\Models\BillingExpense;
use App\Models\User;
use Carbon\Carbon;

/**
 * Clones monthly recurring expenses into every month up to a target month.
 * Idempotent: the unique (recurrence_key, competence_month) index plus the
 * existence check prevent duplicate months.
 */
class RecurringExpenseGenerator
{
    public function generateThrough(Carbon $through, ?User $actor = null): int
    {
        $through = $through->copy()->startOfMonth();

        $series = BillingExpense::query()
            ->where('recurrence', BillingExpense::RECURRENCE_MONTHLY)
            ->whereNotNull('recurrence_key')
            ->orderBy('competence_month')
            ->get()
            ->groupBy('recurrence_key');

        $created = 0;

        foreach ($series as $key => $expenses) {
            $latest = $expenses->sortByDesc('competence_month')->first();

            if ($latest === null || $latest->isCancelled()) {
                continue;
            }

            $next = $latest->competence_month->copy()->startOfMonth()->addMonthNoOverflow();
            $guard = 0;

            while ($next->lte($through) && $guard++ < 24) {
                $existing = BillingExpense::query()
                    ->where('recurrence_key', $key)
                    ->whereDate('competence_month', $next->toDateString())
                    ->first();

                if ($existing !== null) {
                    $latest = $existing;
                    $next = $next->copy()->addMonthNoOverflow();

                    continue;
                }

                $dueDay = min($latest->due_date?->day ?? 1, $next->daysInMonth);

                $expense = BillingExpense::create([
                    'description' => $latest->description,
                    'vendor' => $latest->vendor,
                    'category' => $latest->category,
                    'amount_cents' => $latest->amount_cents,
                    'competence_month' => $next->copy()->startOfMonth()->toDateString(),
                    'due_date' => $next->copy()->day($dueDay)->toDateString(),
                    'paid_at' => null,
                    'status' => BillingExpense::STATUS_PENDING,
                    'payment_method' => $latest->payment_method,
                    'recurrence' => BillingExpense::RECURRENCE_MONTHLY,
                    'recurrence_key' => $key,
                    'notes' => $latest->notes,
                    'created_by' => $actor?->id ?? $latest->created_by,
                ]);

                $created++;
                $latest = $expense;
                $next = $next->copy()->addMonthNoOverflow();
            }
        }

        return $created;
    }
}
