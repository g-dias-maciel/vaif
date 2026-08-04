/* VAIF Calculator Logic & Calendar Booking Module */

let horarioSelecionadoDB = null;
let horarioSelecionadoUI = null;
let leadWhatsAppAtual = null;

const TIMEZONE_AGENCIA = 'America/Sao_Paulo';

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
    document.getElementById('prejuizoCopyValue').textContent = 'R$ ' + prejuizo_mensal.toLocaleString('pt-BR') + ',00';

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

        if (slotsLivres > 0) return offset;
        offset += 1;
    }
    return 0;
}

function gerarDiasCalendario(horariosOcupados = [], offset = 0) {
    const container = document.getElementById('calendarContainer');
    if (!container) return;
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

            const nomePrimeiro = form.nome.value.split(' ')[0];

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

function pularAgendamento() {
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

    const titleElement = document.getElementById('finalSuccessTitle');
    const textElement = document.getElementById('finalSuccessText');

    if (horario) {
        titleElement.textContent = "Horário Confirmado!";
        textElement.innerHTML = `Excelente, <strong>${nome}</strong>! Sua reunião está agendada para <strong>${horario}</strong>.<br><br>Nosso especialista vai te enviar uma mensagem no WhatsApp em breve com o link de acesso da nossa sala.`;
    } else {
        titleElement.textContent = "Diagnóstico Salvo!";
        textElement.innerHTML = `Excelente, <strong>${nome}</strong>! Recebemos os seus dados.<br><br>Como você optou por não escolher um horário agora, nosso especialista vai te chamar no WhatsApp para combinarmos o melhor momento para conversarmos.`;
    }
}

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
