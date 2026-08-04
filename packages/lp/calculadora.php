<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lucro Oculto - Calculadora para Tatuadores</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '752550821217294');
        fbq('track', 'PageView');
    </script>
      <script>
      var _paq = window._paq = window._paq || [];
      /* tracker methods like "setCustomDimension" should be called before "trackPageView" */
      _paq.push(['trackPageView']);
      _paq.push(['enableLinkTracking']);
      (function() {
        var u="//analytics.vaif.com.br/";
        _paq.push(['setTrackerUrl', u+'matomo.php']);
        _paq.push(['setSiteId', '1']);
        var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
        g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
      })();
    </script>
    <noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id=752550821217294&ev=PageView&noscript=1"
    /></noscript>
     <link rel="icon" href="/img/favicon/favicon.ico" sizes="any" type="image/x-icon">
    <link rel="icon" href="/img/favicon/favicon-16x16.png" sizes="16x16" type="image/png">
    <link rel="icon" href="/img/favicon/favicon-32x32.png" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="/img/favicon/apple-touch-icon.png">
    <link rel="icon" href="/img/favicon/android-chrome-192x192.png" sizes="192x192" type="image/png">
    <link rel="icon" href="/img/favicon/android-chrome-512x512.png" sizes="512x512" type="image/png">
    <link rel="manifest" href="/img/favicon/site.webmanifest">
    <style>
        :root {
            --gold: #D4B04C; 
            --bg-dark: #0A0A0A;
            --bg-card: #121212;
            --text-main: rgb(242, 237, 228);
            --text-muted: rgb(160, 154, 142);
            --border-color: #222222;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            scroll-behavior: smooth;
        }

        body {
            background-color: var(--bg-dark);
            color: var(--text-main);
            font-family: 'Montserrat', sans-serif;
            line-height: 1.6;
            overflow-x: hidden;
            background-image: radial-gradient(circle at 80% 20%, rgba(212, 176, 76, 0.05), transparent 40%);
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 24px;
        }

        .fade-in-up {
            opacity: 0;
            transform: translateY(40px);
            animation: fadeInUp 1.2s cubic-bezier(0.165, 0.84, 0.44, 1) forwards;
        }

        .delay-1 { animation-delay: 0.2s; }
        .delay-2 { animation-delay: 0.4s; }
        .delay-3 { animation-delay: 0.6s; }

        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        h1, h2, h3, .serif-font {
            font-family: 'Cormorant Garamond', serif;
            color: var(--text-main);
            font-weight: 600;
        }

        strong {
            color: var(--text-main);
            font-weight: 600;
        }

        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            width: 100%;
            padding: 80px 24px;
            background-image: 
                linear-gradient(90deg, rgba(10, 10, 10, 0.98) 0%, rgba(10, 10, 10, 0.9) 45%, rgba(10, 10, 10, 0.6) 100%),
                url('https://d2xsxph8kpxj0f.cloudfront.net/310519663486917648/irrCoUbQoV6yC8GdYKjsD9/hero-bg-FNevDH6u7dQ5qmXMetACwE.webp');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        .hero-content {
            max-width: 650px;
            margin-left: max(0px, calc((100vw - 1200px) / 2));
        }

        .hero-label {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 30px;
            display: block;
            font-family: 'Montserrat', sans-serif;
        }

        .hero-title {
            font-size: clamp(3rem, 6vw, 4.5rem);
            font-weight: 600;
            line-height: 1.1;
            margin-bottom: 40px;
        }

        .hero-title span {
            color: var(--gold);
            font-style: italic;
        }

        .hero-divider {
            display: flex;
            align-items: center;
            margin: 40px 0;
        }

        .hero-divider::before, .hero-divider::after {
            content: '';
            flex-grow: 0;
            width: 40px;
            height: 1px;
            background-color: var(--border-color);
        }

        .diamond {
            width: 6px;
            height: 6px;
            background-color: var(--gold);
            transform: rotate(45deg);
            margin: 0 15px;
        }

        .hero-subtitle {
            font-size: 15px;
            font-weight: 400;
            color: var(--text-muted);
            margin-bottom: 40px;
            line-height: 1.8;
            max-width: 500px;
        }

        .hero-links {
            display: flex;
            flex-direction: column;
            gap: 20px;
            align-items: flex-start;
        }

        .small-text {
            font-size: 10px;
            color: var(--text-muted);
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 16px 32px;
            background-color: var(--gold);
            color: #000;
            border: none;
            font-family: 'Montserrat', sans-serif;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            width: 100%;
        }

        .hero-content .btn-primary {
            width: auto;
        }

        .btn-primary:hover {
            background-color: #E5C35E;
            transform: translateY(-2px);
        }

        .scroll-indicator {
            position: absolute;
            bottom: 40px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            color: var(--gold);
            cursor: pointer;
            z-index: 10;
            animation: bounce 2s infinite ease-in-out;
        }

        .scroll-indicator span {
            font-family: 'Montserrat', sans-serif;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 4px;
            text-transform: uppercase;
        }

        .scroll-indicator svg {
            width: 14px;
            height: 14px;
            stroke: var(--gold);
            stroke-width: 2.5;
        }

        @keyframes bounce {
            0%, 100% { transform: translate(-50%, 0); }
            50% { transform: translate(-50%, 8px); }
        }

        /* Progresso UI */
        .progress-wrapper {
            background-color: #0d0d0d;
            padding-top: 80px;
            text-align: center;
        }

        .calculator-section {
            padding: 60px 0 100px;
            text-align: center;
            background-color: #0d0d0d;
        }

        .section-header {
            margin-bottom: 60px;
        }

        .section-title {
            font-size: 2.5rem;
            margin: 20px 0;
            display: flex;
            justify-content: center;
            text-align: center;
        }

        .calc-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 50px;
            text-align: left;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 40px;
        }

        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 12px;
        }

        .form-label span {
            color: var(--text-main);
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-prefix {
            position: absolute;
            left: 20px;
            color: var(--gold);
            font-size: 14px;
            font-weight: 600;
        }

        .form-input {
            width: 100%;
            padding: 16px 20px 16px 50px;
            background-color: #1A1A1A;
            border: 1px solid var(--border-color);
            color: var(--text-main);
            font-family: 'Montserrat', sans-serif;
            font-size: 15px;
            transition: border-color 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--gold);
        }

        .form-input::placeholder {
            color: #444;
        }

        .input-hint {
            display: block;
            font-size: 11px;
            color: var(--text-muted);
            opacity: 0.8;
            margin-top: 8px;
        }

        .divider-center {
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 40px 0;
        }

        .divider-center::before, .divider-center::after {
            content: '';
            width: 100%;
            height: 1px;
            background-color: var(--border-color);
        }

        .result-section {
            display: none;
            padding: 20px 0 80px;
            text-align: center;
            background-color: #0d0d0d;
        }

        .result-section.active {
            display: block;
            animation: fadeInUp 1s ease forwards;
        }

        .resultado-texto-intro {
            font-size: 16px;
            color: var(--text-muted);
            margin-bottom: 12px;
            text-align: left;
        }

        .divisor-linha {
            width: 100%;
            height: 1px;
            background-color: var(--border-color);
            margin: 30px 0;
        }

        .valor-gigante {
            color: var(--gold);
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(3rem, 7vw, 5rem);
            font-weight: 700;
            line-height: 1.1;
            margin: 10px 0;
            text-shadow: 0 0 40px rgba(212, 176, 76, 0.25);
        }

        .promessa-box {
            border: 1px solid rgba(212, 176, 76, 0.15);
            background: rgba(212, 176, 76, 0.03);
            padding: 30px;
            margin: 40px 0;
            text-align: left;
        }

        .promessa-label {
            color: var(--gold);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            display: block;
            margin-bottom: 15px;
        }

        .promessa-box p {
            color: var(--text-muted);
            font-size: 15px;
            line-height: 1.6;
        }

        #potencialValueText {
             color: var(--gold);
        }

        .locked-action {
            position: relative;
            padding: 40px 0;
            margin: 30px 0;
            display: flex;
            justify-content: center;
            align-items: center;
            background: repeating-linear-gradient(
                180deg,
                rgba(242, 237, 228, 0.02) 0px,
                rgba(242, 237, 228, 0.02) 10px,
                transparent 10px,
                transparent 22px
            );
        }

        .locked-action::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(180deg, var(--bg-card) 0%, transparent 20%, transparent 80%, var(--bg-card) 100%);
            pointer-events: none;
        }

        .locked-box {
            position: relative;
            z-index: 2;
            border: 1px solid rgba(212, 176, 76, 0.3);
            background: #0A0A0A;
            padding: 20px 30px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }

        .locked-box span {
            color: var(--gold);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        footer {
            text-align: center;
            padding: 60px 20px;
            border-top: 1px solid var(--border-color);
        }

        /* ─── Trusted By Banner (Marquee) ─── */
        .trusted-section {
            padding: 0px 0 60px;
            text-align: center;
            overflow: hidden;
        }

        .trusted-label {
            font-family: 'Montserrat', sans-serif;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 35px;
        }

        .marquee-wrap {
            overflow: hidden;
            mask-image: linear-gradient(90deg, transparent, #000 8%, #000 92%, transparent);
            -webkit-mask-image: linear-gradient(90deg, transparent, #000 8%, #000 92%, transparent);
        }

        .marquee-track {
            display: flex;
            align-items: center;
            gap: 60px;
            width: fit-content;
            animation: marqueeScroll 35s linear infinite;
        }

        .marquee-logo {
            height: 32px;
            flex-shrink: 0;
            display: block;
            filter: grayscale(100%) brightness(50%);
            opacity: 0.45;
            transition: all 0.4s ease;
            cursor: pointer;
        }

        .marquee-track a {
            flex-shrink: 0;
            line-height: 0;
            text-decoration: none;
        }

        .marquee-logo:hover {
            filter: grayscale(0%) brightness(110%) drop-shadow(0 0 8px rgba(212, 176, 76, 0.5));
            opacity: 1;
        }

        @keyframes marqueeScroll {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        /* ─── Social Proof 2: Track Record Grid ─── */
        .track-record {
            padding: 30px 0;
            margin: 30px 0 20px;
        }
        .track-record-title {
            font-family: 'Montserrat', sans-serif;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--text-muted);
            text-align: center;
            margin-bottom: 25px;
        }

        .track-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        .track-grid.track-grid-4 {
            grid-template-columns: repeat(4, 1fr);
        }

        .track-item {
            text-align: center;
            padding: 35px 15px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
        }

        .track-number {
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(2.5rem, 5vw, 3.5rem);
            font-weight: 700;
            color: var(--gold);
            line-height: 1.1;
            margin-bottom: 8px;
        }

        .track-label {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .conviction-block {
            background: rgba(212, 176, 76, 0.03);
            border-left: 3px solid var(--gold);
            padding: 20px 24px;
            margin: 30px auto 15px;
            max-width: 550px;
        }

        /* ─── Carrossel de Depoimentos ─── */
        .testimonial-section {
            padding: 80px 0;
            overflow: hidden;
        }

        .carousel-viewport {
            max-width: 780px;
            margin: 40px auto 0;
            position: relative;
            perspective: 1200px;
            overflow: visible;
            padding: 20px 0;
        }

        .carousel-track {
            position: relative;
            min-height: 520px;
        }

        .carousel-slide {
            position: absolute;
            top: 0;
            width: 340px;
            text-align: center;
            opacity: 0;
            pointer-events: none;
            transition: transform 0.9s cubic-bezier(0.25, 0.46, 0.45, 0.94),
                        opacity 0.9s cubic-bezier(0.25, 0.46, 0.45, 0.94),
                        filter 0.9s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }
        .carousel-slide-inactive {
            filter: blur(4px) brightness(0.6);
        }
        .carousel-slide-inactive .carousel-instagram,
        .carousel-slide-inactive .carousel-instagram a,
        .carousel-slide-inactive .carousel-result span {
            color: var(--text-muted) !important;
        }

        .carousel-photo {
            width: 220px;
            height: 220px;
            object-fit: cover;
            border: 2px solid var(--gold);
            margin: 0 auto 14px;
            display: block;
        }

        .carousel-instagram {
            font-family: 'Montserrat', sans-serif;
            font-size: 12px;
            font-weight: 600;
            color: var(--gold);
            letter-spacing: 1px;
            margin-bottom: 12px;
        }

        .carousel-instagram a {
            color: var(--gold);
            text-decoration: none;
            transition: opacity 0.3s;
        }

        .carousel-instagram a:hover {
            opacity: 0.7;
        }

        .carousel-result {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 12px;
        }

        .carousel-result span {
            color: var(--gold);
        }

        .carousel-quote {
            font-family: 'Montserrat', sans-serif;
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.6;
            max-width: 300px;
            margin: 0 auto 20px;
            font-style: italic;
        }

        .carousel-dots {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 25px;
        }

        .carousel-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--border-color);
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .carousel-dot.active {
            background: var(--gold);
            box-shadow: 0 0 10px rgba(212, 176, 76, 0.4);
        }

        .carousel-arrows {
            position: absolute;
            top: 110px;
            left: 0;
            right: 0;
            pointer-events: none;
            z-index: 10;
        }

        .carousel-arrow {
            position: absolute;
            background: none;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            width: 40px;
            height: 40px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            font-size: 16px;
            pointer-events: auto;
        }
        .carousel-arrow.prev {
            left: 0;
        }
        .carousel-arrow.next {
            right: 0;
        }

        .carousel-arrow:hover {
            border-color: var(--gold);
            color: var(--gold);
        }

        /* ─── Post-Booking Limpo (Confirmação) ─── */
        .confirmation-page {
            padding: 30px 0 20px;
            text-align: center;
        }

        .confirmation-checkmark {
            width: 64px;
            height: 64px;
            border: 2px solid var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
        }

        .confirmation-checkmark svg {
            stroke: var(--gold);
        }

        .confirmation-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2.5rem;
            color: var(--text-main);
            font-weight: 700;
            margin-bottom: 16px;
        }

        .confirmation-subtitle {
            font-family: 'Montserrat', sans-serif;
            font-size: 15px;
            color: var(--text-muted);
            line-height: 1.7;
            max-width: 520px;
            margin: 0 auto 40px;
        }

        .confirmation-subtitle strong {
            color: var(--text-main);
            font-weight: 600;
        }

        .confirmation-subtitle .highlight-gold {
            color: var(--gold);
            font-weight: 600;
        }

        .homework-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-top: 2px solid var(--gold);
            padding: 35px 30px;
            max-width: 560px;
            margin: 0 auto 35px;
        }

        .homework-label {
            font-family: 'Montserrat', sans-serif;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 20px;
        }

        .homework-video {
            position: relative;
            cursor: pointer;
        }

        .homework-video img {
            width: 100%;
            display: block;
            border: 1px solid var(--border-color);
        }

        .homework-play {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 56px;
            height: 56px;
            background: var(--gold);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.3s ease;
        }

        .homework-video:hover .homework-play {
            transform: translate(-50%, -50%) scale(1.1);
        }

        .confirmation-footer {
            font-family: 'Montserrat', sans-serif;
            font-size: 11px;
            color: var(--text-muted);
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 15px 22px;
            border: 1px dashed rgba(212, 176, 76, 0.3);
            display: inline-block;
        }

        .confirmation-footer svg {
            vertical-align: middle;
            margin-right: 6px;
            stroke: var(--gold);
        }

        /* Call Outcomes */
        .call-outcomes {
            background: rgba(212, 176, 76, 0.04);
            border: 1px solid rgba(212, 176, 76, 0.12);
            max-width: 560px;
            margin: 0 auto 30px;
            padding: 30px;
            text-align: left;
        }
        .outcomes-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 20px;
            text-align: center;
        }
        .outcomes-list {
            list-style: none;
            padding: 0;
        }
        .outcomes-list li {
            font-family: 'Montserrat', sans-serif;
            font-size: 14px;
            color: var(--text-main);
            line-height: 1.7;
            padding: 8px 0 8px 28px;
            position: relative;
        }
        .outcomes-list li::before {
            content: '';
            position: absolute;
            left: 0;
            top: 14px;
            width: 5px;
            height: 5px;
            background: var(--gold);
            transform: rotate(45deg);
        }

        /* Specialist Card */
        .specialist-card {
            max-width: 560px;
            margin: 0 auto 35px;
            padding: 20px 24px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
        }
        .specialist-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 15px;
        }
        .specialist-row {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .specialist-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 2px solid var(--gold);
            background: #1a1a1a;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gold);
            font-family: 'Montserrat', sans-serif;
            font-size: 13px;
            font-weight: 700;
            flex-shrink: 0;
        }
        .specialist-info {
            text-align: left;
        }
        .specialist-name {
            font-family: 'Montserrat', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 4px;
        }
        .specialist-role {
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.4;
        }

        /* Homework Commitment */
        .homework-commitment {
            font-family: 'Montserrat', sans-serif;
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.7;
            margin-top: 20px;
            padding-top: 18px;
            border-top: 1px solid var(--border-color);
        }
        .homework-commitment span {
            color: var(--gold);
            font-weight: 600;
            font-style: italic;
        }
        .homework-whatsapp-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 14px 28px;
            margin-top: 18px;
            background: #25D366;
            color: #000;
            border: none;
            font-family: 'Montserrat', sans-serif;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .homework-whatsapp-btn:hover {
            background: #22c35e;
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .calc-card { padding: 30px 20px; }
            .hero-title { font-size: 2.5rem; }
            .track-grid { grid-template-columns: 1fr; gap: 15px; }
            .track-grid.track-grid-4 { grid-template-columns: repeat(2, 1fr); }
            .carousel-viewport { max-width: 360px; }
            .carousel-slide { width: 280px; }
            .carousel-track { min-height: 480px; }
            .carousel-photo { width: 160px; height: 160px; }
            .carousel-quote { max-width: 260px; font-size: 11px; }
            .carousel-arrow { width: 32px; height: 32px; font-size: 13px; }
            .carousel-arrows { top: 85px; }
            .marquee-logo { height: 26px; }
            .marquee-track { gap: 40px; }
            .confirmation-title { font-size: 2rem; }
        }

        /* ─── Estilos do Calendário Nativo ─── */
        .success-text {
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            color: var(--text-muted);
            opacity: 0.8;
            line-height: 1.6;
            max-width: 400px;
            margin: 0 auto;
        }

        #nativeCalendarBlock {
            background: linear-gradient(145deg, #111111 0%, #0a0a0a 100%);
            border: 1px solid rgba(212, 176, 76, 0.15);
            border-top: 3px solid var(--gold);
            border-radius: 8px;
            padding: 50px 40px;
            text-align: center;
            margin-top: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.6);
            animation: fadeInUp 0.6s ease forwards;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin: 40px 0;
            text-align: left;
        }

        .calendar-day-col h4 {
            color: var(--gold); 
            font-family: 'Montserrat', sans-serif;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 20px;
            border-bottom: 1px dashed rgba(212, 176, 76, 0.3);
            padding-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .calendar-day-col h4::before {
            content: '';
            display: block;
            width: 6px;
            height: 6px;
            background-color: var(--gold);
            transform: rotate(45deg);
        }

        .time-slot {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            padding: 16px;
            margin-bottom: 12px;
            background: #161616;
            border: 1px solid #2a2a2a;
            color: var(--text-main);
            font-family: 'Montserrat', sans-serif;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border-radius: 6px;
        }

        .time-slot:hover {
            border-color: var(--gold);
            background: rgba(212, 176, 76, 0.08);
            transform: translateY(-2px);
        }

        .time-slot.selected {
            background: var(--gold);
            color: #000;
            font-weight: 700;
            border-color: var(--gold);
            box-shadow: 0 0 20px rgba(212, 176, 76, 0.3);
            transform: translateY(-2px);
        }

        .time-slot:disabled {
            opacity: 1;
            cursor: not-allowed;
            background: #0a0a0a;
            border-color: #1a1a1a;
            color: #444;
            text-decoration: none;
            transform: none;
            box-shadow: none;
        }

        .slot-status {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-left: 10px;
            color: #555;
        }
        .skip-action {
            display: inline-block;
            margin-top: 25px;
            color: var(--text-muted);
            font-size: 12px;
            text-decoration: underline;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .skip-action:hover {
            color: var(--text-main);
        }

        @media (max-width: 600px) {
            .calendar-grid { grid-template-columns: 1fr; }
        }

        .ebook-premium-box {
            background: linear-gradient(145deg, #111111 0%, #0a0a0a 100%);
            border: 1px solid rgba(212, 176, 76, 0.15);
            border-top: 3px solid var(--gold);
            border-radius: 8px;
            padding: 50px 30px;
            text-align: center;
            margin-top: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.6);
            animation: fadeInUp 0.6s ease forwards;
        }

        .ebook-premium-box .funil-title {
            font-size: 2.5rem;
            color: var(--text-main);
            margin-bottom: 5px;
        }

        .ebook-premium-box .highlight-gold {
            color: var(--gold);
            font-style: italic;
        }

        /* ─── Analyzing Overlay ─── */
        .analyzing-content {
            text-align: center;
            padding: 50px 20px;
        }
        .analyzing-spinner {
            width: 44px;
            height: 44px;
            border: 2px solid var(--border-color);
            border-top-color: var(--gold);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .analyzing-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.4rem;
            color: var(--text-main);
        }
        .analyzing-detail {
            font-family: 'Montserrat', sans-serif;
            font-size: 12px;
            color: var(--text-muted);
        }
        .analyzing-detail strong {
            color: var(--gold);
        }
        .analyzing-status {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.8rem;
            color: var(--gold);
            font-weight: 600;
        }

        .ebook-paragraph {
            color: #d1cbc1;
            font-size: 16px;
            line-height: 1.7;
            max-width: 600px;
            margin: 0 auto 20px;
        }

        .coupon-card {
            background: rgba(212, 176, 76, 0.05);
            border: 2px dashed var(--gold);
            border-radius: 8px;
            padding: 25px;
            display: inline-block;
            margin: 35px 0;
            position: relative;
            min-width: 280px;
        }

        .coupon-label {
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            background: #111;
            padding: 0 15px;
            font-size: 11px;
            color: var(--gold);
            letter-spacing: 2px;
            text-transform: uppercase;
            font-weight: 700;
        }

        .coupon-code {
            font-family: 'Montserrat', sans-serif;
            font-size: 28px;
            font-weight: 800;
            letter-spacing: 5px;
            color: var(--text-main);
            text-shadow: 0 0 15px rgba(212, 176, 76, 0.2);
        }
    </style>
</head>
<body>

   <section class="hero">
        <div class="hero-content fade-in-up">
            <span class="hero-label">Exclusivo para tatuadores de realismo e preto & cinza</span>
            <h1 class="hero-title">
                Quanto dinheiro você perde <br>
                <span>negociando orçamento</span> <br>
                no WhatsApp todo mês?
            </h1>
            
            <div class="hero-divider fade-in-up delay-1">
                <div class="diamond"></div>
            </div>

            <p class="hero-subtitle fade-in-up delay-2">
                Você já fatura 5 dígitos com realismo. Mas enquanto você negocia desconto no direct, outro tatuador do seu nível está fechando 3 sessões de <strong>R$ 2.000 cada</strong> — com processo, não com talento.
            </p>

            <div class="hero-links fade-in-up delay-3">
                <button class="btn-primary" onclick="scrollToCalculator()">Calcular meu lucro oculto &darr;</button>
                <span class="small-text">Diagnóstico Gratuito • Sem Compromisso</span>
            </div>
        </div>

        <div class="scroll-indicator" onclick="scrollToCalculator()">
            <span>Role</span>
            <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 5v14M19 12l-7 7-7-7"/>
            </svg>
        </div>
    </section>


    <!-- OTIMIZAÇÃO: Barra de Progresso Global do Funil -->
    <div class="progress-wrapper" id="progressWrapper">
        <div class="container">
            <div style="max-width: 500px; margin: 0 auto;">
                <span id="progressLabel" style="font-size: 10px; color: var(--gold); text-transform: uppercase; letter-spacing: 2px; margin-bottom: 12px; display: block; text-align: center; font-weight: 700;">Passo 1 de 2: Diagnóstico Inicial (50%)</span>
                <div style="width: 100%; background-color: #222; border-radius: 4px; height: 6px; overflow: hidden;">
                    <div id="progressBar" style="height: 100%; background-color: var(--gold); width: 50%; transition: width 1s cubic-bezier(0.165, 0.84, 0.44, 1);"></div>
                </div>
            </div>
        </div>
    </div>

    <section class="calculator-section" id="calculator">
        <div class="container">
            <div class="section-header">
                <span class="hero-label" style="margin-bottom: 15px; justify-content: center; display: flex;">Diagnóstico Financeiro</span>
                <h2 class="section-title">A Calculadora do Lucro Oculto</h2>
                <div class="divider-center" style="max-width: 200px; margin: 20px auto;">
                    <div class="diamond"></div>
                </div>
                <p style="color: var(--text-muted); font-size: 13px; max-width: 400px; margin: 0 auto;">Preencha os campos abaixo com honestidade. O diagnóstico é preciso apenas com dados reais.</p>
            </div>

            <!-- ─── Trusted By: Marquee ─── -->
            <section class="trusted-section">
                <div class="container fade-in-up">
                    <p class="trusted-label">Acelerando estúdios de alto padrão em todo o Brasil</p>
                </div>
                <div class="marquee-wrap">
                    <div class="marquee-track">
                        <a href="https://www.instagram.com/jhonatanmasters" target="_blank" rel="noopener noreferrer" title="Ver estúdio Jhonatan Masters"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=JHONATAN+MASTERS&font=montserrat" alt="Jhonatan Masters"></a>
                        <a href="https://www.instagram.com/rsilvatattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Rodrigo Silva"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=RODRIGO+SILVA&font=montserrat" alt="Rodrigo Silva"></a>
                        <a href="https://www.instagram.com/sergiomoraestattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Sergio Moraes"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=SERGIO+MORAES&font=montserrat" alt="Sergio Moraes"></a>
                        <a href="https://www.instagram.com/Kleberocker" target="_blank" rel="noopener noreferrer" title="Ver estúdio Kleber Rocker"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=KLEBER+ROCKER&font=montserrat" alt="Kleber Rocker"></a>
                        <a href="https://www.instagram.com/Maikbuenotattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Bueno Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=BUENO+TATTOO&font=montserrat" alt="Bueno Tattoo"></a>
                        <a href="https://www.instagram.com/dinho_tattoo091" target="_blank" rel="noopener noreferrer" title="Ver estúdio Dinho Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=DINHO+TATTOO&font=montserrat" alt="Dinho Tattoo"></a>
                        <!-- Duplicata para loop infinito -->
                        <a href="https://www.instagram.com/jhonatanmasters" target="_blank" rel="noopener noreferrer" title="Ver estúdio Jhonatan Masters"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=JHONATAN+MASTERS&font=montserrat" alt="Jhonatan Masters"></a>
                        <a href="https://www.instagram.com/rsilvatattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Rodrigo Silva"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=RODRIGO+SILVA&font=montserrat" alt="Rodrigo Silva"></a>
                        <a href="https://www.instagram.com/sergiomoraestattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Sergio Moraes"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=SERGIO+MORAES&font=montserrat" alt="Sergio Moraes"></a>
                        <a href="https://www.instagram.com/Kleberocker" target="_blank" rel="noopener noreferrer" title="Ver estúdio Kleber Rocker"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=KLEBER+ROCKER&font=montserrat" alt="Kleber Rocker"></a>
                        <a href="https://www.instagram.com/Maikbuenotattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Bueno Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=BUENO+TATTOO&font=montserrat" alt="Bueno Tattoo"></a>
                        <a href="https://www.instagram.com/dinho_tattoo091" target="_blank" rel="noopener noreferrer" title="Ver estúdio Dinho Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=DINHO+TATTOO&font=montserrat" alt="Dinho Tattoo"></a>
                    </div>
                </div>
            </section>

            <div class="calc-card">
                <form id="calcForm" onsubmit="handleCalculate(event)">
                    
                    <div class="form-group">
                        <label class="form-label"><span>01 &mdash;</span> Faturamento Bruto Mensal Atual</label>
                        <div class="input-wrapper">
                            <span class="input-prefix">R$</span>
                            <input type="text" inputmode="numeric" class="form-input" name="faturamento" placeholder="Ex: 15.000" required>
                        </div>
                        <span class="input-hint">Quanto você fatura em média por mês</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><span>02 &mdash;</span> Valor Médio por Sessão de Realismo</label>
                        <div class="input-wrapper">
                            <span class="input-prefix">R$</span>
                            <input type="text" inputmode="numeric" class="form-input" name="ticket" placeholder="Ex: 1.500" required>
                        </div>
                        <span class="input-hint">Quanto você cobra em média por sessão fechada</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><span>03 &mdash;</span> Sessões de Tatuagem por Mês</label>
                        <div class="input-wrapper">
                            <span class="input-prefix">#</span>
                            <input type="number" class="form-input" name="sessoes" placeholder="Ex: 10" required>
                        </div>
                        <span class="input-hint">Quantas tatuagens você entrega por mês</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><span>04 &mdash;</span> Horas Negociando Orçamento no WhatsApp</label>
                        <div class="input-wrapper">
                            <span class="input-prefix">h</span>
                            <input type="text" inputmode="numeric" class="form-input" name="horas_admin" placeholder="Ex: 3" required>
                        </div>
                        <span class="input-hint">Tempo diário respondendo "quanto cobra pra fechar um braço?"</span>
                    </div>

                    <div class="divider-center">
                        <div class="diamond"></div>
                    </div>

                    <button type="submit" class="btn-primary">Ver Diagnóstico &rarr;</button>
                    <p style="text-align: center; font-size: 11px; color: var(--text-muted); opacity: 0.7; margin-top: 15px;">Seus dados são confidenciais e não serão compartilhados.</p>
                </form>
            </div>
        </div>
    </section>

    <section class="result-section" id="resultSection">
        <div class="container">
            <div class="section-header">
                <span class="hero-label" style="margin-bottom: 15px; justify-content: center; display: flex;">Seu Diagnóstico</span>
                <h2 class="section-title">O Custo Real do Seu Tempo</h2>
                <div class="divider-center" style="max-width: 200px; margin: 20px auto;">
                    <div class="diamond"></div>
                </div>
            </div>

            <!-- ─── Trusted By: Marquee ─── -->
            <section class="trusted-section">
                <div class="container fade-in-up">
                    <p class="trusted-label">Acelerando estúdios de alto padrão em todo o Brasil</p>
                </div>
                <div class="marquee-wrap">
                    <div class="marquee-track">
                        <a href="https://www.instagram.com/jhonatanmasters" target="_blank" rel="noopener noreferrer" title="Ver estúdio Jhonatan Masters"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=JHONATAN+MASTERS&font=montserrat" alt="Jhonatan Masters"></a>
                        <a href="https://www.instagram.com/rsilvatattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Rodrigo Silva"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=RODRIGO+SILVA&font=montserrat" alt="Rodrigo Silva"></a>
                        <a href="https://www.instagram.com/sergiomoraestattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Sergio Moraes"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=SERGIO+MORAES&font=montserrat" alt="Sergio Moraes"></a>
                        <a href="https://www.instagram.com/Kleberocker" target="_blank" rel="noopener noreferrer" title="Ver estúdio Kleber Rocker"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=KLEBER+ROCKER&font=montserrat" alt="Kleber Rocker"></a>
                        <a href="https://www.instagram.com/Maikbuenotattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Bueno Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=BUENO+TATTOO&font=montserrat" alt="Bueno Tattoo"></a>
                        <a href="https://www.instagram.com/dinho_tattoo091" target="_blank" rel="noopener noreferrer" title="Ver estúdio Dinho Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=DINHO+TATTOO&font=montserrat" alt="Dinho Tattoo"></a>
                        <!-- Duplicata para loop infinito -->
                        <a href="https://www.instagram.com/jhonatanmasters" target="_blank" rel="noopener noreferrer" title="Ver estúdio Jhonatan Masters"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=JHONATAN+MASTERS&font=montserrat" alt="Jhonatan Masters"></a>
                        <a href="https://www.instagram.com/rsilvatattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Rodrigo Silva"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=RODRIGO+SILVA&font=montserrat" alt="Rodrigo Silva"></a>
                        <a href="https://www.instagram.com/sergiomoraestattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Sergio Moraes"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=SERGIO+MORAES&font=montserrat" alt="Sergio Moraes"></a>
                        <a href="https://www.instagram.com/Kleberocker" target="_blank" rel="noopener noreferrer" title="Ver estúdio Kleber Rocker"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=KLEBER+ROCKER&font=montserrat" alt="Kleber Rocker"></a>
                        <a href="https://www.instagram.com/Maikbuenotattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Bueno Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=BUENO+TATTOO&font=montserrat" alt="Bueno Tattoo"></a>
                        <a href="https://www.instagram.com/dinho_tattoo091" target="_blank" rel="noopener noreferrer" title="Ver estúdio Dinho Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=DINHO+TATTOO&font=montserrat" alt="Dinho Tattoo"></a>
                    </div>
                </div>
            </section>


            <div class="calc-card" style="text-align: center; padding-top: 60px;">
                
                <p class="resultado-texto-intro">Você passa <strong id="horasMesValue">0 horas por mês</strong> negociando orçamento em vez de tatuando.</p>
                <p class="resultado-texto-intro">O seu <strong>custo real de produção</strong> hoje é de:</p>

                <div class="divisor-linha"></div>

                <p class="form-label" style="text-align: center; letter-spacing: 3px;">Prejuízo Mensal Estimado</p>
                <div class="valor-gigante" id="prejuizoValue">R$ 0,00</div>
                <p style="color: var(--text-muted); opacity: 0.8; font-size: 12px; margin-bottom: 40px;">o custo de ser secretário do próprio estúdio</p>

                <div class="promessa-box">
                    <span class="promessa-label">Sua Transformação</span>
                    <p>Com um sistema de captação para realismo, você pode faturar <strong id="potencialValueText">R$ 0,00</strong> <strong>com a mesma agulha e as mesmas horas.</strong></p>
                </div>

                <div class="locked-action">
                    <div class="locked-box">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <span>Seu Plano de Captação High-Ticket está Bloqueado</span>
                    </div>
                </div>

                <!-- ─── Social Proof: Track Record Grid ─── -->
                <div class="track-record fade-in-up">
                    <p class="track-record-title">Nossos números em 8 anos de mercado</p>
                    <div class="track-grid track-grid-4">
                        <div class="track-item">
                            <div class="track-number">+8</div>
                            <div class="track-label">Anos no Mercado de Tatuagem</div>
                        </div>
                        <div class="track-item">
                            <div class="track-number">+20M</div>
                            <div class="track-label">Gerados para Estúdios de Realismo</div>
                        </div>
                        <div class="track-item">
                            <div class="track-number">+110</div>
                            <div class="track-label">Estúdios de Realismo e Preto & Cinza Escalados</div>
                        </div>
                        <div class="track-item">
                            <div class="track-number">+4.7M</div>
                            <div class="track-label">Recuperados em Perda de Faturamento</div>
                        </div>
                    </div>
                </div>

                <!-- OTIMIZAÇÃO: Bloco de Convicção -->
                <div class="conviction-block fade-in-up delay-1">
                    <p style="font-size: 13px; color: var(--text-main); max-width: 520px; margin: 0 auto 20px; line-height: 1.8;">
                        Se você fatura acima de <strong style="color: var(--gold);">R$ 7.000 com realismo</strong>, o que está travando seu crescimento não é sua técnica — é seu sistema de captação. Enquanto você negocia desconto no direct, outro tatuador do seu nível está fechando 3 sessões de R$ 2.000 cada. <strong>A diferença não é talento. É processo.</strong>
                    </p>
                </div>

                <!-- OTIMIZAÇÃO: Gatilho de Curiosidade no Texto -->
                <p id="instrucaoForm" style="text-align: center; font-size: 15px; color: var(--text-muted); margin: 40px auto; max-width: 550px; line-height: 1.6;">
                    Nosso especialista analisou seu prejuízo de <strong style="color: var(--gold);" id="prejuizoCopyValue">R$ 0,00</strong>. Preencha para destravar o plano exato de como recuperar esse valor em <strong style="color: var(--text-main);">30 dias</strong>.
                </p>

                <form id="leadForm" onsubmit="handleLeadSubmit(event)" style="text-align: left;">
                    <div class="form-group">
                        <label class="form-label">Nome Completo</label>
                        <input type="text" class="form-input" name="nome" placeholder="Seu nome" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">WhatsApp</label>
                        <input type="tel" class="form-input" name="whatsapp" placeholder="(11) 99999-9999" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">@ Do Instagram</label>
                        <div class="input-wrapper">
                            <span class="input-prefix" style="color: var(--gold);">@</span>
                            <input type="text" class="form-input" name="instagram" placeholder="seu.perfil" required>
                        </div>
                    </div>
                    
                    <div class="divider-center">
                        <div class="diamond"></div>
                    </div>

                    <!-- OTIMIZAÇÃO: Prova Social Discreta -->
                    <p style="text-align: center; font-size: 12px; color: var(--text-muted); margin-bottom: 25px; font-style: italic;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 5px; margin-top: -2px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        Junte-se aos mais de 100 estúdios de alto padrão que já resolveram esse problema.
                    </p>

                    <button type="submit" class="btn-primary" id="submitBtn">Quero o Plano de Escala &rarr;</button>
                    <p style="text-align: center; font-size: 11px; color: var(--text-muted); opacity: 0.7; margin-top: 20px;">Sem spam. Apenas conteúdo de alto valor para artistas sérios.</p>
                </form>

                <div id="analyzingOverlay" style="display: none;">
                    <div class="analyzing-content">
                        <div class="analyzing-spinner"></div>
                        <p class="analyzing-title" style="margin-top: 24px;">Analisando perfil de <strong id="analyzingName">[Nome]</strong></p>
                        <p class="analyzing-detail" style="margin-top: 8px;">Faturamento: <strong id="analyzingFaturamento">R$ 0,00</strong> • Prejuízo mensal: <strong id="analyzingPrejuizo">R$ 0,00</strong></p>
                        <p class="analyzing-status" style="margin-top: 30px; opacity: 0; transition: opacity 0.4s ease;" id="analyzingStatus">Perfil qualificado ✓</p>
                    </div>
                </div>

                <div id="nativeCalendarBlock" style="display: none;">
                    <h3 class="funil-title" style="font-size: 2.5rem; margin-bottom: 10px;">Seu estúdio de realismo está <span style="color: var(--gold); font-style: italic;">pronto para escalar.</span></h3>
                    <p class="success-text" style="margin-bottom: 10px; max-width: 500px; margin-left: auto; margin-right: auto; font-size: 16px;">
                        Você tem a estrutura exata que escalamos. Abrimos um acesso direto à agenda do nosso especialista em captação de clientes para tatuadores.
                    </p>
                    
                    <div class="calendar-grid" id="calendarContainer">
                        </div>

                    <p style="text-align: center; font-size: 11px; color: var(--text-muted); margin-top: 15px;">
                        Esses horários são reservados exclusivamente para estúdios de alto padrão.<br>
                        Após o agendamento, seu horário está confirmado em até 2 minutos.
                    </p>
                    
                    <button id="btnConfirmTime" class="btn-primary" style="display: none; max-width: 350px; margin: 0 auto 20px;" onclick="confirmarAgendamento()">
                        CONFIRMAR REUNIÃO &rarr;
                    </button>
                    
                    <div style="text-align: center; margin-top: 15px;">
                        <span class="skip-action" onclick="pularAgendamento()">Prefiro combinar o horário depois pelo WhatsApp</span>
                    </div>
                </div>

                <div id="ebookBlock" class="ebook-premium-box" style="display: none;">
                    <h3 class="funil-title">Diagnóstico <span class="highlight-gold">Concluído!</span></h3>
                    
                    <p class="ebook-paragraph" style="margin-top: 30px;">
                        Analisamos o seu perfil, <strong id="ebookLeadNome" style="color: #fff;"></strong>. No seu estágio atual, o caminho mais rápido para quebrar o teto do seu estúdio e <strong>atingir os R$ 10.000,00 mensais</strong> é estruturar a sua base de captação.
                    </p>
                    
                    <p class="ebook-paragraph">
                        Como você concluiu nossa análise, você acabou de desbloquear um <strong>presente exclusivo</strong> para ter acesso ao nosso manual prático:
                    </p>
                    
                    <div class="coupon-card">
                        <span class="coupon-label">Seu Cupom Ativo</span>
                        <div class="coupon-code">TATTOO10K</div>
                    </div>
                    
                    <a href="https://ebook.vaif.com.br/tatuador-10k" target="_blank" class="btn-primary" style="max-width: 420px; margin: 0 auto; display: block; font-size: 13px; padding: 20px 32px;" onclick="trackEbookClick()">
                        📖 GARANTIR MANUAL COM DESCONTO &rarr;
                    </a>
                    
                    <p style="font-size: 11px; color: var(--text-muted); margin-top: 15px; text-transform: uppercase; letter-spacing: 1px;">
                        Acesso Imediato • Pagamento Único
                    </p>
                </div>
            
                <div id="successMessage" style="display: none;">
                    <div class="confirmation-page">

                        <!-- Grande Checkmark Dourado -->
                        <div class="confirmation-checkmark">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                        </div>

                        <!-- Título -->
                        <h3 class="confirmation-title" id="confirmationTitle">Horário Confirmado!</h3>

                        <!-- Texto Principal -->
                        <p class="confirmation-subtitle" id="confirmationSubtitle">
                            <strong id="confNamePlaceholder">[Nome]</strong>, sua reunião está marcada para <strong id="confDateTimePlaceholder">[Data] às [Hora]</strong>. Você já investiu 3 minutos no seu diagnóstico — essa call de <strong>30 minutos</strong> é o passo final para recuperar os <span class="highlight-gold" id="confLossPlaceholder">R$ 9.750,00</span> que estão escapando do seu estúdio <strong>este mês</strong>.
                        </p>

                        <!-- O que vai sair da call -->
                        <div class="call-outcomes">
                            <p class="outcomes-label">Esta reunião foi desenhada para te entregar:</p>
                            <ul class="outcomes-list">
                                <li>Uma análise completa do seu estúdio — faturamento, ticket médio, gargalos de venda e pontos cegos que estão travando seu crescimento</li>
                                <li>Cases reais de tatuadores com o mesmo perfil que o seu que escalaram o faturamento — e exatamente como chegaram lá</li>
                                <li>Scripts de vendas validados por dezenas de tatuadores + direcionamento de marketing (orgânico ou pago) sob medida para o seu caso</li>
                            </ul>
                        </div>

                        <!-- Especialista -->
                        <div class="specialist-card">
                            <p class="specialist-label">Quem vai te atender:</p>
                            <div class="specialist-row">
                                <div class="specialist-avatar">DH</div>
                                <div class="specialist-info">
                                    <div class="specialist-name">Daniel</div>
                                    <div class="specialist-role">Especialista em Captação para Estúdios de Tatuagem</div>
                                </div>
                            </div>
                        </div>

                        <!-- Card: Dever de Casa com Micro-Compromisso -->
                        <div class="homework-card">
                            <p class="homework-label">Passo Obrigatório Antes da Reunião:</p>
                            <div class="homework-video">
                                <img src="https://placehold.co/560x315/1a1a1a/555?text=Assista+ao+V%C3%ADdeo+de+Aquecimento&font=montserrat" alt="Vídeo de aquecimento">
                                <div class="homework-play">
                                    <svg width="18" height="20" viewBox="0 0 24 24" fill="#000"><polygon points="5,3 19,12 5,21"></polygon></svg>
                                </div>
                            </div>
                            <p class="homework-commitment">Após assistir, responda no WhatsApp: <span>"Hoje o seu maior desafio no estúdio é lotar a agenda com frequência ou conseguir atrair clientes melhores?"</span></p>
                            <a href="https://wa.me/5521999553136?" target="_blank" rel="noopener noreferrer" class="homework-whatsapp-btn">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px;"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
                                Responder no WhatsApp
                            </a>
                        </div>

                        <!-- Aviso Footer -->
                        <div class="confirmation-footer">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
                            </svg>
                            O link da sala será enviado no seu WhatsApp 5 minutos antes da reunião
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ─── Carrossel de Depoimentos ─── -->
    <section class="testimonial-section">
        <div class="container">
            <div class="section-header fade-in-up">
                <span class="hero-label" style="margin-bottom: 15px; justify-content: center; display: flex;">Resultados</span>
                <h2 class="section-title">Conheça alguns dos nossos parceiros</h2>
                <div class="divider-center" style="max-width: 200px; margin: 20px auto;">
                    <div class="diamond"></div>
                </div>
            </div>

            <div class="carousel-viewport fade-in-up delay-1">
                <div class="carousel-track" id="carouselTrack">

                    <!-- Slide 1 -->
                    <div class="carousel-slide">
                        <img class="carousel-photo" src="/img/guitattoo_resultado.jpeg" alt="Gui Tattoo">
                        <p class="carousel-instagram"><a href="https://instagram.com/Guitattoobh" target="_blank" rel="noopener noreferrer">@Guitattoobh</a></p>
                        <div class="carousel-result">De <span>R$ 7k</span> para <span>R$ 20k</span> em 60 dias, com a agenda sempre lotada</div>
                        <p class="carousel-quote">Conheci o trabalho da VAIF em um momento onde a agenda estava vazia, não conseguia subir o preço das minhas tatuagens, estava sem perspectiva. Hoje, quase 2 anos depois, continuo o trabalho com eles e graças a Deus com a agenda lotada.</p>
                    </div>

                    <!-- Slide 2 (card do meio → inicial) -->
                    <div class="carousel-slide">
                        <img class="carousel-photo" src="/img/rsilva_resultado.png" alt="Rodrigo Silva">
                        <p class="carousel-instagram"><a href="https://instagram.com/rsilvatattoo" target="_blank" rel="noopener noreferrer">@rsilvatattoo</a></p>
                        <div class="carousel-result">De <span>R$ 9k</span> para <span>R$ 38k</span> em 30 dias</div>
                        <p class="carousel-quote">A VAIF assumiu quando abri meu estúdio. Na época, tinha acabado de me mudar para uma cidade nova, sem clientes e precisava de capital. No primeiro mês já vi o meu faturamento sair de 9 mil reais para 38 mil reais. Desde então a agenda fica lotada com pelo menos um mês de antecedência.</p>
                    </div>

                    <!-- Slide 3 -->
                    <div class="carousel-slide">
                        <img class="carousel-photo" src="/img/dinho_resultado.png" alt="Dinho Tattoo">
                        <p class="carousel-instagram"><a href="https://instagram.com/dinho_tattoo091" target="_blank" rel="noopener noreferrer">@dinho_tattoo091</a></p>
                        <div class="carousel-result">De <span>R$ 15k</span> para <span>R$ 48k</span> em 75 dias</div>
                        <p class="carousel-quote">Já trabalho com a VAIF faz 4 anos e meio, já trabalhei com outros profissionais, inclusive famosos no meio do marketing, e nenhum deles me trouxe tantos resultados quanto a VAIF.</p>
                    </div>

                </div>

                <!-- Setas -->
                <div class="carousel-arrows">
                    <button class="carousel-arrow prev" onclick="moverCarrossel(-1)" aria-label="Anterior">&larr;</button>
                    <button class="carousel-arrow next" onclick="moverCarrossel(1)" aria-label="Próximo">&rarr;</button>
                </div>
            </div>
        </div>
    </section>

    <footer>
        <div class="container">
            <div class="diamond" style="margin: 0 auto 30px;"></div>
            <p class="small-text" style="margin-bottom: 10px;">Desenvolvido para especialistas. Suas informações estão seguras.</p>
            <p class="small-text" style="color: var(--text-muted); opacity: 0.6;">&copy; 2026 • Todos os direitos reservados</p>
        </div>
    </footer>

    <script>
        function scrollToCalculator() {
            document.getElementById('progressWrapper').scrollIntoView({ behavior: 'smooth' });
        }

        function animateValue(element, start, end, duration) {
            let startTimestamp = null;
            const step = (timestamp) => {
                if (!startTimestamp) startTimestamp = timestamp;
                const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                const easeProgress = 1 - Math.pow(1 - progress, 3);
                const current = Math.floor(easeProgress * (end - start) + start);
                
                element.textContent = 'R$ ' + current.toLocaleString('pt-BR') + ',00';
                
                if (progress < 1) {
                    window.requestAnimationFrame(step);
                } else {
                    element.textContent = 'R$ ' + end.toLocaleString('pt-BR') + ',00';
                }
            };
            window.requestAnimationFrame(step);
        }

        function parseBrNumber(val) {
            if (!val) return 0;
            let cleanVal = val.toString().replace(/\./g, '').replace(',', '.');
            return parseFloat(cleanVal) || 0;
        }

        function handleCalculate(event) {
            event.preventDefault();

            if (typeof _paq !== 'undefined') {
                _paq.push(['trackEvent', 'Calculadora', 'Clique_Calcular', 'Viu_o_Prejuizo']);
            }
            
            const form = event.target;
            
            const faturamento = parseBrNumber(form.faturamento.value);
            const ticket = parseBrNumber(form.ticket.value);
            const sessoes = parseBrNumber(form.sessoes.value);
            const horas_admin = parseBrNumber(form.horas_admin.value);

            const valor_hora = faturamento / (sessoes * 8); 
            const horasTotaisMes = horas_admin * 26;
            const prejuizo_mensal = Math.round(horasTotaisMes * valor_hora);
            const potencial_lucro = Math.round(faturamento + (prejuizo_mensal * 0.7));

            window.calcData = {
                faturamento, ticket, sessoes, horas_admin,
                valor_hora: Math.round(valor_hora),
                horas_secretario: Math.round(horasTotaisMes),
                prejuizo_mensal, potencial_lucro
            };

            document.getElementById('horasMesValue').textContent = horasTotaisMes + ' horas por mês';
            document.getElementById('potencialValueText').textContent = 'R$ ' + potencial_lucro.toLocaleString('pt-BR') + ',00';
            
            // Injeção do valor gerado no texto persuasivo do formulário
            document.getElementById('prejuizoCopyValue').textContent = 'R$ ' + prejuizo_mensal.toLocaleString('pt-BR') + ',00';

            // Atualização da Barra de Progresso
            document.getElementById('progressBar').style.width = '80%';
            document.getElementById('progressLabel').textContent = 'Passo 2 de 2: Liberação do Plano Estratégico (80%)';

            const calcSec = document.getElementById('calculator');
            const resultSec = document.getElementById('resultSection');
            
            calcSec.style.display = 'none';
            resultSec.classList.add('active');
            
            setTimeout(() => {
                document.getElementById('progressWrapper').scrollIntoView({ behavior: 'smooth', block: 'start' });
                animateValue(document.getElementById('prejuizoValue'), 0, prejuizo_mensal, 2500);
            }, 100);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const instagramInput = document.querySelector('input[name="instagram"]');
            const whatsappInput = document.querySelector('input[name="whatsapp"]');

            if (instagramInput) {
                instagramInput.addEventListener('input', function(e) {
                    this.value = this.value.replace(/[@\s]/g, '');
                });
            }

            if (whatsappInput) {
                whatsappInput.addEventListener('input', function(e) {
                    let v = this.value.replace(/\D/g, '');
                    if (v.length > 11) v = v.substring(0, 11);
                    if (v.length > 2) {
                        v = '(' + v.substring(0, 2) + ') ' + v.substring(2);
                    }
                    if (v.length > 10) {
                        v = v.substring(0, 10) + '-' + v.substring(10);
                    }
                    this.value = v;
                });
            }
        });

        let horarioSelecionadoDB = null; 
        let horarioSelecionadoUI = null; 
        let leadWhatsAppAtual = null;

        const TIMEZONE_AGENCIA = 'America/Sao_Paulo';

        function obterDataEmBrasilia(offsetDias = 0) {
            const dataBR = new Date(new Date().toLocaleString("en-US", { timeZone: TIMEZONE_AGENCIA }));
            dataBR.setDate(dataBR.getDate() + offsetDias);
            return dataBR;
        }

        function formatarParaBanco(dateObjBR, horaStr) {
            const ano = dateObjBR.getFullYear();
            const mes = String(dateObjBR.getMonth() + 1).padStart(2, '0');
            const dia = String(dateObjBR.getDate()).padStart(2, '0');
            return `${ano}-${mes}-${dia} ${horaStr}:00`; 
        }

        function encontrarProximaJanelaDisponivel(horariosOcupados) {
            let offset = 0;
            const slots = ['10:00', '14:00', '17:00'];
            const agoraBR = obterDataEmBrasilia(0); 

            while (offset < 60) { 
                const data1 = obterDataEmBrasilia(offset);
                const data2 = obterDataEmBrasilia(offset + 1);

                let slotsLivres = 0;

                for (let hora of slots) {
                    const [slotHora, slotMin] = hora.split(':').map(Number);
                    const dataSlot1 = new Date(data1);
                    dataSlot1.setHours(slotHora, slotMin, 0, 0);

                    const estaOcupado = horariosOcupados.includes(formatarParaBanco(data1, hora));
                    const estaNoPassado = dataSlot1 <= agoraBR;

                    if (!estaOcupado && !estaNoPassado) slotsLivres++;
                }

                for (let hora of slots) {
                    const [slotHora, slotMin] = hora.split(':').map(Number);
                    const dataSlot2 = new Date(data2);
                    dataSlot2.setHours(slotHora, slotMin, 0, 0);

                    const estaOcupado = horariosOcupados.includes(formatarParaBanco(data2, hora));
                    const estaNoPassado = dataSlot2 <= agoraBR;

                    if (!estaOcupado && !estaNoPassado) slotsLivres++;
                }

                // Se esta duplas de dias tiver pelo menos 1 horário futuro e livre, escolhe esta janela!
                if (slotsLivres > 0) {
                    return offset;
                }

                offset += 1; 
            }
            return 0;
        }

        function gerarDiasCalendario(horariosOcupados = [], offset = 0) {
            const container = document.getElementById('calendarContainer');
            container.innerHTML = ''; 

            const data1 = obterDataEmBrasilia(offset);
            const data2 = obterDataEmBrasilia(offset + 1);
            const agoraBR = obterDataEmBrasilia(0); 

            const slots = ['10:00', '14:00', '17:00'];

            const criarColuna = (titulo, dateObj) => {
                let html = `<div class="calendar-day-col"><h4>${titulo}</h4>`;
                
                slots.forEach(hora => {
                    const valorSQL = formatarParaBanco(dateObj, hora);
                    const estaOcupado = horariosOcupados.includes(valorSQL);
                    
                    const [slotHora, slotMin] = hora.split(':').map(Number);
                    const dataSlot = new Date(dateObj);
                    dataSlot.setHours(slotHora, slotMin, 0, 0);
                    
                    const estaNoPassado = dataSlot <= agoraBR;
                    const desativarBotao = estaOcupado || estaNoPassado;

                    const opcoesUI = { weekday: 'long', day: '2-digit', month: '2-digit' };
                    let nomeDia = dateObj.toLocaleDateString('pt-BR', opcoesUI).split(',')[0];
                    const textoUI = `${nomeDia}, ${String(dateObj.getDate()).padStart(2, '0')}/${String(dateObj.getMonth() + 1).padStart(2, '0')} às ${hora}`;

                    const btnStatus = desativarBotao ? 'disabled' : '';
                    
                    let statusTexto = "";
                    if (estaOcupado) statusTexto = `<span class="slot-status">• Lotado</span>`;
                    else if (estaNoPassado) statusTexto = `<span class="slot-status">• Encerrado</span>`;

                    html += `<button class="time-slot" onclick="if(!this.disabled) selecionarSlot(this, '${valorSQL}', '${textoUI}')" ${btnStatus}>
                                <span>${hora}</span> ${statusTexto}
                             </button>`;
                });
                html += `</div>`;
                return html;
            };

            const formatarTitulo = (dateObj, isFirst) => {
                const dia = String(dateObj.getDate()).padStart(2, '0');
                const mes = String(dateObj.getMonth() + 1).padStart(2, '0');
                let nomeDia = dateObj.toLocaleDateString('pt-BR', { weekday: 'long' }).split('-')[0];
                
                if (offset === 0) {
                    nomeDia = isFirst ? "Hoje" : "Amanhã";
                } else {
                    nomeDia = nomeDia.charAt(0).toUpperCase() + nomeDia.slice(1);
                }
                return `${nomeDia} (${dia}/${mes})`;
            };

            container.innerHTML = criarColuna(formatarTitulo(data1, true), data1) + criarColuna(formatarTitulo(data2, false), data2);
        }

        function selecionarSlot(elemento, valorDB, valorUI) {
            document.querySelectorAll('.time-slot').forEach(el => el.classList.remove('selected'));
            elemento.classList.add('selected');
            
            horarioSelecionadoDB = valorDB; 
            horarioSelecionadoUI = valorUI; 
            
            document.getElementById('btnConfirmTime').style.display = 'block';
        }

        async function confirmarAgendamento() {
            const btn = document.getElementById('btnConfirmTime');
            btn.textContent = 'Agendando...';
            btn.disabled = true;

            const nomeForm = document.querySelector('input[name="nome"]').value.split(' ')[0]; 

            // EVENTOS: Reunião Marcada com Sucesso
            if (typeof fbq !== 'undefined') fbq('track', 'Schedule');
            if (typeof _paq !== 'undefined') _paq.push(['trackEvent', 'Funil_Agendamento', 'Horario_Confirmado', horarioSelecionadoDB]);

            try {
                await fetch('/api/leads/update_agendamento.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        whatsapp: leadWhatsAppAtual,
                        data_agendamento: horarioSelecionadoDB 
                    })
                });

                mostrarTelaSucessoFinal(nomeForm, horarioSelecionadoUI);
            } catch (e) {
                mostrarTelaSucessoFinal(nomeForm, horarioSelecionadoUI);
            }
        }

        async function handleLeadSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const submitBtn = document.getElementById('submitBtn');
            
            const whatsappNumeros = form.whatsapp.value.replace(/\D/g, '');
            if (whatsappNumeros.length < 10) {
                alert('Por favor, insira um número de WhatsApp válido.');
                return;
            }

            submitBtn.textContent = 'Analisando perfil...';
            submitBtn.disabled = true;

            try {
                leadWhatsAppAtual = form.whatsapp.value; 

                const payload = {
                    nome: form.nome.value,
                    whatsapp: form.whatsapp.value,
                    instagram: form.instagram.value,
                    ...window.calcData
                };

                const response = await fetch('/api/leads/submit.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const data = await response.json();

                if (data.success) {
                    document.getElementById('leadForm').style.display = 'none';
                    document.querySelector('.locked-action').style.display = 'none';
                    document.getElementById('instrucaoForm').style.display = 'none';
                    document.querySelector('.conviction-block').style.display = 'none';

                    const nomePrimeiro = form.nome.value.split(' ')[0];
                    const calc = window.calcData || {};
                    const lossFmt = 'R$ ' + Number(calc.prejuizo_mensal || 0).toLocaleString('pt-BR') + ',00';
                    const fatFmt = 'R$ ' + Number(calc.faturamento || 0).toLocaleString('pt-BR') + ',00';

                    // Mostra overlay de análise
                    document.getElementById('analyzingName').textContent = nomePrimeiro;
                    document.getElementById('analyzingFaturamento').textContent = fatFmt;
                    document.getElementById('analyzingPrejuizo').textContent = lossFmt;
                    document.getElementById('analyzingOverlay').style.display = 'block';
                    document.getElementById('analyzingStatus').style.opacity = '0';

                    // Micro-transição: spinner → "Perfil qualificado" → calendário/ebook
                    await new Promise(function(r) { setTimeout(r, 2000); });
                    document.getElementById('analyzingStatus').style.opacity = '1';
                    await new Promise(function(r) { setTimeout(r, 1500); });
                    document.getElementById('analyzingOverlay').style.display = 'none';

                    if (window.calcData.faturamento > 7000) {
                        try {
                            const resHorarios = await fetch('/api/leads/get_horarios.php');
                            const dataHorarios = await resHorarios.json();
                            const ocupados = dataHorarios.ocupados || [];

                            const offsetNecessario = encontrarProximaJanelaDisponivel(ocupados);
                            gerarDiasCalendario(ocupados, offsetNecessario);

                        } catch (e) {
                            gerarDiasCalendario([], 0);
                        }
                        document.getElementById('nativeCalendarBlock').style.display = 'block';
                        // Atualiza barra de progresso
                        document.getElementById('progressBar').style.width = '80%';
                        document.getElementById('progressLabel').textContent = 'Passo 2 de 2: Escolha seu horário (80%)';
                    } else {
                        document.getElementById('ebookLeadNome').textContent = nomePrimeiro;
                        document.getElementById('ebookBlock').style.display = 'block';

                        // Atualiza a Barra para 100% (Funil do E-book Finalizado)
                        document.getElementById('progressBar').style.width = '100%';
                        document.getElementById('progressLabel').textContent = 'Processo Concluído (100%)';
                    }
                } else {
                    alert('Erro ao salvar dados. Tente novamente.');
                    submitBtn.textContent = 'Quero o Plano de Escala';
                    submitBtn.disabled = false;
                }
            } catch (error) {
                alert('Erro de conexão.');
                submitBtn.textContent = 'Quero o Plano de Escala';
                submitBtn.disabled = false;
            }
        }

        function pularAgendamento() {
            // EVENTOS: O Lead clicou em combinar depois via WhatsApp
            if (typeof fbq !== 'undefined') fbq('trackCustom', 'ScheduleSkippedToWhatsapp');
            if (typeof _paq !== 'undefined') _paq.push(['trackEvent', 'Funil_Agendamento', 'Preferiu_WhatsApp', 'Pular_Calendario']);

            const nomeForm = document.querySelector('input[name="nome"]').value.split(' ')[0];
            mostrarTelaSucessoFinal(nomeForm, null);
        }

        function mostrarTelaSucessoFinal(nome, horario) {
            document.getElementById('nativeCalendarBlock').style.display = 'none';
            document.getElementById('successMessage').style.display = 'block';

            document.getElementById('progressBar').style.width = '100%';
            document.getElementById('progressLabel').textContent = 'Processo Concluído (100%)';

            var calc = window.calcData || {};
            var prejuizo = calc.prejuizo_mensal || 0;
            var lossFmt = 'R$ ' + Number(prejuizo).toLocaleString('pt-BR') + ',00';

            document.getElementById('confNamePlaceholder').textContent = nome;
            document.getElementById('confLossPlaceholder').textContent = lossFmt;

            if (horario) {
                document.getElementById('confirmationTitle').textContent = 'Horário Confirmado!';
                document.getElementById('confDateTimePlaceholder').textContent = horario;
                document.getElementById('confirmationSubtitle').innerHTML =
                    '<strong id="confNamePlaceholder">' + nome + '</strong>, sua reunião está marcada para <strong id="confDateTimePlaceholder">' + horario + '</strong>. Você já investiu 3 minutos no seu diagnóstico — essa call de <strong>30 minutos</strong> é o passo final para recuperar os <span class="highlight-gold" id="confLossPlaceholder">' + lossFmt + '</span> que estão escapando do seu estúdio <strong>este mês</strong>.';
            } else {
                document.getElementById('confirmationTitle').textContent = 'Diagnóstico Salvo!';
                document.getElementById('confDateTimePlaceholder').textContent = 'em breve';
                document.getElementById('confirmationSubtitle').innerHTML =
                    '<strong id="confNamePlaceholder">' + nome + '</strong>, seu diagnóstico está completo. Nosso especialista vai entrar em contato pelo WhatsApp para marcar sua call de <strong>30 minutos</strong> onde vamos estruturar o plano exato para recuperar os <span class="highlight-gold" id="confLossPlaceholder">' + lossFmt + '</span> que estão escapando do seu estúdio <strong>este mês</strong>.';
            }
        }

        // ─── Carrossel Coverflow + Fade ───
        (function() {
            var track = document.getElementById('carouselTrack');
            if (!track) return;

            var slides = Array.from(track.children);
            var total = slides.length;
            if (total < 2) return;

            var atual = 1; // começa do meio

            function distancia(i) {
                var d = i - atual;
                if (d > 1) d -= total;
                if (d < -1) d += total;
                return d;
            }

            function atualizarSlides() {
                slides.forEach(function(slide, i) {
                    var d = distancia(i);
                    var x, rotY, sc, op;

                    if (d === -1) {
                        x = '-45%';
                        rotY = 45;
                        sc = 0.78;
                        op = 0.3;
                    } else if (d === 0) {
                        x = '-50%';
                        rotY = 0;
                        sc = 1;
                        op = 1;
                    } else { // d === +1
                        x = '-55%';
                        rotY = -45;
                        sc = 0.78;
                        op = 0.3;
                    }

                    slide.style.left = '50%';
                    slide.style.transform = 'translateX(' + x + ') perspective(1000px) rotateY(' + rotY + 'deg) scale(' + sc + ')';
                    slide.style.opacity = op;
                    slide.style.zIndex = d === 0 ? 5 : 2;
                    slide.style.pointerEvents = d === 0 ? 'auto' : 'none';
                    slide.classList.toggle('carousel-slide-inactive', d !== 0);
                });
            }

            window.moverCarrossel = function(dir) {
                atual += dir;
                if (atual < 0) atual = total - 1;
                if (atual >= total) atual = 0;
                atualizarSlides();
            };

            // Inicializar
            atualizarSlides();
        })();

        // EVENTOS: Rastreia quando o usuário qualificado como "E-book" clica no botão de compra
        function trackEbookClick() {
            if (typeof fbq !== 'undefined') {
                fbq('track', 'InitiateCheckout', {
                    content_name: 'Manual Tatuador 10k',
                    currency: 'BRL'
                });
            }
            if (typeof _paq !== 'undefined') {
                _paq.push(['trackEvent', 'Funil_Ebook', 'Redirecionado_Pagina_Ebook', 'TATTOO10K']);
            }
        }
    </script>
</body>
</html>