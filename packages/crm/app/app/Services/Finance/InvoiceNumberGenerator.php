<?php

namespace App\Services\Finance;

use App\Models\BillingSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sequential invoice numbers per year, e.g. VAIF-2026-0001. The counter row is
 * locked inside the caller's transaction so concurrent runs cannot reuse a
 * number.
 */
class InvoiceNumberGenerator
{
    public function next(?Carbon $date = null): string
    {
        $year = (int) ($date ?? now())->format('Y');
        $prefix = trim((string) BillingSetting::current()->invoice_prefix) ?: 'VAIF';
        $prefix = mb_strtoupper(preg_replace('/[^A-Za-z0-9_-]+/', '-', $prefix) ?: 'VAIF');

        return DB::transaction(function () use ($year, $prefix): string {
            DB::table('billing_invoice_counters')->insertOrIgnore([
                'year' => $year,
                'last_number' => 0,
            ]);

            $counter = DB::table('billing_invoice_counters')
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            $next = (int) ($counter->last_number ?? 0) + 1;

            DB::table('billing_invoice_counters')
                ->where('year', $year)
                ->update(['last_number' => $next]);

            return sprintf('%s-%d-%04d', $prefix, $year, $next);
        });
    }
}
