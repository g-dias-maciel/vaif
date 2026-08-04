/* VAIF Interactive AI SDR Chat Simulator & Qualifying Form */

const ChatSimulator = (function() {
    const messagesContainer = document.getElementById('chat-messages');
    const typingIndicator = document.getElementById('typing-indicator');
    const chatForm = document.getElementById('chat-send-form');
    const chatInput = document.getElementById('chat-user-input');
    const sendBtn = document.getElementById('chat-send-btn');
    const leadCapture = document.getElementById('chat-lead-capture');
    const leadForm = document.getElementById('chat-lead-form');

    if (!messagesContainer) return { init: () => {} };

    const getTime = () => {
        return new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    };

    const addMessage = (sender, text) => {
        const div = document.createElement('div');
        div.className = `chat-message ${sender}`;
        div.innerHTML = `<div class="message-bubble">${text}<span class="msg-time">${getTime()}</span></div>`;
        messagesContainer.insertBefore(div, typingIndicator);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    };

    const showTyping = () => typingIndicator && typingIndicator.classList.add('active');
    const hideTyping = () => typingIndicator && typingIndicator.classList.remove('active');

    // Chat state machine
    const states = [
        {
            reply: 'Olá! 👋 Aqui é o assistente virtual da <strong>VAIF Agency</strong>.<br><br>Vi que você busca mais clientes de alto padrão para o seu estúdio. Me conta: <strong>você sente que está perdendo vendas por falta de tempo para atender?</strong>',
            next: 1
        },
        {
            validator: input => {
                const lower = input.toLowerCase();
                return lower.includes('sim') || lower.includes('muito') || lower.includes('demais') || lower.includes('com certeza') || lower.includes('tô') || lower.includes('estou') || lower.includes('perco');
            },
            success: 'Entendo perfeitamente. A maioria dos tatuadores que atendemos na VAIF perde <strong>40% dos leads</strong> por não responder a tempo.<br><br>Me diz: <strong>quantos clientes você atende por mês hoje?</strong>',
            fail: 'Entendo. Mas sabia que até os melhores estúdios perdem clientes por demora no atendimento?<br><br>Deixa eu perguntar: <strong>se você pudesse ter um assistente respondendo 24h, isso te ajudaria?</strong>',
            next: 2
        },
        {
            validator: input => {
                const num = parseInt(input.replace(/\D/g, ''));
                return !isNaN(num) && num > 0;
            },
            success: (input) => {
                const num = parseInt(input.replace(/\D/g, ''));
                const projecao = Math.round(num * 2.4);
                return `Ótimo! Com <strong>${num} clientes/mês</strong>, aplicando nosso método de captação premium, você pode estar atendendo <strong>${projecao} clientes de alto ticket</strong> em 90 dias.<br><br>Quer ver como? É simples — deixa eu te explicar rapidinho. 👇`;
            },
            fail: 'Sem problemas! Vamos pensar juntos: <strong>qual seria um número realista de clientes que você GOSTARIA de atender por mês?</strong>',
            next: 3
        },
        {
            reply: 'Perfeito! Aqui está o método VAIF:<br><br>1️⃣ <strong>Tráfego Premium</strong> — Leads com orçamento R$ 3.000+<br>2️⃣ <strong>Atendente Virtual</strong> — Qualificamos e fechamos 78% dos leads<br>3️⃣ <strong>Agenda Cheia</strong> — Você só tatua, sem tocar no celular<br><br>Quer ver o diagnóstico do seu estúdio?',
            next: 4
        },
        {
            endFlow: true,
            reply: '🚀 Pronto! Seu diagnóstico personalizado está liberado.<br><br>Deixe seu contato abaixo que nosso especialista envia o <strong>raio-X completo do seu estúdio</strong> em menos de 5 minutos — sem compromisso.'
        }
    ];

    let currentState = 0;
    let isProcessing = false;

    const botReply = (stateIndex) => {
        showTyping();

        const delay = 1200 + Math.random() * 1800;

        setTimeout(() => {
            hideTyping();
            const state = states[stateIndex];

            if (state.endFlow) {
                addMessage('bot', state.reply);
                leadCapture.classList.add('active');
                currentState = states.length;
                chatInput.disabled = true;
                sendBtn.disabled = true;
                return;
            }

            addMessage('bot', state.reply);
            currentState = stateIndex;
            isProcessing = false;
            chatInput.disabled = false;
            chatInput.focus();
            sendBtn.disabled = false;
        }, delay);
    };

    const processInput = (input) => {
        const state = states[currentState];

        if (state.endFlow) return;
        if (!state.validator) {
            botReply(state.next);
            return;
        }

        if (state.validator(input)) {
            const reply = typeof state.success === 'function' ? state.success(input) : state.success;
            showTyping();
            const delay = 1000 + Math.random() * 1500;
            setTimeout(() => {
                hideTyping();
                addMessage('bot', reply);
                currentState = state.next;
                isProcessing = false;
                chatInput.disabled = false;
                sendBtn.disabled = false;
            }, delay);
        } else {
            showTyping();
            const delay = 1000 + Math.random() * 1200;
            setTimeout(() => {
                hideTyping();
                addMessage('bot', state.fail);
                isProcessing = false;
                chatInput.disabled = false;
                sendBtn.disabled = false;
            }, delay);
        }
    };

    const init = () => {
        chatInput.disabled = true;
        sendBtn.disabled = true;
        setTimeout(() => {
            addMessage('bot', states[0].reply);
            currentState = 1;
            chatInput.disabled = false;
            sendBtn.disabled = false;
            // Do not auto-focus — prevents unwanted scroll to chat section on load
        }, 1500);

        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const text = chatInput.value.trim();
            if (!text || isProcessing) return;

            isProcessing = true;
            addMessage('user', text);
            chatInput.value = '';
            chatInput.disabled = true;
            sendBtn.disabled = true;

            processInput(text);
        });

        if (leadForm) {
            leadForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const nome = this.querySelector('input[name="chat-nome"]').value.trim();
                const whatsapp = this.querySelector('input[name="chat-whatsapp"]').value.trim();
                if (!nome || whatsapp.replace(/\D/g, '').length < 10) {
                    alert('Por favor, preencha nome e WhatsApp válido.');
                    return;
                }

                if (typeof fbq !== 'undefined') fbq('track', 'Lead');
                if (typeof _paq !== 'undefined') _paq.push(['trackEvent', 'Chat_Simulador', 'Lead_Capturado', nome]);

                leadCapture.innerHTML = `
                    <h4 style="color:var(--accent-green);">✅ Diagnóstico Solicitado!</h4>
                    <p style="color:var(--text-main);">Obrigado, <strong>${nome}</strong>! Nosso especialista vai te chamar no <strong>WhatsApp</strong> em instantes com seu raio-X completo.</p>
                    <div style="text-align:center;margin-top:15px;">
                        <span style="font-size:10px;color:var(--text-muted);letter-spacing:1px;">🔔 FIQUE DE OLHO NO ZAP</span>
                    </div>
                `;
            });
        }
    };

    return { init };
})();

