<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row finance configuration (id = 1): issuer data, PIX key, invoice
 * prefix and the invoice approval/delivery texts.
 */
class BillingSetting extends Model
{
    public const DEFAULT_INVOICE_TERMS = 'Em caso de atraso no pagamento será cobrada uma multa de 1% ao mês e todos os serviços serão pausados imediatamente.';

    public const DEFAULT_INVOICE_MESSAGE = "Olá, {primeiro_nome}! Tudo bem?\nSegue a fatura referente aos seus serviços ativos neste mês (período com início {periodo}).\n\n🔗 Acesse ou baixe o seu PDF com segurança aqui:\n{link}\n\nO vencimento está programado para {vencimento}.\n\nValor: {valor}\n\nQualquer dúvida sobre os itens, estou à disposição!\n\n{notas}";

    public const DEFAULT_DUNNING_MESSAGE = "Olá, {primeiro_nome}! A fatura {numero} no valor de {valor} venceu em {vencimento} e ainda está em aberto.\n\nPara evitar a multa de 1% ao mês e a pausa dos serviços, regularize o pagamento:\n{link}\n\nSe já pagou, desconsidere esta mensagem.";

    protected $table = 'billing_settings';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'legal_name',
        'tax_id',
        'address',
        'pix_key',
        'invoice_prefix',
        'default_due_day',
        'invoice_lead_days',
        'invoice_approval_email',
        'invoice_terms',
        'invoice_whatsapp_message',
        'dunning_whatsapp_message',
        'payment_instructions',
        'receipt_footer',
    ];

    protected $casts = [
        'default_due_day' => 'integer',
        'invoice_lead_days' => 'integer',
    ];

    public static function current(): self
    {
        $setting = self::firstOrCreate(['id' => 1], [
            'invoice_prefix' => 'INV',
            'default_due_day' => 10,
            'invoice_lead_days' => 3,
            'invoice_terms' => self::DEFAULT_INVOICE_TERMS,
            'invoice_whatsapp_message' => self::DEFAULT_INVOICE_MESSAGE,
            'dunning_whatsapp_message' => self::DEFAULT_DUNNING_MESSAGE,
        ]);

        $dirty = false;

        foreach ([
            'invoice_terms' => self::DEFAULT_INVOICE_TERMS,
            'invoice_whatsapp_message' => self::DEFAULT_INVOICE_MESSAGE,
            'dunning_whatsapp_message' => self::DEFAULT_DUNNING_MESSAGE,
        ] as $field => $default) {
            if (blank($setting->{$field})) {
                $setting->{$field} = $default;
                $dirty = true;
            }
        }

        if ($setting->invoice_lead_days === null) {
            $setting->invoice_lead_days = 3;
            $dirty = true;
        }

        if ($dirty) {
            $setting->save();
        }

        return $setting;
    }

    /**
     * @return array<int, string>
     */
    public function approvalEmails(): array
    {
        $configured = preg_split('/[,;\s]+/', (string) $this->invoice_approval_email, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($configured === []) {
            $fallback = (string) (config('finance.approval_email')
                ?: config('crm.admin_email')
                ?: config('mail.from.address', ''));

            if ($fallback !== '') {
                $configured = [$fallback];
            }
        }

        return array_values(array_unique(array_filter(
            array_map('trim', $configured),
            fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }

    public function invoiceLeadDays(): int
    {
        return max(0, (int) ($this->invoice_lead_days ?? 3));
    }
}
