/**
 * Hero WhatsApp Chat Animation — Infinite Loop Simulator
 *
 * Smooth, jank-free automated chat sequence for the hero phone mockup.
 * Uses overflow-anchor for natural scroll following (no forced reflows),
 * keeps the typing indicator in-place, and eliminates dead gaps.
 */

const HeroChatAnimation = (function() {
    'use strict';

    const CONTAINER_ID = 'hero-chat-container';
    const TYPING_ID = 'hero-typing-indicator';
    const RESET_DELAY = 8000;     // ms before restarting after sequence ends
    const TYPING_MIN = 1400;      // min typing pause
    const TYPING_MAX = 2400;      // max typing pause
    const POST_MESSAGE_DELAY = 400; // tiny gap before next typing starts

    let container, typingEl;
    let currentStep = 0;
    let timeoutId = null;
    let isAnimating = false;

    // ── Conversation sequence (complete sale: lead → refs → booking) ───
    const SEQUENCE = [
        {
            text: 'Olá, queria saber o valor para fechar as costas inteiras em realismo.',
            sender: 'user'
        },
        {
            text: 'Que legal! Para te dar um orçamento bem preciso, você pode me enviar algumas fotos de referência do estilo que deseja? Assim já aproveito e vejo a disponibilidade na agenda.',
            sender: 'bot'
        },
        {
            text: 'Claro! Tenho algumas referências no Pinterest. Vou enviar.',
            sender: 'user'
        },
        {
            text: 'Perfeito! Recebi as imagens. Analisando aqui... o encaixe ideal para o desenho vai ficar incrível nas suas costas. Posso já deixar reservado um horário para iniciarmos o projeto? Qual o melhor dia pra você?',
            sender: 'bot'
        },
        {
            text: 'Pode ser semana que vem, terça de manhã.',
            sender: 'user'
        },
        {
            text: 'Terça às 10h está reservado! ✅ Você receberá a confirmação por WhatsApp com os detalhes do orçamento. Pode ir enviando mais referências até lá — quanto mais material, mais exato fica o valor final.',
            sender: 'bot'
        }
    ];

    // ── Helpers ──────────────────────────────────────────────

    function randomPause() {
        return TYPING_MIN + Math.random() * (TYPING_MAX - TYPING_MIN);
    }

    function showTyping() {
        if (typingEl) {
            typingEl.classList.add('active');
        }
    }

    function hideTyping() {
        if (typingEl) typingEl.classList.remove('active');
    }

    function scrollToBottom() {
        // Double-RAF: wait for layout to settle, then scroll once
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                container.scrollTop = container.scrollHeight;
            });
        });
    }

    function appendMessage(text, sender) {
        const bubble = document.createElement('div');
        bubble.className = 'hc-message ' + sender;
        bubble.innerHTML = '<div class="hc-bubble">' + text + '</div>';
        // Insert BEFORE the typing indicator so typing always stays at bottom
        if (typingEl && typingEl.parentNode === container) {
            container.insertBefore(bubble, typingEl);
        } else {
            container.appendChild(bubble);
        }
        scrollToBottom();
    }

    // ── Core loop ────────────────────────────────────────────

    function playStep() {
        if (currentStep >= SEQUENCE.length) {
            // Sequence finished — pause, then reset
            timeoutId = setTimeout(resetSequence, RESET_DELAY);
            return;
        }

        const msg = SEQUENCE[currentStep];
        const pause = currentStep === 0
            ? 2200 + Math.random() * 800   // initial hesitation — shorter
            : randomPause();

        showTyping();
        scrollToBottom();

        timeoutId = setTimeout(function() {
            hideTyping();
            appendMessage(msg.text, msg.sender);
            currentStep++;
            // Short natural pause before next typing indicator
            timeoutId = setTimeout(playStep, POST_MESSAGE_DELAY);
        }, pause);
    }

    function resetSequence() {
        // Fade out existing messages before clearing
        if (container) {
            const messages = container.querySelectorAll('.hc-message');
            messages.forEach(function(el, i) {
                el.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                el.style.opacity = '0';
                el.style.transform = 'translateY(-6px)';
            });
            // Wait for fade-out, then clear
            setTimeout(function() {
                container.innerHTML = '';
                // Re-append the typing indicator (it was removed by innerHTML clearing)
                if (typingEl) {
                    container.appendChild(typingEl);
                }
                currentStep = 0;
                hideTyping();
                // Restart
                timeoutId = setTimeout(function() {
                    playStep();
                }, 2000);
            }, 350);
        }
    }

    // ── Public API ───────────────────────────────────────────

    function init() {
        container = document.getElementById(CONTAINER_ID);
        if (!container) return;

        typingEl = document.getElementById(TYPING_ID);
        if (!typingEl) {
            typingEl = document.createElement('div');
            typingEl.id = TYPING_ID;
            typingEl.className = 'hc-typing';
            typingEl.innerHTML = '<span></span><span></span><span></span>';
            container.appendChild(typingEl);
        } else {
            // Ensure typing indicator is the last child so it stays at the bottom
            container.appendChild(typingEl);
        }

        // Small delay before first message so the user sees a "live" feel
        isAnimating = true;
        timeoutId = setTimeout(function() {
            playStep();
        }, 1800);
    }

    function destroy() {
        if (timeoutId) clearTimeout(timeoutId);
        isAnimating = false;
    }

    return {
        init: init,
        destroy: destroy
    };
})();

// Boot on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    HeroChatAnimation.init();
});