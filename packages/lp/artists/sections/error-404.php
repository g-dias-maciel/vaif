<?php

declare(strict_types=1);

/**
 * 404 page for /artists/<slug>. Self-contained; required before routing so the
 * function exists when the router bails out.
 */
function render_404(): void
{
    echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Artista não encontrado — VAIF</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #0A0A0A;
            color: rgb(242, 237, 228);
            font-family: 'Montserrat', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 24px;
            background-image: radial-gradient(circle at 80% 20%, rgba(212, 176, 76, 0.05), transparent 40%);
        }
        .err-card {
            background: #121212;
            border: 1px solid #222222;
            border-radius: 16px;
            padding: 60px 40px;
            max-width: 480px;
            width: 100%;
        }
        h1 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2.5rem;
            font-weight: 600;
            color: #D4B04C;
            margin-bottom: 16px;
        }
        p {
            color: #CCCCCC;
            font-size: 1rem;
            line-height: 1.7;
            margin-bottom: 32px;
        }
        .btn-back {
            display: inline-block;
            padding: 14px 32px;
            background: #D4B04C;
            color: #000;
            text-decoration: none;
            font-family: 'Montserrat', sans-serif;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            border-radius: 4px;
            transition: background-color 0.3s, transform 0.3s;
        }
        .btn-back:hover {
            background: #E5C35E;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="err-card">
        <h1>Artista não encontrado</h1>
        <p>A página que você procura não existe ou o artista ainda não foi cadastrado em nossa plataforma.</p>
        <a href="/" class="btn-back">Voltar ao site</a>
    </div>
</body>
</html>
HTML;
}
