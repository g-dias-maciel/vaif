/* VAIF Shared JavaScript Utilities */

// Parse Brazilian currency format (1.500,00 -> 1500)
function parseBrNumber(val) {
    if (!val) return 0;
    let cleanVal = val.toString().replace(/\./g, '').replace(',', '.');
    return parseFloat(cleanVal) || 0;
}

// Animate numbers smoothly
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

// Setup input masks (WhatsApp and Instagram)
function setupInputMasks(scope = document) {
    const instagramInputs = scope.querySelectorAll('input[name="instagram"], input[name="f-instagram"], input[name="chat-instagram"]');
    const whatsappInputs = scope.querySelectorAll('input[name="whatsapp"], input[name="f-whatsapp"], input[name="chat-whatsapp"]');

    instagramInputs.forEach(input => {
        input.addEventListener('input', function() {
            this.value = this.value.replace(/[@\s]/g, '');
        });
    });

    whatsappInputs.forEach(input => {
        input.addEventListener('input', function() {
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
    });

    // Revenue mask — only digits, dots (thousands), and commas (decimal)
    const revenueInputs = scope.querySelectorAll('input[name="f-revenue"], input[name="chat-revenue"]');
    revenueInputs.forEach(input => {
        input.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9.,]/g, '');
        });
    });
}

// Setup mobile hamburger menu toggle
function setupMobileNav() {
    const hamburger = document.getElementById('nav-hamburger');
    const navLinks = document.getElementById('nav-links');
    if (!hamburger || !navLinks) return;

    const toggleMenu = (open) => {
        const isOpen = open !== undefined ? open : !navLinks.classList.contains('open');
        navLinks.classList.toggle('open', isOpen);
        hamburger.classList.toggle('active', isOpen);
        hamburger.setAttribute('aria-expanded', isOpen);
        document.body.style.overflow = isOpen ? 'hidden' : '';
    };

    hamburger.addEventListener('click', () => toggleMenu());

    // Close menu on link click
    navLinks.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => toggleMenu(false));
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') toggleMenu(false);
    });
}

// Setup scroll-triggered fade-in animations
function setupScrollObserver() {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                // If entry.target itself is a fade-in element
                if (entry.target.classList.contains('fade-in-up')) {
                    entry.target.classList.add('visible');
                }
                // Also trigger any child fade-in-up elements
                const animEls = entry.target.querySelectorAll('.fade-in-up');
                animEls.forEach((el) => {
                    el.classList.add('visible');
                });
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.05, rootMargin: '0px 0px -20px 0px' });

    // Observe only fade-in-up elements (not section containers — prevents scroll jump on load)
    document.querySelectorAll('.fade-in-up').forEach(el => {
        observer.observe(el);
    });

    // Hero elements visible immediately
    document.querySelectorAll('.hero .fade-in-up').forEach(el => {
        el.classList.add('visible');
    });
}

document.addEventListener('DOMContentLoaded', () => {
    setupMobileNav();
    setupInputMasks();
    setupScrollObserver();
});
