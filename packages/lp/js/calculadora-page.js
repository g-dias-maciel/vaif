/* VAIF Calculadora Page — Page-Specific Scripts */

function scrollToCalculator() {
    document.getElementById('progressWrapper').scrollIntoView({ behavior: 'smooth' });
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

            document.getElementById('analyzingName').textContent = nomePrimeiro;
            document.getElementById('analyzingFaturamento').textContent = fatFmt;
            document.getElementById('analyzingPrejuizo').textContent = lossFmt;
            document.getElementById('analyzingOverlay').style.display = 'block';
            document.getElementById('analyzingStatus').style.opacity = '0';

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
                document.getElementById('progressBar').style.width = '80%';
                document.getElementById('progressLabel').textContent = 'Passo 2 de 2: Escolha seu horário (80%)';
            } else {
                document.getElementById('ebookLeadNome').textContent = nomePrimeiro;
                document.getElementById('ebookBlock').style.display = 'block';

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

(function() {
    var track = document.getElementById('carouselTrack');
    if (!track) return;

    var slides = Array.from(track.children);
    var total = slides.length;
    if (total < 2) return;

    var atual = 1;

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
            } else {
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

    atualizarSlides();
})();
