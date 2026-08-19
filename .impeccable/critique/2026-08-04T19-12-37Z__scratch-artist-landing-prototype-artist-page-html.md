---
target: .scratch/artist-landing/prototype/artist-page.html
total_score: 25
max_score: 36
na_heuristics: 10
p0_count: 1
p1_count: 2
timestamp: 2026-08-04T19-12-37Z
slug: scratch-artist-landing-prototype-artist-page-html
---
# Critique: Artist Landing Page Prototype (Run 2 — post bolder/polish)

Method: dual-agent (A: ses_031d14bb4ffehiGeybOia7rhq7 · B: ses_031d13948ffeIuQKwVXR85cp1m)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|---|---|---|
| 1 | Visibility of System Status | 3/4 | FAQ aria-expanded, hamburger toggle, back-to-top all track; no scroll-spy nav highlight |
| 2 | Match System / Real World | 4/4 | Fluent pt-BR, authentic tattoo-industry register, WhatsApp-primary, prices in Reais |
| 3 | User Control and Freedom | 2/4 | Mobile nav overlay has no Escape key or backdrop dismiss |
| 4 | Consistency and Standards | 3/4 | Strong CSS token system; three WhatsApp button classes with different sizing |
| 5 | Error Prevention | 2/4 | Portfolio hover effects create false affordances without interactivity |
| 6 | Recognition Rather Than Recall | 3/4 | Standard patterns throughout; icons universally recognized |
| 7 | Flexibility and Efficiency | 3/4 | Linear narrative with persistent CTAs + sticky bar is good for this scope |
| 8 | Aesthetic and Minimalist Design | 3/4 | Palette strong; booking box slightly over-ornamented; circle-crop Instagram loses detail |
| 9 | Error Recovery | 2/4 | FAQ re-toggling works; mobile nav lacks cancel/escape path |
| 10 | Help and Documentation | n/a | Persuade-mode landing page; FAQ handles help adequately |
| **Total** | | **25/36** | **Good (69%)** |

On the same 8-heuristic scale as the baseline (excluding H7/H10): **22/32 (+3 from 19/32)**

Score delta: +3. Gains: focus indicators (+1 H1), FAQ icon consistency (+1 H4), real map embed (+1 H2), slightly better error-recovery structure (+1 H9).

## Detector scan

9 findings — 0 real issues, 9 false positives. All were `overused-font` on Montserrat (which isn't even in the detector's flagged-font list). All three prior-run findings (FAQ layout animation, dark glow shadows, overused-font) are resolved. CLI detector is clean.

Minor code-quality observations: `var` instead of `const`/`let`, one inline-style DRY violation on a diamond, Firefox doesn't animate `fr` units so accordion loses smoothness there.

## Design Specificity Verdict

The copy does most of the specificity work — authentic pt-BR, correct tattoo-industry register, culturally accurate details. The visual language (dark+gold, serif/sans-serif pairing, diamond dividers, bordered cards) is still interchangeable with any premium service page (real estate, photography, coaching). A tattoo page should feel more visceral — this reads airbrushed. The needle-dot portfolio divider was a step in the right direction but isn't enough on its own.

## Overall Impression

Clear improvement from the baseline. The surface is mechanically clean — zero real detector findings, focus indicators work, FAQ animation is smooth in Chromium, the map is real, the hero is focused. But the page still reads as "well-executed luxury template" rather than "João Silva's tattoo studio." The copywriting is the strongest asset; the visual identity hasn't caught up.

## What's Working

- **Copywriting is domain-authentic** — Brazilian Portuguese with correct tattoo-industry register, realistic pricing, culturally accurate metro/neighborhood/payment details. This alone sells the page.
- **FAQ accordion is unusually thorough** — covers pricing, deposits, session duration, aftercare, and cover-ups with concrete numbers. This is the detail that converts R$2,500+ decisions.
- **Persuasive narrative arc is well-sequenced** — Authority → Proof → Connection → Conversion → Validation → Relevance → Objection-handling → Logistics. Every section earns its scroll position.

## Priority Issues

- **[P0] Portfolio hover effects without interactivity:** Scale + gradient overlay on hover promise clickability but nothing happens. Users will click expecting a lightbox. Either remove hover effects entirely or add lightbox behavior.
- **[P1] Mobile nav overlay has no close mechanism:** No Escape key handler, no backdrop click dismiss, no visible close button. A user who opens the menu accidentally is trapped — must commit to a navigation.
- **[P1] Missing skip-to-content link:** Keyboard accessibility baseline gap. Combined with the new focus indicators being the only keyboard support, screen-reader users have no fast-path past the nav.
- **[P2] Instagram circle-crop loses tattoo detail:** `border-radius: 50%` cuts corners where tattoo edge detail lives. Switch to rounded rectangles (border-radius: var(--radius-md)).
- **[P2] Closing CTA lacks visual weight:** Reads as an afterthought compared to the Booking CTA above it. No background treatment, no visual anchor — easy to scroll past.

## Persona Red Flags

- **Jordan (first-timer):** Price anchor (R$2,500) is buried deep in paragraph 2 of About — below portfolio, after hero. A first-timer vetting multiple artists never sees the price signal.
- **Casey (mobile):** Sticky mobile CTA bar permanently occupies ~55px, covering FAQ answers and location details at scroll-bottom. Circle-crop Instagram at 3-column makes images functionally unreadable.
- **Sam (accessibility):** Generic Instagram alt texts ("Instagram post 1" through "8"). Testimonial stars are raw Unicode ★★★★★ — screen readers may narrate "black star" five times despite aria-label. Tattoo divider elements lack aria-hidden. No skip-to-content.

## Minor Observations

- Three WhatsApp button CSS classes with different sizing (`.btn-primary`, `.whatsapp-btn`, `.nav-btn-highlight`) — minor inconsistency.
- FAQ `grid-template-rows` animation doesn't animate in Firefox (`fr` units don't animate there) — graceful degradation but no smooth motion.
- 8 `var` declarations in JS — should be `const`/`let`.

## Questions to Consider

- If you stripped all the copy and left only the visual design, could anyone tell this is a tattoo artist page and not a wedding photographer's? If not, what single visual element is most missing?
- What does a R$2,500+ tattoo customer need to *feel* before messaging on WhatsApp — trust in technical skill (needs real photos), or trust in the artist as a person (needs more of João's voice)?
