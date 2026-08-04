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
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="css/calculadora.css">
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
                        <div class="marquee-set">
                            <a href="https://www.instagram.com/jhonatanmasters" target="_blank" rel="noopener noreferrer" title="Ver estúdio Jhonatan Masters"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=JHONATAN+MASTERS&font=montserrat" alt="Jhonatan Masters"></a>
                            <a href="https://www.instagram.com/rsilvatattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Rodrigo Silva"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=RODRIGO+SILVA&font=montserrat" alt="Rodrigo Silva"></a>
                            <a href="https://www.instagram.com/sergiomoraestattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Sergio Moraes"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=SERGIO+MORAES&font=montserrat" alt="Sergio Moraes"></a>
                            <a href="https://www.instagram.com/Kleberocker" target="_blank" rel="noopener noreferrer" title="Ver estúdio Kleber Rocker"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=KLEBER+ROCKER&font=montserrat" alt="Kleber Rocker"></a>
                            <a href="https://www.instagram.com/Maikbuenotattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Bueno Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=BUENO+TATTOO&font=montserrat" alt="Bueno Tattoo"></a>
                            <a href="https://www.instagram.com/dinho_tattoo091" target="_blank" rel="noopener noreferrer" title="Ver estúdio Dinho Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=DINHO+TATTOO&font=montserrat" alt="Dinho Tattoo"></a>
                        </div>
                        <div class="marquee-set" aria-hidden="true">
                            <a href="https://www.instagram.com/jhonatanmasters" target="_blank" rel="noopener noreferrer" title="Ver estúdio Jhonatan Masters"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=JHONATAN+MASTERS&font=montserrat" alt="Jhonatan Masters"></a>
                            <a href="https://www.instagram.com/rsilvatattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Rodrigo Silva"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=RODRIGO+SILVA&font=montserrat" alt="Rodrigo Silva"></a>
                            <a href="https://www.instagram.com/sergiomoraestattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Sergio Moraes"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=SERGIO+MORAES&font=montserrat" alt="Sergio Moraes"></a>
                            <a href="https://www.instagram.com/Kleberocker" target="_blank" rel="noopener noreferrer" title="Ver estúdio Kleber Rocker"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=KLEBER+ROCKER&font=montserrat" alt="Kleber Rocker"></a>
                            <a href="https://www.instagram.com/Maikbuenotattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Bueno Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=BUENO+TATTOO&font=montserrat" alt="Bueno Tattoo"></a>
                            <a href="https://www.instagram.com/dinho_tattoo091" target="_blank" rel="noopener noreferrer" title="Ver estúdio Dinho Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=DINHO+TATTOO&font=montserrat" alt="Dinho Tattoo"></a>
                        </div>
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
                        <div class="marquee-set">
                            <a href="https://www.instagram.com/jhonatanmasters" target="_blank" rel="noopener noreferrer" title="Ver estúdio Jhonatan Masters"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=JHONATAN+MASTERS&font=montserrat" alt="Jhonatan Masters"></a>
                            <a href="https://www.instagram.com/rsilvatattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Rodrigo Silva"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=RODRIGO+SILVA&font=montserrat" alt="Rodrigo Silva"></a>
                            <a href="https://www.instagram.com/sergiomoraestattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Sergio Moraes"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=SERGIO+MORAES&font=montserrat" alt="Sergio Moraes"></a>
                            <a href="https://www.instagram.com/Kleberocker" target="_blank" rel="noopener noreferrer" title="Ver estúdio Kleber Rocker"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=KLEBER+ROCKER&font=montserrat" alt="Kleber Rocker"></a>
                            <a href="https://www.instagram.com/Maikbuenotattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Bueno Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=BUENO+TATTOO&font=montserrat" alt="Bueno Tattoo"></a>
                            <a href="https://www.instagram.com/dinho_tattoo091" target="_blank" rel="noopener noreferrer" title="Ver estúdio Dinho Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=DINHO+TATTOO&font=montserrat" alt="Dinho Tattoo"></a>
                        </div>
                        <div class="marquee-set" aria-hidden="true">
                            <a href="https://www.instagram.com/jhonatanmasters" target="_blank" rel="noopener noreferrer" title="Ver estúdio Jhonatan Masters"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=JHONATAN+MASTERS&font=montserrat" alt="Jhonatan Masters"></a>
                            <a href="https://www.instagram.com/rsilvatattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Rodrigo Silva"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=RODRIGO+SILVA&font=montserrat" alt="Rodrigo Silva"></a>
                            <a href="https://www.instagram.com/sergiomoraestattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Sergio Moraes"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=SERGIO+MORAES&font=montserrat" alt="Sergio Moraes"></a>
                            <a href="https://www.instagram.com/Kleberocker" target="_blank" rel="noopener noreferrer" title="Ver estúdio Kleber Rocker"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=KLEBER+ROCKER&font=montserrat" alt="Kleber Rocker"></a>
                            <a href="https://www.instagram.com/Maikbuenotattoo" target="_blank" rel="noopener noreferrer" title="Ver estúdio Bueno Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=BUENO+TATTOO&font=montserrat" alt="Bueno Tattoo"></a>
                            <a href="https://www.instagram.com/dinho_tattoo091" target="_blank" rel="noopener noreferrer" title="Ver estúdio Dinho Tattoo"><img class="marquee-logo" src="https://placehold.co/160x32/999/222?text=DINHO+TATTOO&font=montserrat" alt="Dinho Tattoo"></a>
                        </div>
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

    <script src="js/main.js"></script>
    <script src="js/calculator.js"></script>
    <script src="js/calculadora-page.js"></script>
</body>
</html>
