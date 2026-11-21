<?php

namespace App\Mail;

use App\Models\BillingInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceApprovalDigest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, BillingInvoice>  $invoices
     */
    public function __construct(public array $invoices) {}

    public function build(): self
    {
        $count = count($this->invoices);
        $month = $this->invoices[0]->period_start?->format('m/Y') ?? now()->format('m/Y');

        return $this
            ->subject('[Financeiro] '.$count.' fatura(s) para aprovar — '.$month)
            ->view('emails.invoice-approval-digest', [
                'invoices' => $this->invoices,
                'count' => $count,
            ]);
    }
}
