<?php

namespace App\Console\Commands;

use App\Models\BillingInvoice;
use Illuminate\Console\Command;

/**
 * Marks open invoices whose due date has passed as overdue. A status, not a
 * separate row; paying an overdue invoice moves it back to paid.
 */
class MarkBillingOverdue extends Command
{
    protected $signature = 'finance:mark-overdue
        {--date= : Data de referência (Y-m-d, padrão hoje)}
        {--dry-run : Relata sem gravar}';

    protected $description = 'Marca como atrasadas as cobranças vencidas e não pagas';

    public function handle(): int
    {
        $asOf = $this->option('date')
            ? \Carbon\Carbon::parse((string) $this->option('date'))->startOfDay()
            : now()->startOfDay();

        $query = BillingInvoice::query()
            ->whereIn('status', [BillingInvoice::STATUS_DRAFT, BillingInvoice::STATUS_SENT])
            ->whereDate('due_date', '<', $asOf->toDateString())
            ->whereColumn('paid_cents', '<', 'amount_cents');

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('Nenhuma cobrança vencida em aberto.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("Dry-run: {$count} cobrança(s) seriam marcadas como atrasadas.");

            return self::SUCCESS;
        }

        $query->update(['status' => BillingInvoice::STATUS_OVERDUE]);

        $this->info("{$count} cobrança(s) marcadas como atrasadas.");

        return self::SUCCESS;
    }
}
