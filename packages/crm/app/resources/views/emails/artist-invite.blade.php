<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Defina sua senha — VAIF CRM</title>
</head>
<body style="margin:0;padding:0;background:#0A0A0A;font-family:Arial,Helvetica,sans-serif;color:#f2ede4;">
    <div style="max-width:560px;margin:0 auto;padding:32px 20px;">
        <div style="background:#121212;border:1px solid #222222;border-radius:16px;padding:32px;">
            <img src="{{ asset('img/vaif_logo.png') }}" width="180" alt="VAIF" style="display:block;border:0;margin:0 0 24px;">

            <h1 style="margin:0 0 16px;font-size:24px;line-height:1.3;color:#f2ede4;">Bem-vindo ao VAIF CRM</h1>

            <p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#d7d1c6;">
                Olá, {{ $user->name }}.
            </p>
            <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#d7d1c6;">
                Sua conta foi criada. Para começar a usar o CRM, defina sua senha:
            </p>

            <p style="margin:0 0 28px;">
                <a href="{{ $url }}"
                   style="display:inline-block;background:#D4B04C;color:#0A0A0A;text-decoration:none;font-weight:bold;font-size:15px;padding:13px 22px;border-radius:9px;">
                    Definir minha senha
                </a>
            </p>

            <p style="margin:0 0 8px;font-size:12px;line-height:1.6;color:#a09a8e;">
                O link expira em 7 dias. Se o botão não funcionar, copie e cole este endereço no navegador:
            </p>
            <p style="margin:0 0 24px;font-size:12px;line-height:1.6;color:#D4B04C;word-break:break-all;">
                {{ $url }}
            </p>

            <p style="margin:0;font-size:12px;line-height:1.6;color:#a09a8e;">
                Se você não reconhece este convite, ignore este e-mail.
            </p>
        </div>

        <p style="margin:18px 0 0;text-align:center;font-size:11px;color:#6f6a60;">
            VAIF CRM — {{ config('app.url') }}
        </p>
    </div>
</body>
</html>