/* Form Validation for index.php Qualifying Form */
const QualifyingForm = (function() {
    const form = document.getElementById('qualification-form');
    if (!form) return { init: () => {} };

    const fields = {
        'f-name': { min: 3, label: 'Nome completo' },
        'f-studio': { min: 2, label: 'Nome do estúdio' },
        'f-whatsapp': { min: 14, label: 'WhatsApp' },
        'f-instagram': { pattern: /^[\w.]+$/, label: 'Instagram' },
        'f-revenue': { type: 'number', min: 0, label: 'Faturamento' }
    };

    const showError = (el, msg) => {
        const parent = el.closest('.form-group') || el.parentNode;
        const existing = parent.querySelector('.error-text');
        if (existing) existing.remove();
        const err = document.createElement('div');
        err.className = 'error-text';
        err.textContent = msg;
        parent.appendChild(err);
        el.classList.add('invalid');
    };

    const clearError = (el) => {
        const parent = el.closest('.form-group') || el.parentNode;
        const existing = parent.querySelector('.error-text');
        if (existing) existing.remove();
        el.classList.remove('invalid');
    };

    const validateField = (el) => {
        const name = el.name;
        const rules = fields[name];
        if (!rules) return true;

        const val = el.value.trim();
        if (!val) {
            showError(el, 'Este campo é obrigatório');
            return false;
        }

        if (rules.type === 'number') {
            const num = parseBrNumber(val);
            if (isNaN(num) || num < rules.min) {
                showError(el, 'Informe um valor válido');
                return false;
            }
        } else if (rules.min && val.length < rules.min) {
            showError(el, `${rules.label} deve ter pelo menos ${rules.min} caracteres`);
            return false;
        }

        if (rules.pattern && !rules.pattern.test(val)) {
            showError(el, 'Formato inválido. Use apenas letras, números e pontos');
            return false;
        }

        clearError(el);
        return true;
    };

    const init = () => {
        Object.keys(fields).forEach(name => {
            const el = form.querySelector(`[name="${name}"]`);
            if (el) el.addEventListener('blur', () => validateField(el));
        });

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            let allValid = true;

            Object.keys(fields).forEach(name => {
                const el = this.querySelector(`[name="${name}"]`);
                if (el && !validateField(el)) allValid = false;
            });

            if (!allValid) return;

            const btn = document.getElementById('qualify-submit-btn');
            btn.textContent = 'Enviando...';
            btn.disabled = true;

            const payload = Object.fromEntries(new FormData(this).entries());
            payload['f-revenue'] = parseBrNumber(payload['f-revenue']);

            if (typeof fbq !== 'undefined') fbq('track', 'Lead');
            if (typeof _paq !== 'undefined') _paq.push(['trackEvent', 'Formulario_Final', 'Lead_Enviado', payload['f-name']]);

            fetch('/api/leads/submit.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    nome: payload['f-name'],
                    whatsapp: payload['f-whatsapp'],
                    instagram: payload['f-instagram'],
                    faturamento: payload['f-revenue'],
                    origem: 'formulario_final'
                })
            })
            .then(res => res.json())
            .then(data => {
                form.style.display = 'none';
                const successMsg = document.getElementById('form-success-message');
                successMsg.style.display = 'block';
                successMsg.innerHTML = `
                    <h3>Diagnóstico Solicitado!</h3>
                    <p>Obrigado, <strong>${payload['f-name'].split(' ')[0]}</strong>! Nosso especialista vai analisar seus dados e te chamar no WhatsApp em até <strong>15 minutos</strong>.</p>
                    <a href="https://wa.me/5511999999999" class="success-cta" target="_blank">Falar no WhatsApp Agora →</a>
                `;
            })
            .catch(() => {
                form.style.display = 'none';
                const successMsg = document.getElementById('form-success-message');
                successMsg.style.display = 'block';
                successMsg.innerHTML = `
                    <h3>Diagnóstico Solicitado!</h3>
                    <p>Recebemos seus dados, <strong>${payload['f-name'].split(' ')[0]}</strong>! Um especialista vai te chamar no WhatsApp em instantes.</p>
                    <a href="https://wa.me/5511999999999" class="success-cta" target="_blank">Falar no WhatsApp Agora →</a>
                `;
            });
        });
    };

    return { init };
})();

document.addEventListener('DOMContentLoaded', () => {
    ChatSimulator.init();
    QualifyingForm.init();
});
