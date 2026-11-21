<?php

namespace App\Services\Finance;

use App\Models\BillingInvoice;
use App\Models\BillingSetting;
use App\Services\WahaService;

/**
 * Sends invoices and dunning messages through the company WhatsApp session.
 * Replaces the WAHA steps of the old n8n Create Invoice workflow.
 */
class InvoiceNotifier
{
    public function __construct(
        private readonly WahaService $waha,
        private readonly InvoicePdfRenderer $pdf,
    ) {}

    public function send(BillingInvoice $invoice): bool
    {
        $invoice->loadMissing(['prospect', 'items']);
        $setting = BillingSetting::current();

        try {
            $this->pdf->store($invoice);
        } catch (\Throwable $e) {
            $this->fail($invoice, 'Falha ao gerar o PDF: '.$e->getMessage());

            return false;
        }

        $message = $this->render(
            (string) ($setting->invoice_whatsapp_message ?: BillingSetting::DEFAULT_INVOICE_MESSAGE),
            $invoice,
            $setting,
        );

        return $this->deliver($invoice, $message, reminder: false);
    }

    public function sendReminder(BillingInvoice $invoice): bool
    {
        $invoice->loadMissing(['prospect', 'items']);
        $setting = BillingSetting::current();

        try {
            $this->pdf->store($invoice);
        } catch (\Throwable $e) {
            $this->fail($invoice, 'Falha ao gerar o PDF: '.$e->getMessage());

            return false;
        }

        $message = $this->render(
            (string) ($setting->dunning_whatsapp_message ?: BillingSetting::DEFAULT_DUNNING_MESSAGE),
            $invoice,
            $setting,
        );

        return $this->deliver($invoice, $message, reminder: true);
    }

    private function deliver(BillingInvoice $invoice, string $message, bool $reminder): bool
    {
        $prospect = $invoice->prospect;
        $phone = $prospect?->whatsapp_e164;

        if (blank($phone)) {
            $this->fail($invoice, 'Cliente sem WhatsApp cadastrado.');

            return false;
        }

        $session = (string) config('finance.waha_session', 'default');
        $check = $this->waha->checkNumber($session, (string) $phone);

        if ($check !== null && $check['numberExists'] === false) {
            $this->fail($invoice, 'Número de WhatsApp inválido.');

            return false;
        }

        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        $chatId = $check['chatId'] ?? ($digits !== '' ? $digits.'@c.us' : '');

        if ($chatId === '') {
            $this->fail($invoice, 'WhatsApp não configurado para este cliente.');

            return false;
        }

        if (! $this->waha->sendTextToSession($session, $chatId, $message)) {
            $this->fail($invoice, 'Falha ao enviar pelo WhatsApp.');

            return false;
        }

        $invoice->forceFill([
            'delivery_status' => BillingInvoice::DELIVERY_SENT,
            'delivery_error' => null,
            'sent_at' => $invoice->sent_at ?? now(),
        ]);

        if ($reminder) {
            $invoice->forceFill([
                'reminder_count' => ((int) $invoice->reminder_count) + 1,
                'last_reminded_at' => now(),
            ]);
        }

        $invoice->save();

        return true;
    }

    private function fail(BillingInvoice $invoice, string $error): void
    {
        $invoice->forceFill([
            'delivery_status' => BillingInvoice::DELIVERY_FAILED,
            'delivery_error' => $error,
        ])->save();
    }

    private function render(string $template, BillingInvoice $invoice, BillingSetting $setting): string
    {
        $prospect = $invoice->prospect;
        $name = (string) ($prospect?->name ?? 'cliente');
        $firstName = trim(explode(' ', $name)[0] ?? $name);

        $values = [
            '{primeiro_nome}' => $firstName,
            '{cliente}' => $name,
            '{numero}' => (string) $invoice->number,
            '{periodo}' => $invoice->periodLabel(),
            '{vencimento}' => $invoice->due_date?->format('d/m/Y') ?? '—',
            '{valor}' => 'R$ '.number_format($invoice->balanceCents() / 100, 2, ',', '.'),
            '{link}' => $this->pdf->publicUrl($invoice),
            '{notas}' => (string) ($invoice->notes ?? ''),
            '{pix}' => (string) ($setting->pix_key ?? ''),
        ];

        return trim(strtr($template, $values));
    }
}
