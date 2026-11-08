<?php

namespace App\Console\Commands;

use App\Models\ProspectContract;
use App\Services\Finance\InvoiceGenerator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates the PIX invoices that are due for active contracts. Card contracts
 * are skipped: their Mercado Pago charges are recorded as payments.
 */
class GenerateBillingInvoices extends Command
{
    protected $signature = 'finance:generate-invoices
        {--date= : Data de referência (Y-m-d, padrão hoje)}
        {--contract= : Gera apenas para um contrato (UUID)}
        {--dry-run : Relata sem gravar}';

    protected $description = 'Gera as cobranças PIX vencidas dos contratos ativos';

    public function handle(InvoiceGenerator $generator): int
    {
        $asOf = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))->startOfDay()
            : now()->startOfDay();

        $query = ProspectContract::query()
            ->where('status', ProspectContract::STATUS_ACTIVE)
            ->where('billing_mode', 'pix')
            ->whereNotNull('next_invoice_at')
            ->whereDate('next_invoice_at', '<=', $asOf->toDateString())
            ->with('prospect');

        if ($contractId = $this->option('contract')) {
            $query->where('id', $contractId);
        }

        $contracts = $query->orderBy('next_invoice_at')->get();

        if ($contracts->isEmpty()) {
            $this->info('Nenhum contrato PIX com cobrança vencida.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $totalCents = 0;

        DB::beginTransaction();

        try {
            foreach ($contracts as $contract) {
                $invoices = $generator->generateDue($contract, $asOf);

                foreach ($invoices as $invoice) {
                    $totalCents += $invoice->amount_cents;
                    $rows[] = [
                        $invoice->number,
                        $contract->prospect?->name ?? '—',
                        $invoice->period_start->format('d/m/Y'),
                        $invoice->due_date->format('d/m/Y'),
                        'R$ '.number_format($invoice->amount_cents / 100, 2, ',', '.'),
                    ];
                }
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Falha ao gerar cobranças: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->info('Nenhuma cobrança nova gerada.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Dry-run: nada foi gravado.');
        }

        $this->table(['Número', 'Cliente', 'Período', 'Vencimento', 'Valor'], $rows);
        $this->line('Total: R$ '.number_format($totalCents / 100, 2, ',', '.'));

        return self::SUCCESS;
    }
}
