<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'VAIF')</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #111; background: #fff; font-size: 13px; line-height: 1.5; }
        .sheet { max-width: 760px; margin: 0 auto; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .8px; margin: 24px 0 8px; color: #555; }
        .muted { color: #666; }
        .row { display: flex; justify-content: space-between; gap: 24px; }
        .box { border: 1px solid #ddd; border-radius: 8px; padding: 14px 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px 6px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
        th { font-size: 10px; text-transform: uppercase; letter-spacing: .6px; color: #666; }
        .right { text-align: right; }
        .total { font-size: 18px; font-weight: 600; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 999px; border: 1px solid #999; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; }
        .badge--paid { border-color: #15803d; color: #15803d; }
        .badge--overdue { border-color: #b91c1c; color: #b91c1c; }
        .footer { margin-top: 32px; padding-top: 14px; border-top: 1px solid #ddd; color: #666; font-size: 11px; }
        .print-button { position: fixed; top: 16px; right: 16px; padding: 10px 16px; border: 0; border-radius: 8px; background: #111; color: #fff; cursor: pointer; }
        @media print {
            body { padding: 0; }
            .print-button { display: none; }
            .box, table { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <button class="print-button" onclick="window.print()">Imprimir</button>
    <div class="sheet">
        @yield('content')
    </div>
</body>
</html>
