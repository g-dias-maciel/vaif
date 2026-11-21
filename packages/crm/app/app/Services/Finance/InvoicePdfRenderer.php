<?php

namespace App\Services\Finance;

use App\Models\BillingInvoice;
use App\Models\BillingSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Renders the invoice PDF from the Blade template that mirrors the old Google
 * Docs invoice_template. The logo is embedded as a data URI so Dompdf does not
 * need remote-file access.
 */
class InvoicePdfRenderer
{
    public function render(BillingInvoice $invoice): string
    {
        $invoice->loadMissing(['prospect', 'items', 'contract']);

        $setting = BillingSetting::current();
        $logoPath = public_path('images/vaif-invoice-logo.png');
        $logoData = is_file($logoPath) ? base64_encode((string) file_get_contents($logoPath)) : null;

        $pdf = Pdf::loadView('finance.invoices.pdf', [
            'invoice' => $invoice,
            'setting' => $setting,
            'logoData' => $logoData,
        ])->setPaper('a4')->setOptions([
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ]);

        return $pdf->output();
    }

    public function path(BillingInvoice $invoice): string
    {
        $year = $invoice->issue_date?->format('Y') ?: now()->format('Y');
        $base = trim((string) config('finance.pdf_path', 'invoices'), '/');

        return $base.'/'.$year.'/'.$invoice->number.'.pdf';
    }

    public function store(BillingInvoice $invoice): string
    {
        $path = $this->path($invoice);

        Storage::disk($this->disk())->put($path, $this->render($invoice));

        return $path;
    }

    public function publicUrl(BillingInvoice $invoice): string
    {
        return URL::temporarySignedRoute(
            'finance.invoices.public-pdf',
            now()->addDays(max(1, (int) config('finance.pdf_link_days', 30))),
            ['invoice' => $invoice->id],
        );
    }

    public function download(BillingInvoice $invoice)
    {
        $path = $this->store($invoice);

        return Storage::disk($this->disk())->download($path, 'fatura-'.$invoice->number.'.pdf');
    }

    private function disk(): string
    {
        return (string) config('finance.pdf_disk', 'local');
    }
}
