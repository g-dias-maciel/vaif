---
name: VAIF Agency Landing Page
description: "Agency storefront converting high-end Brazilian tattoo artists into qualified consultation leads"
colors:
  primary: "#D4B04C"
  bg-dark: "#0A0A0A"
  bg-card: "#121212"
  text-main: "rgb(242, 237, 228)"
  text-muted: "#CCCCCC"
  border-color: "#222222"
  accent-red: "#D44C4C"
  accent-green: "#4CD4A0"
  gold-light: "rgba(212, 176, 76, 0.1)"
  gold-dark: "rgba(212, 176, 76, 0.25)"
  input-bg: "#1A1A1A"
typography:
  display:
    fontFamily: "Cormorant Garamond, serif"
    fontSize: "clamp(2.2rem, 4vw, 3.2rem)"
    fontWeight: 700
    lineHeight: 1.15
  title:
    fontFamily: "Cormorant Garamond, serif"
    fontSize: "1.4rem"
    fontWeight: 600
    lineHeight: 1.3
  body:
    fontFamily: "Montserrat, sans-serif"
    fontSize: "1.1rem"
    fontWeight: 400
    lineHeight: 1.6
  label:
    fontFamily: "Montserrat, sans-serif"
    fontSize: "11px"
    fontWeight: 700
    letterSpacing: "2px"
    textTransform: "uppercase"
rounded:
  sm: "4px"
  md: "8px"
  lg: "16px"
spacing:
  xs: "8px"
  sm: "16px"
  md: "24px"
  lg: "40px"
  xl: "60px"
  "2xl": "80px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "#000"
    padding: "16px 32px"
    typography: "label"
  button-primary-hover:
    backgroundColor: "#E5C35E"
  button-secondary:
    backgroundColor: "transparent"
    textColor: "{colors.primary}"
    padding: "16px 32px"
    typography: "label"
    rounded: "{rounded.sm}"
  button-secondary-hover:
    textColor: "{colors.primary}"
  input-field:
    backgroundColor: "{colors.input-bg}"
    textColor: "{colors.text-main}"
    rounded: "{rounded.sm}"
    padding: "16px 20px"
  card:
    backgroundColor: "{colors.bg-card}"
    textColor: "{colors.text-main}"
    rounded: "0"
    padding: "{spacing.xl} {spacing.lg}"
---

# Design System: VAIF Agency Landing Page

## Overview

**Creative North Star: "The Golden Needle"**

