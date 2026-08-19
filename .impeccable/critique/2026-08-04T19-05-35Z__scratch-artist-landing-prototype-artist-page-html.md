---
target: .scratch/artist-landing/prototype/artist-page.html
total_score: 19
max_score: 32
na_heuristics: 7,10
p0_count: 2
p1_count: 3
timestamp: 2026-08-04T19-05-35Z
slug: scratch-artist-landing-prototype-artist-page-html
---
# Critique: Artist Landing Page Prototype

Method: dual-agent (A: ses_031d7e3e2ffeNy9u4k2dkrHo7z · B: ses_031d7c1d7ffesjKij4cDNy4bwi)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|---|---|---|
| 1 | Visibility of System Status | 2/4 | FAQ first item icon shows "+" while expanded; no active-section nav highlighting |
| 2 | Match System / Real World | 3/4 | Pix/sinal terms authentic for BR market; diamond motif lacks tattoo-studio anchor |
| 3 | User Control and Freedom | 2/4 | smooth-scroll overrides prefers-reduced-motion; no back-to-top affordance |
| 4 | Consistency and Standards | 3/4 | Section-header pattern repeats across 7/8 sections; Booking CTA breaks the pattern |
| 5 | Error Prevention | 2/4 | Portfolio grid has cursor:pointer with no click handler; no WhatsApp fallback |
| 6 | Recognition Rather Than Recall | 3/4 | Solid — WhatsApp icon universal in BR; Instagram lacks follower count |
| 7 | Flexibility and Efficiency | n/a | Single-page persuasion flow |
| 8 | Aesthetic and Minimalist Design | 3/4 | Gold palette restrained; map placeholder is visual dead zone |
| 9 | Error Recovery | 1/4 | No alternative contact if WhatsApp fails |
| 10 | Help and Documentation | n/a | FAQ serves as embedded help |
| **Total** | | **19/32** | **Good (59%)** |

## Design Specificity Verdict

The visual language (dark theme, gold accent, luxury-serif typography, diamond dividers) is a premium-service template that could serve a barbershop or boutique hotel with only content swaps. No visual metaphor for ink, needles, skin, or permanence anchors it to tattooing. However, content choices are genuinely tattoo-specific — portfolio grid, WhatsApp-only booking, FAQ covering session duration/aftercare/deposits, Pix terminology — and authentically Brazilian-market.

**Detector scan:** 3 findings — 2 real issues, 1 false positive (Montserrat font is a design-system constraint). Detector caught layout-property animation on FAQ accordion and chromatic glow shadows. Missed critical gaps: no focus styles anywhere, WhatsApp green fails WCAG AA contrast, and first FAQ item ships with icon state mismatch.

**Browser overlays:** Not available (no browser automation).

## Overall Impression

Strong content strategy and FAQ section, but the aesthetic reads as "premium service" interchangeable rather than "tattoo artist." The hero fold is overloaded, the closing impression (map placeholder) undercuts the premium positioning, and the prototype ships with no focus indicators — a hard accessibility fail. Peak-and-end rule suggests fixing the closing section and simplifying the hero are the highest-leverage changes.

## What's Working

- **FAQ section is outstanding** — addresses real pre-booking anxieties (pricing, deposits, aftercare) with concrete, trust-building answers. The first item expanded by default demonstrates confidence.
- **Diamond divider + gold/Cormorant Garamond pairing** creates a consistent premium visual rhythm that feels intentional.
- **Booking CTA creates a distinct "pause moment"** with radial gradient glow and bordered box, successfully breaking page rhythm to command attention.

## Priority Issues

**[P0] Portfolio grid items are dead click targets**
Why: cursor:pointer promises interaction that doesn't exist — users clicking portfolio images expecting a lightbox get nothing, eroding trust at the section meant to showcase quality.
Fix: Remove cursor:pointer from portfolio-item and instagram-item until click handlers exist.

**[P0] Page ends on dead map placeholder**
Why: Peak-end rule — last impression of a R$2,500+ service is placeholder text and bare copyright, crushing the emotional arc.
Fix: Embed real Google Maps iframe; add secondary booking CTA above footer.

**[P1] No visible focus indicators anywhere**
Why: Keyboard users have zero orientation — interactive elements (nav links, CTAs, FAQ accordions, hamburger) are invisible to them.
Fix: Add `:focus-visible` outline styles to all interactive elements with 2px gold outline-offset-2.

**[P1] Hero fold overloaded (7+ competing elements)**
Why: First impression scatters attention across photo, eyebrow, title, subtitle, two CTAs, and three stats — cognitive load at point of highest bounce risk.
Fix: Move stat cards to About section; drop "Ver Portfólio" secondary CTA (nav already links there).

**[P1] scroll-behavior overrides motion preferences**
Why: Accessibility violation — forced smooth scrolling with no opt-out for users with motion sensitivity.
Fix: Wrap in `@media (prefers-reduced-motion: no-preference)`.

## Persona Red Flags

- **Jordan (first-timer):** Hero's dual CTAs create decision paralysis — doesn't know which is the "right" first step.
- **Riley (stress tester):** FAQ first item renders expanded with "+" icon — immediate inconsistency questioning attention to detail.
- **Sam (accessibility):** Zero focus indicators on any interactive element. WhatsApp button fails WCAG AA contrast (1.99:1).

## Minor Observations

- FAQ accordion animates layout properties (max-height + padding) — causes reflow, should use grid-template-rows or clip-path instead.
- `.btn-primary:hover`, `.btn-secondary:hover`, `.whatsapp-btn:hover` all use zero-offset chromatic glow shadows — AI-generated default.
- Portfolio and Instagram grids are visually identical (both square grids, same hover zoom) — users may not perceive Instagram as distinct social proof.
- No `<main>` landmark between nav and footer.
- All 17 `<img>` tags lack `loading="lazy"`.
- Duplicated WhatsApp SVG inline (106 lines) in hero and booking CTA.
- `.faq-icon` spans need `aria-hidden="true"`.
- Nav only covers 4 of 8 sections — no Location link.
- No back-to-top affordance for 8-section scroll.
- No OpenGraph/social meta tags.

## Questions to Consider

1. If the artist photo and portfolio images are the same quality tier (placeholders), does the page feel like a template awaiting content?
2. Would replacing Instagram grid with a "process journey" (consult → sketch → stencil → session → healed) deliver more persuasive value?
3. If WhatsApp fails, the entire conversion path dies — for a R$2,500+ service, what backup contact justifies the single-point-of-failure?
