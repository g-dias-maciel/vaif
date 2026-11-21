<?php

namespace App\Console\Commands;

use App\Mail\InvoiceApprovalDigest;
use App\Models\BillingInvoice;
use App\Models\BillingSetting;
use App\Models\ProspectContract;
use App\Services\Finance\InvoiceGenerator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Creates the PIX invoices that are due for active contracts. Card contracts
 * are skipped: their Mercado Pago charges are recorded as payments.
 *
 * New invoices are drafts and go to the approval queue; a digest e-mail warns
 * the finance address. Use --no-mail to generate without notifying.
 */
class GenerateBillingInvoices extends Command
{
    protected $signature = 'finance:generate-invoices
        {--date= : Data de referência (Y-m-d, padrão hoje)}
        {--contract= : Gera apenas para um contrato (UUID)}
        {--dry-run : Relata sem gravar}
        {--no-mail : Não envia o e-mail de aprovação}';

    protected $description = 'Gera as cobranças PIX dos contratos ativos e envia para aprovação';

    public function handle(InvoiceGenerator $generator): int
    {
        $asOf = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))->startOfDay()
            : now()->startOfDay();

        $leadDays = BillingSetting::current()->invoiceLeadDays();
        $limit = $asOf->copy()->addDays($leadDays);

        $query = ProspectContract::query()
            ->where('status', ProspectContract::STATUS_ACTIVE)
            ->where('billing_mode', 'pix')
            ->whereNotNull('next_invoice_at')
            ->whereDate('next_invoice_at', '<=', $limit->toDateString())
            ->with('prospect');

        if ($contractId = $this->option('contract')) {
            $query->where('id', $contractId);
        }

        $contracts = $query->orderBy('next_invoice_at')->get();

        if ($contracts->isEmpty()) {
            $this->info('Nenhum contrato PIX com cobrança a gerar.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $totalCents = 0;
        /** @var array<int, BillingInvoice> $created */
        $created = [];

        DB::beginTransaction();

        try {
            foreach ($contracts as $contract) {
                $invoices = $generator->generateDue($contract, $asOf);

                foreach ($invoices as $invoice) {
                    $created[] = $invoice;
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

        if (! $dryRun && ! (bool) $this->option('no-mail')) {
            $this->notifyApproval($created);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, BillingInvoice>  $invoices
     */
    private function notifyApproval(array $invoices): void
    {
        $setting = BillingSetting::current();
        $emails = $setting->approvalEmails();

        if ($emails === []) {
            $this->warn('Nenhum e-mail de aprovação configurado; as faturas ficaram na fila.');

            return;
        }

        try {
            Mail::to($emails)->send(new InvoiceApprovalDigest($invoices));
            $this->info('E-mail de aprovação enviado para '.implode(', ', $emails).'.');
        } catch (Throwable $e) {
            Log::warning('Invoice approval e-mail failed', ['error' => $e->getMessage()]);
            $this->warn('Falha ao enviar o e-mail de aprovação: '.$e->getMessage());
        }
    }
}
