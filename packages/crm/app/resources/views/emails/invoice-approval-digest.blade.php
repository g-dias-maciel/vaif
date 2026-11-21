<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Faturas para aprovação</title>
</head>
<body style="margin:0; padding:0; background:#0A0A0A; color:#f2ede4; font-family:Arial, Helvetica, sans-serif;">
    <div style="max-width:640px; margin:0 auto; padding:32px 24px;">
        <p style="font-size:13px; letter-spacing:2px; text-transform:uppercase; color:#D4B04C; margin:0 0 12px;">
            VAIF · Financeiro
        </p>

        <h1 style="font-size:24px; margin:0 0 8px;">{{ $count }} fatura(s) aguardando aprovação</h1>
        <p style="color:#a09a8e; line-height:1.6; margin:0 0 24px;">
            Revise os valores e clique em <strong>Aprovar e enviar</strong> no CRM. O cliente recebe o PDF pelo WhatsApp.
        </p>

        <table style="width:100%; border-collapse:collapse; margin-bottom:22px;">
            <thead>
                <tr>
                    <th style="text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#a09a8e; padding:8px 6px; border-bottom:1px solid #333;">Cliente</th>
                    <th style="text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#a09a8e; padding:8px 6px; border-bottom:1px solid #333;">Fatura</th>
                    <th style="text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#a09a8e; padding:8px 6px; border-bottom:1px solid #333;">Vencimento</th>
                    <th style="text-align:right; font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#a09a8e; padding:8px 6px; border-bottom:1px solid #333;">Valor</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td style="padding:10px 6px; border-bottom:1px solid #222;">
                            <a href="{{ route('finance.invoices.show', $invoice) }}" style="color:#f2ede4; text-decoration:none;">
                                <strong>{{ $invoice->prospect?->name ?? '—' }}</strong>
                            </a>
                            <div style="color:#a09a8e; font-size:12px;">{{ $invoice->number }}</div>
                        </td>
                        <td style="padding:10px 6px; border-bottom:1px solid #222;">
                            <a href="{{ route('finance.invoices.show', $invoice) }}" style="color:#D4B04C; text-decoration:none;">Revisar</a>
                        </td>
                        <td style="padding:10px 6px; border-bottom:1px solid #222;">{{ $invoice->due_date?->format('d/m/Y') ?? '—' }}</td>
                        <td style="padding:10px 6px; border-bottom:1px solid #222; text-align:right; white-space:nowrap;">
                            R$ {{ number_format($invoice->amount_cents / 100, 2, ',', '.') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p style="margin-top:26px;">
            <a href="{{ route('finance.dashboard') }}" style="display:inline-block; background:#D4B04C; color:#0A0A0A; text-decoration:none; padding:10px 18px; border-radius:8px; font-weight:bold;">
                Abrir Financeiro
            </a>
        </p>
    </div>
</body>
</html>
