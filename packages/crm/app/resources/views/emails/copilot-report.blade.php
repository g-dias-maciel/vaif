<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>{{ $report->label() }}</title>
</head>
<body style="margin:0; padding:0; background:#0A0A0A; color:#f2ede4; font-family:Arial, Helvetica, sans-serif;">
    <div style="max-width:620px; margin:0 auto; padding:32px 24px;">
        <p style="font-size:13px; letter-spacing:2px; text-transform:uppercase; color:#D4B04C; margin:0 0 12px;">
            VAIF · Copiloto
        </p>

        <h1 style="font-size:24px; margin:0 0 8px;">{{ $report->label() }}</h1>
        <p style="color:#a09a8e; line-height:1.6; margin:0 0 24px;">
            {{ $report->period_start?->format('d/m/Y H:i') }} — {{ $report->period_end?->format('d/m/Y H:i') }}
        </p>

        @if ($report->narrative)
            <p style="line-height:1.7; margin:0 0 20px;">{{ $report->narrative }}</p>
        @endif

        @forelse ($report->findings ?? [] as $finding)
            @php
                $severity = $finding['severity'] ?? 'info';
                $color = $severity === 'critical' ? '#D44C4C' : ($severity === 'warning' ? '#D4B04C' : '#6b6b6b');
            @endphp
            <div style="background:#121212; border-left:3px solid {{ $color }}; border-radius:8px; padding:14px 16px; margin-bottom:12px;">
                <p style="margin:0 0 6px; color:#f2ede4;"><strong>{{ $finding['title'] ?? '' }}</strong></p>
                <p style="margin:0 0 8px; color:#d8c9c6; line-height:1.55;">{{ $finding['body'] ?? '' }}</p>

                @if (! empty($finding['suggested_action']))
                    <p style="margin:0; color:#a09a8e; font-size:13px; line-height:1.5;">
                        <strong style="color:#D4B04C;">Sugestão:</strong> {{ $finding['suggested_action'] }}
                    </p>
                @endif
            </div>
        @empty
            <p style="color:#a09a8e;">Sem achados automáticos neste relatório.</p>
        @endforelse

        <p style="margin-top:26px;">
            <a href="{{ route('copilot.show', $report) }}" style="display:inline-block; background:#D4B04C; color:#0A0A0A; text-decoration:none; padding:10px 18px; border-radius:8px; font-weight:bold;">
                Abrir no CRM
            </a>
        </p>
    </div>
</body>
</html>