The Golden Needle is a precision instrument — deliberate, sharp, and irreplaceable. Like the tattoo machine it evokes, every element in this system earns its place. The design speaks to artists who already understand craft: nothing is decorative, everything is engineered. The gold (#D4B04C) is old-world bullion — muted, aged, earned — not jewelry-store glitter. It signals wealth that was built, not bought.

The atmosphere is refined, urgent, and aspirational. The page wastes no time on pleasantries; it confronts the artist with their hidden loss, then offers a precise instrument to recover it. The dark canvas (#0A0A0A) recedes, letting the gold do the talking. White space is generous; density is low; every section earns its scroll depth. Anti-references: no startup SaaS gradients, no neon aggression, no rounded blob illustrations, no emoji cheer.

**Key Characteristics:**
- Gold-on-black palette with old-world bullion character — muted, earned, never garish
- Cormorant Garamond for display authority paired with Montserrat for utilitarian precision
- Low-density layout with 6rem section breathing room
- Fade-in-up scroll animations with staggered delays — reveals feel earned, not auto-played
- Diamond motif (◆) as the recurring brand mark — the needle's tip in geometric form
- Card surfaces lift on hover with subtle shadow — layered, not flat

## Colors

The palette is stark: a near-black canvas with a single precious-metal accent. Every other color is neutral infrastructure — borders, surfaces, muted text — that exists only to frame the gold.

### Primary
- **Old-World Bullion** (#D4B04C): The sole accent. Used on CTA buttons, section headings highlights, the diamond divider, hover borders, stat numbers, and navigational active states. Its rarity within a page is the point — when gold appears, the eye knows it matters. Hover brightens to #E5C35E.

### Neutral
- **Void Black** (#0A0A0A): Page background. The canvas that makes the gold legible. Paired with a radial gradient (`rgba(212, 176, 76, 0.05)` at 80% 20%) for ambient depth.
- **Carbon Black** (#121212): Card and surface backgrounds. Slightly lifted from the void — the difference between "background" and "surface."
- **Ink Off-White** (rgb(242, 237, 228)): Primary text. Not pure white — slightly warm, slightly dimmed, like fine paper rather than a screen.
- **Muted Stone** (#CCCCCC): Secondary text, placeholder values, navigation links at rest. Recedes without disappearing.
- **Divider Steel** (#222222): Borders, dividers, structural lines. Present but never loud.
- **Input Cavity** (#1A1A1A): Form field background. Darker than cards but lighter than the void — a recessed surface that invites input.

### Accent
- **Wound Red** (#D44C4C): Error text, invalid field borders. Seldom seen but immediately legible against the dark palette.
- **Healed Green** (#4CD4A0): Success checkmarks, trust badge icons. The only verdant note in an otherwise monochromatic system.

### Named Rules
**The Bullion Rule.** Gold is used on ≤10% of any given screen. Its power comes from restraint. A page where everything is gold is a page where nothing is.

**The One Accent Rule.** Red and green are functional, not decorative. They appear only in error and success states respectively; they are never used to "add color" to a section.

## Typography

**Display Font:** Cormorant Garamond, serif (with Georgia fallback)
**Body Font:** Montserrat, sans-serif (with system-ui fallback)
**Label/Mono Font:** Montserrat (shared with body — distinct via weight, size, letter-spacing, and case)

**Character:** The pairing is authority meets utility. Cormorant Garamond brings high-contrast editorial gravitas — it belongs on a gallery wall or a leather-bound ledger. Montserrat is the precision layer: geometric, legible at small sizes, built for data and direction. Together they say "institution with sharp elbows."

### Hierarchy
- **Hero** (Cormorant Garamond, 600, clamp(2.8rem, 5.5vw, 4.2rem), 1.15): The only text at this weight and scale. Appears exactly once per page. Gold on highlighted words, italic for emphasis.
- **Section Heading** (Cormorant Garamond, 700, clamp(2.2rem, 4vw, 3.2rem), 1.15): Introduces each major section. Gold span for the italic punch word.
- **Card Title** (Cormorant Garamond, 600, 1.4rem, 1.3): Value cards, service cards headings. Smaller than section headings but still carries the serif authority.
- **Body** (Montserrat, 400, 1.1rem, 1.6, color #E0E0E0): The workhorse. Cards, paragraphs, form descriptions, benefit lists. Never drops below 1.1rem — legibility over density.
- **Eyebrow** (Montserrat, 700, 0.85rem, letter-spacing 2px, uppercase, gold): Section tags, hero eyebrow. Short, declarative, always in gold — the first thing you read before the heading.
- **Label** (Montserrat, 600-700, 10-11px, letter-spacing 2-3px, uppercase): Form labels, stat labels, nav links, footer headings. Tiny but demanding through weight and tracking.

### Named Rules
**The Two-Font Rule.** Never introduce a third font family. Cormorant and Montserrat cover every role. Icons are inline SVG — no icon fonts.

## Layout

The page is a single-column narrative stack with two-column breakouts at key moments (hero, chat demo, lead form). The max-width container is 1200px with 24px side padding.

**Section rhythm:** Every section gets 6rem (96px) of vertical padding on desktop, collapsing to 4rem on mobile. This generous whitespace makes each section feel like a deliberate chapter rather than a scroll.

**Grid patterns:**
- Two-column grids at 900px+ for hero content/mockup, chat copy/dashboard, and form copy/form — collapsing to single-column below.
- Three-column value grid at 900px+, two-column at 768px, single-column at 600px.
- Two-column services grid at 900px+, single-column below.
- Three-column footer (2:1:1 ratio), single-column on mobile.

**Breakpoints:** 900px (tablet collapse), 768px (mobile nav + full single-column), 600px (tight mobile).

**Spacing rhythm:** The spacing scale (8, 16, 24, 40, 60, 80) follows an 8px base unit with exponential feel. Component internal padding leans toward 40px/60px for luxury breathing room; compact elements (time slots, nav pills) use 16px.

## Elevation & Depth

This system uses layered elevation with soft, diffuse shadows on hover. Surfaces are flat at rest — cards sit flush against the dark canvas, distinguished only by their slightly lighter background (#121212 vs #0A0A0A) and a 1px border (#222222). On hover, surfaces lift: translateY(-2px to -4px) with a soft, colored shadow.

### Shadow Vocabulary
- **Button Lift** (hover): `0 6px 25px rgba(212, 176, 76, 0.3)` — gold-tinted lift on primary CTAs. The shadow color matches the element, not generic black.
- **Button Outline Lift** (hover): `0 4px 20px rgba(212, 176, 76, 0.15)` — lighter version for secondary/ghost buttons.
- **Phone Mockup** (ambient): `0 30px 80px rgba(0,0,0,0.7), 0 8px 30px rgba(0,0,0,0.5)` — heavy, multi-layered shadow that anchors the hero's phone mockup.
- **Dashboard Card** (ambient): `0 20px 60px rgba(0,0,0,0.5)` — permanent ambient depth for the calendar dashboard widget.
- **Teaser Box** (ambient): `0 20px 50px rgba(0,0,0,0.5)` — anchors the calculator teaser against its gradient background.

### Named Rules
**The Flat-By-Default Rule.** Surfaces are flat at rest. Shadows appear only on hover, on permanently elevated dashboard elements, or as ambient anchoring on the phone mockup. A card without an interactive surface gets no shadow.

## Shapes

The form language is angular and deliberate — a deliberate departure from rounded, friendly SaaS. Cards, buttons, and input fields use no radius or minimal radius (4px). The default corner value is zero — sharp edges project precision. Larger radii (8px, 16px) are reserved for contained widget shells (dashboard, calendar block, teaser box) where a frame-within-frame relationship matters.

The diamond (◆) is the system's recurring geometric signature — a 6px rotated square used in dividers and calendar day markers. It's the needle's tip, the tattoo machine's point, reduced to pure geometry.

**Borders** are consistently 1px solid with the exception of gold-tinted hover borders (1px solid rgba(212, 176, 76, 0.3)) and the dashed locked-action area. No double borders, no border animation — borders are structural.

## Components

### Buttons
- **Shape:** No border-radius (0) for primary, 4px for secondary/nav. Square shoulders project industrial precision.
- **Primary (Solid Gold):** Background #D4B04C, text #000, padding 16px 32px. Montserrat 700, 12px, letter-spacing 2px, uppercase. Full-width by default in forms; auto-width in hero.
- **Primary Hover:** Background brightens to #E5C35E, lifts 2px with `box-shadow: 0 6px 25px rgba(212, 176, 76, 0.3)`. Transition: all 0.3s ease.
- **Secondary (Ghost Outline):** Transparent background, 2px solid gold border, gold text. Padding 16px 32px, border-radius 4px. Montserrat 600, 12px, letter-spacing 2px, uppercase.
- **Secondary Hover:** Lifts 2px with `box-shadow: 0 4px 20px rgba(212, 176, 76, 0.15)`. No background fill — outline treatment stays outline.
- **Nav Highlight:** Transparent background, 1px solid gold border, gold text. Padding 10px 20px, border-radius 4px. Montserrat 700, 11px, letter-spacing 2px.
- **Nav Highlight Hover:** Inverts — fills gold, text goes black. Lifts 2px.

### Cards
- **Corner Style:** Sharp (no border-radius).
- **Background:** #121212 (bg-card), 1px solid #222222 (border-color) border.
- **Shadow Strategy:** Flat at rest. Hover lifts translateY(-3px to -4px) with gold-tinted border transition (border-color shifts to rgba(212, 176, 76, 0.25-0.3)).
- **Top Accent (Value Cards only):** A 1px gold gradient line (transparent → gold → transparent) appears on hover via `::before` pseudo-element.
- **Internal Padding:** 60px 40px (xl lg) on value cards, variable on service cards.
- **Stat Footer:** Separated by a 1px border-top. Stat number in Cormorant Garamond (1.8rem, 700, gold), label in Montserrat (10px, uppercase, muted).

### Inputs / Fields
- **Style:** Background #1A1A1A, 1px solid #333 border, border-radius 4px, padding 16px 20px (14px for compact form variant). Text in Montserrat 15px, color text-main. Placeholder #555.
- **Prefix:** Gold-colored inline prefix (R$, @) positioned absolutely at left: 20px — the field padding accommodates it (50px left when prefixed).
- **Focus:** Border shifts to gold with `box-shadow: 0 0 0 1px var(--gold)`. No outline, no glow animation.
- **Error:** Border turns #D44C4C with !important. Error text in 11px red below the field.
- **Label:** Displayed above field in Montserrat 11px, 600, letter-spacing 2px, uppercase, color muted.

### Navigation
- **Bar:** Fixed top, `rgba(10, 10, 10, 0.9)` with 12px backdrop-filter blur. 1px bottom border (#222222). Padding 16px 0.
- **Desktop:** Horizontal link list with 28px gap. Links in Montserrat 12px, 500, letter-spacing 1px, uppercase, color muted. Hover to gold.
- **Mobile:** Hamburger icon (3 × 22px bars) reveals full-screen overlay on click — `rgba(10, 10, 10, 0.98)` with blur, centered vertical link stack, 32px gap, links at 15px in text-main.
- **Active state:** Gold color on the current-section link (via scroll spy or active class).

### Section Tag (Eyebrow)
A recurring pattern: small gold label above every section heading. Montserrat 10px, 700, letter-spacing 3px, uppercase, gold color, inline-block with 16px bottom margin.

### Diamond Divider
A horizontal rule composed of three elements: a 40px line on each side (color border-color, 1px), and a 6px gold rotated square in the center. Used between form sections and as a visual rhythm break. The diamond is CSS-only (no image, no SVG).

### Horizontal Rule (Section Boundary)
A 1px gradient line (transparent → gold-dark → transparent) across the full width at section transitions. Used at the top of services section, form section, and calculator teaser section.

## Do's and Don'ts

### Do:
- **Do** use gold sparingly — on exactly one CTA per viewport fold, on the diamond divider, and as the italic highlight in section headings. Gold is the precious metal; treat it like one.
- **Do** maintain 6rem section padding on desktop and 4rem on mobile. Generous whitespace is the luxury signal.
- **Do** pair Cormorant headings with Montserrat body in every text block. The pairing is the system's voice — breaking it reads as off-brand.
- **Do** use fade-in-up animations with staggered delays (delay-1: 0.2s, delay-2: 0.4s, delay-3: 0.6s) on section entrance. The reveal must feel earned.
- **Do** keep cards sharp-edged (no radius). Rounded corners are reserved for contained widget shells (dashboard, calendar block, teaser box) where 8px-16px radius signals a frame-within-frame relationship.
- **Do** lift interactive surfaces on hover with translateY(-2px to -4px) and a gold-tinted box-shadow. Depth is earned by interaction.

### Don't:
- **Don't** introduce a third font family. Cormorant Garamond and Montserrat cover every typographic role.
- **Don't** use red or green for decoration. `--accent-red` and `--accent-green` are functional signals — error and success only.
- **Don't** add a border-radius to primary buttons, cards, or the qualifying form. Square shoulders are the system's posture.
- **Don't** use drop shadows on static cards. Shadows are a hover response or a permanent ambient anchor for elevated widgets — never a default.
- **Don't** use emoji, rounded blob illustrations, neon colors, or glitch animations. This is an atelier, not a nightclub or a SaaS dashboard.
- **Don't** reduce body text below 1.1rem. The audience reads on phones between tattoo sessions — prioritize legibility over fitting more copy.
