/**
 * Video facade — click-to-load YouTube embed.
 *
 * Renders a lightweight branded poster; the privacy-enhanced (youtube-nocookie)
 * iframe is only injected after the visitor clicks. This means no third-party
 * request and no cookies on page load (LGPD-friendly), and no YouTube chrome
 * or branding on the poster itself.
 *
 * Works for any element carrying data-video-id, e.g. the warm-up video and the
 * artist testimonial:
 *
 *   <div class="homework-video"
 *        data-video-id="{youtubeId}"
 *        data-video-title="{accessible title}"
 *        role="button" tabindex="0" aria-label="{label}">
 *     <div class="homework-poster"><span class="homework-poster-label">…</span></div>
 *     <div class="homework-play">…</div>
 *   </div>
 */
(function () {
    'use strict';

    // autoplay on click; rel=0 keeps related videos to the same channel;
    // playsinline keeps iOS from forcing fullscreen; modestbranding minimizes
    // YouTube chrome.
    var PARAMS = 'autoplay=1&rel=0&playsinline=1&modestbranding=1';

    function activate(facade) {
        var id = facade.getAttribute('data-video-id');
        if (!id || facade.getAttribute('data-activated') === 'true') return;

        facade.setAttribute('data-activated', 'true');
        facade.classList.add('is-playing');
        facade.removeAttribute('role');
        facade.removeAttribute('tabindex');
        facade.removeAttribute('aria-label');

        while (facade.firstChild) {
            facade.removeChild(facade.firstChild);
        }

        var iframe = document.createElement('iframe');
        iframe.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(id) + '?' + PARAMS;
        iframe.title = facade.getAttribute('data-video-title') || 'Vídeo';
        iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('loading', 'lazy');
        facade.appendChild(iframe);
    }

    function init() {
        var facades = document.querySelectorAll('[data-video-id]');
        Array.prototype.forEach.call(facades, function (facade) {
            facade.addEventListener('click', function () {
                activate(facade);
            });
            facade.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    activate(facade);
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
