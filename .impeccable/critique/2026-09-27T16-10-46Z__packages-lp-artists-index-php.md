---
target: artist landing page template
total_score: 19
max_score: 28
na_heuristics: 5,7,9
p0_count: 0
p1_count: 4
timestamp: 2026-09-27T16-10-46Z
slug: packages-lp-artists-index-php
---
# Critique — Artist landing page template (`packages/lp/artists/index.php`)

Method: dual-agent (A: design review · B: technical audit). Browser automation unavailable → **no rendered visual inspection or overlay**; assessments are source-derived. Detector scan ran (exit 2, 14 findings).

## Design Health Score (Nielsen, 0–4)

| # | Heuristic | Score | Key issue |
|---|-----------|-------|-----------|
| 1 | Visibility of system status | 2 | No active nav state / no lightbox position ("3/6") |
| 2 | Match system ↔ real world | 3 | Tattoo-native PT-BR, but pipe-delimited hero eyebrow + "Ola" missing accent |
| 3 | User control & freedom | 3 | Lightbox Esc/arrows/backdrop; no swipe, no focus trap |
| 4 | Consistency & standards | 2 | Hero CTA scrolls to #booking while every other WhatsApp CTA opens wa.me |
| 5 | Error prevention | n/a | No user input on this surface |
| 6 | Recognition rather than recall | 3 | Labels paired with icons; context not carried to WhatsApp |
| 7 | Flexibility & efficiency | n/a | Linear one-shot portfolio |
| 8 | Aesthetic & minimalist | 3 | Restrained gold-on-black; invented stats + 5 reel dead zone |
| 9 | Error recovery | n/a | No in-page error states |
| 10 | Help & documentation | 3 | Good FAQ; pricing vague and buried below CTA |
| **Total** | | **19/28** | Acceptable→Good |

## Technical audit (0–4)

| # | Dimension | Score | Key finding |
|---|-----------|-------|-------------|
| 1 | Accessibility | 3 | Contrast passes; collapsed FAQ answers stay in a11y tree; lightbox not focus-trapped |
| 2 | Performance | 2 | Resident `will-change`; `transition: all` ×11; unthrottled scroll handler; inline CSS/JS not cacheable |
| 3 | Responsive | 3 | 320px embeds fixed; `100vh` clipping; 40px touch targets; `overflow-x:hidden` masks overflow |
| 4 | Theming | 2 | Tokens partially adopted; repeated literals; hard-coded `#E0E0E0`; duplicate 404 palette |
| 5 | Implementation integrity | 2 | 2,278-line god-file; fabricated stats for every artist |
| **Total** | | **12/20** | Acceptable |

## Design specificity verdict

Generic luxury-dark portfolio template wearing a tattoo costume. Only domain-native elements: the cover-up before/after slider, the one-off needle divider, and the PT-BR copy. Hero deliberately desaturates the artist's craft (`brightness(.55) saturate(.45)`).

Deterministic scan: 14 findings — 13× `overused-font` (Montserrat) are false positives against DESIGN.md's sanctioned Two-Font Rule; 1× `broken-image` (empty lightbox `src=""`) is half-real (spurious request possible).

## Priority issues

1. **P1 — God-file.** 2,278 lines mixing routing, validation, data shaping, SEO, 10 section renderers, ~1,180 lines inline CSS, ~146 lines inline JS. Adding a section touches ≥5 regions. The `lib/artists/*` helpers are the inverse: deep, seamed, unit-tested.
2. **P1 — Fabricated stats hardcoded for every artist** (`600+`, `5.0`, lines 557–563). Contradicts "prove before you promise".
3. **P1 — Hero hierarchy inverted**; name shown twice, differentiator buried in an 11px eyebrow.
4. **P1 — Generic WhatsApp prefill**; lead must compose the hardest message unaided.
5. **P2 — Escaping inconsistent** (`{$display_name}`, `{$hero_img_url}`, FAQ, nav) — safe today (trusted config), latent XSS if config becomes CMS-authored.
6. **P2 — JSON-LD `</script>` breakout possible** (`JSON_UNESCAPED_SLASHES`, no HEX flags) in `SeoHelpers.php`.
7. **P2 — A11y:** collapsed FAQ answers exposed to screen readers; lightbox not focus-trapped/inert.
8. **P2 — Performance:** resident `will-change`, `transition: all`, unthrottled scroll.
9. **P2 — No CI;** the 79 green assertions never run automatically.

## Persona red flags

- **Jordan (first-timer):** blank-chat freeze at WhatsApp; price only ~10 screens down; "Agende… pelo WhatsApp" scrolls instead of opening WhatsApp; "Powered by VAIF" muddies artist ownership.
- **Casey (mobile):** two fixed controls stack bottom-right; 5 full-height IG reels mid-page as a third-party dead zone; thumb-reach CTA is a strength.
- **Sam (accessibility):** all six FAQ answers announced at once; Tab escapes the lightbox to background; hamburger label never switches to "Fechar"; micro-text below the 1.1rem floor. Contrast and slider keyboard support are good.

## Strengths

1. Cover-up comparison slider — domain-native, accessible, rAF/GPU-friendly.
2. Conversion plumbing — 3 WhatsApp CTAs + sticky mobile bar + Matomo events with slug.
3. A11y fundamentals — skip link, focus-visible, aria-expanded, alt text, focus return from lightbox.

## Minor observations

- WhatsApp number `553599968249` is 12 digits (BR mobile is 13) — verify.
- `image_url()` is a dead alias of `artist_media_url()`.
- Footer year hardcoded `2026`.
- `joao-silva.php` ships `placehold.co` images + dead `example.com` video.
- 9px labels; touch targets 40px; `100vh` on hero; `overflow-x:hidden` masks overflow.
