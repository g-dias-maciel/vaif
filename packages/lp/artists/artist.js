(function() {
            const hamburger = document.getElementById('nav-hamburger');
            const navLinks = document.getElementById('nav-links');

            function closeNav() {
                navLinks.classList.remove('open');
                hamburger.classList.remove('active');
                hamburger.setAttribute('aria-expanded', 'false');
                hamburger.setAttribute('aria-label', 'Abrir menu');
                hamburger.focus();
            }

            function openNav() {
                navLinks.classList.add('open');
                hamburger.classList.add('active');
                hamburger.setAttribute('aria-expanded', 'true');
                hamburger.setAttribute('aria-label', 'Fechar menu');
            }

            hamburger.addEventListener('click', function() {
                if (navLinks.classList.contains('open')) {
                    closeNav();
                } else {
                    openNav();
                }
            });

            navLinks.addEventListener('click', function(e) {
                if (e.target === navLinks) {
                    closeNav();
                }
            });

            navLinks.querySelectorAll('a').forEach(function(link) {
                link.addEventListener('click', function() {
                    closeNav();
                });
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && navLinks.classList.contains('open')) {
                    closeNav();
                }
            });

            const faqItems = document.querySelectorAll('.faq-item');
            faqItems.forEach(function(item) {
                const btn = item.querySelector('.faq-question');
                const icon = item.querySelector('.faq-icon');
                const answer = item.querySelector('.faq-answer');
                btn.addEventListener('click', function() {
                    const isActive = item.classList.contains('active');
                    item.classList.toggle('active');
                    icon.textContent = isActive ? '+' : '\u2212';
                    btn.setAttribute('aria-expanded', isActive ? 'false' : 'true');
                    // Keep collapsed answers out of the accessibility tree.
                    if (answer) answer.setAttribute('aria-hidden', isActive ? 'true' : 'false');
                });
            });

            // Before/After comparison sliders
            document.querySelectorAll('.ba-viewport').forEach(function(viewport) {
                const range = viewport.querySelector('.ba-range');
                if (!range) return;
                let frame = null;
                const render = function() {
                    frame = null;
                    viewport.style.setProperty('--ba-pos', range.value + '%');
                };
                const schedule = function() {
                    // Coalesce bursts of input events into one write per frame.
                    if (frame === null) frame = requestAnimationFrame(render);
                };
                range.addEventListener('input', schedule);
                range.addEventListener('change', schedule);
                render();

                // Promote to its own layer only while the user is interacting.
                ['pointerdown', 'focusin'].forEach(function(ev) {
                    viewport.addEventListener(ev, function() { viewport.classList.add('ba-active'); });
                });
                ['pointerup', 'pointercancel', 'focusout'].forEach(function(ev) {
                    viewport.addEventListener(ev, function() { viewport.classList.remove('ba-active'); });
                });
            });

            // Portfolio lightbox — click a work to view it enlarged
            (function() {
                const lightbox = document.getElementById('lightbox');
                if (!lightbox) return;
                const items = Array.from(document.querySelectorAll('.portfolio-item'));
                if (!items.length) return;

                const imgEl = document.getElementById('lightbox-img');
                const captionEl = document.getElementById('lightbox-caption');
                const closeBtn = document.getElementById('lightbox-close');
                const prevBtn = document.getElementById('lightbox-prev');
                const nextBtn = document.getElementById('lightbox-next');
                const background = ['#main-content', '.navbar', '.artist-footer', '.mobile-cta-bar', '#back-to-top']
                    .map(function(sel) { return document.querySelector(sel); })
                    .filter(Boolean);
                let current = 0;

                function render() {
                    const item = items[current];
                    const thumb = item.querySelector('img');
                    imgEl.src = item.getAttribute('data-full') || (thumb ? thumb.src : '');
                    const alt = item.getAttribute('data-alt') || (thumb ? thumb.alt : '');
                    imgEl.alt = alt;
                    captionEl.textContent = alt;
                    const multiple = items.length > 1;
                    prevBtn.hidden = !multiple;
                    nextBtn.hidden = !multiple;
                }
                function open(index) {
                    current = (index + items.length) % items.length;
                    render();
                    lightbox.hidden = false;
                    document.body.classList.add('lightbox-open');
                    // Contain focus: everything behind the dialog becomes inert.
                    background.forEach(function(el) { el.setAttribute('inert', ''); });
                    closeBtn.focus();
                }
                function close() {
                    lightbox.hidden = true;
                    document.body.classList.remove('lightbox-open');
                    background.forEach(function(el) { el.removeAttribute('inert'); });
                    if (items[current]) items[current].focus();
                }
                function step(delta) {
                    current = (current + delta + items.length) % items.length;
                    render();
                }

                items.forEach(function(item, i) {
                    item.addEventListener('click', function() { open(i); });
                });
                closeBtn.addEventListener('click', close);
                prevBtn.addEventListener('click', function() { step(-1); });
                nextBtn.addEventListener('click', function() { step(1); });
                lightbox.addEventListener('click', function(e) {
                    if (e.target === lightbox) close();
                });
                document.addEventListener('keydown', function(e) {
                    if (lightbox.hidden) return;
                    if (e.key === 'Escape') close();
                    else if (e.key === 'ArrowLeft') step(-1);
                    else if (e.key === 'ArrowRight') step(1);
                });
            })();

            const backToTop = document.getElementById('back-to-top');
            let scrollScheduled = false;
            window.addEventListener('scroll', function() {
                if (scrollScheduled) return;
                scrollScheduled = true;
                requestAnimationFrame(function() {
                    scrollScheduled = false;
                    backToTop.classList.toggle('visible', window.scrollY > 600);
                });
            }, { passive: true });
            backToTop.addEventListener('click', function() {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        })();
