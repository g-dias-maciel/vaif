@php
    $count = count($handoffs) + count($deposits);
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Clientes aguardando você</title>
</head>
<body style="margin:0; padding:0; background:#0A0A0A; color:#f2ede4; font-family:Arial, Helvetica, sans-serif;">
    <div style="max-width:620px; margin:0 auto; padding:32px 24px;">
        <p style="font-size:13px; letter-spacing:2px; text-transform:uppercase; color:#D4B04C; margin:0 0 12px;">
            VAIF CRM
        </p>
        <h1 style="font-size:24px; margin:0 0 8px;">{{ $count }} cliente{{ $count === 1 ? '' : 's' }} aguardando você</h1>
        <p style="color:#a09a8e; line-height:1.6; margin:0 0 24px;">
            Olá, {{ $artist->displayName() }}. Estes clientes precisam de uma ação sua no CRM.
        </p>

        @if (count($handoffs) > 0)
            <div style="background:#121212; border:1px solid #2a2a2a; border-radius:12px; padding:20px; margin-bottom:18px;">
                <h2 style="font-size:17px; margin:0 0 6px; color:#f2ede4;">Atendimentos aguardando você</h2>
                <p style="color:#a09a8e; font-size:13px; margin:0 0 16px;">
                    O agente passou estes clientes para você. Abra a conversa para assumir o atendimento.
                </p>

                @foreach ($handoffs as $handoff)
                    <div style="border-top:1px solid #242424; padding:12px 0;">
                        <strong style="display:block; color:#f2ede4;">{{ $handoff['name'] }}</strong>
                        <span style="display:block; color:#a09a8e; font-size:13px; margin:2px 0 8px;">
                            {{ $handoff['reason'] }}@if (! empty($handoff['phone'])) · {{ $handoff['phone'] }}@endif
                        </span>
                        <a href="{{ $handoff['url'] }}" style="display:inline-block; background:#D4B04C; color:#0A0A0A; padding:8px 14px; border-radius:8px; text-decoration:none; font-weight:700; font-size:13px;">
                            Ver conversa
                        </a>
                    </div>
                @endforeach
            </div>
        @endif

        @if (count($deposits) > 0)
            <div style="background:#121212; border:1px solid #4a3d18; border-radius:12px; padding:20px; margin-bottom:18px;">
                <h2 style="font-size:17px; margin:0 0 6px; color:#D4B04C;">Sinais aguardando confirmação</h2>
                <p style="color:#a09a8e; font-size:13px; margin:0 0 16px;">
                    Confira o PIX recebido e confirme o sinal para liberar o agendamento.
                </p>

                @foreach ($deposits as $deposit)
                    <div style="border-top:1px solid #242424; padding:12px 0;">
                        <strong style="display:block; color:#f2ede4;">{{ $deposit['name'] }}</strong>
                        <span style="display:block; color:#a09a8e; font-size:13px; margin:2px 0 8px;">
                            Sinal de R$ {{ $deposit['amount'] }}
                        </span>
                        <a href="{{ $deposit['url'] }}" style="display:inline-block; background:#D4B04C; color:#0A0A0A; padding:8px 14px; border-radius:8px; text-decoration:none; font-weight:700; font-size:13px;">
                            Confirmar sinal
                        </a>
                    </div>
                @endforeach
            </div>
        @endif

        <p style="color:#6f6a60; font-size:12px; line-height:1.5; margin:24px 0 0;">
            Este aviso é enviado uma vez por cliente. Depois de responder ou confirmar, ele não será repetido.
        </p>
    </div>
</body>
</html>
