# Implementation Plan: Artist Landing Pages (#24)

## Summary

Create a PHP front-controller at `/artists/index.php` that reads artist config files and renders a full landing page with 8 sections. Each section is conditionally rendered based on config data presence (except FAQ).

---

## Architecture overview

```
packages/lp/artists/
├── index.php              ← front-controller (routing + render)
├── config/
│   ├── .gitkeep
│   └── joao-silva.php     ← example config matching prototype
```

- **Front-controller** follows the `onboard/index.php` pattern: `declare(strict_types=1)`, `parse_url()` routing, self-contained PHP with inline HTML rendering via heredocs.
- **Config files** are standalone PHP arrays returned via `return [...]`. The front-controller loads them via `$artist = include "config/{$slug}.php"`.
- **No framework, no router library.** Pure PHP matching the existing codebase conventions.
- **CSS** lives inline in the front-controller (matching the prototype), with `style.css` linked for shared design tokens.
- **Tracking** copies the Facebook Pixel + Matomo snippets verbatim from `index.php`.

---

## Changes (dependency order)

### 1. Create `artists/config/` directory

**Files:** `artists/config/.gitkeep`

**What:** Create the directory with a `.gitkeep` placeholder.

**Why:** Config files go here. Git needs at least one file to track the directory. Keeps the pattern consistent with the rest of the repo (no empty directories committed).

---

### 2. Create example config — `artists/config/joao-silva.php`

**What:** A PHP file returning an associative array matching the config schema from the issue. Populated with the exact data from the prototype (`joao-silva` slug, display name "João Silva", etc.).

**Fields:** `slug`, `display_name`, `instagram_handle`, `style`, `profile_photo`, `whatsapp_number`, `hero_headline`, `hero_subheadline`, `portfolio` (array), `bio` (markdown string), `cta_text`, `testimonials` (array), `instagram_feed` (bool), `faq` (array), `location` (associative array with `street`, `neighborhood`, `city`, `state`, `zip`, `maps_embed_url`).

**Why:** Serves as both the schema reference and the acceptance test target. All fields populated so the rendered page exercises every section.

---

### 3. Create front-controller — `artists/index.php`

**What:** A single PHP file (~1200-1500 lines estimated) that:

#### 3a. Bootstrap + routing (top of file)
- `declare(strict_types=1)`
- `parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)` → extract slug from `/artists/<slug>`
- Validate slug: alphanumeric + hyphens only, non-empty → else 404
- Load config: `$config_path = __DIR__ . "/config/{$slug}.php"` → `file_exists()` check → else 404
- `$artist = include $config_path` — validate required fields (`slug`, `display_name`, `whatsapp_number`) → else 500

#### 3b. Default value computation (PHP helpers before rendering)
- `hero_headline`: if missing from config, auto-generate as `"{display_name} — Tatuador {style}"`  
- `hero_subheadline`: if missing, auto-generate from `style` + `display_name`  
- `cta_text`: if missing, default to `"Agende sua sessão pelo WhatsApp"`  
- WhatsApp link template: `https://wa.me/{number}?text=Ola,%20vim%20pelo%20seu%20site%20no%20vaif.com.br`
- Matomo event tag: `Artista / CTA_WhatsApp / {slug}`
- Image base path: `artists/{slug}/media/`
- FAQ merging: artist-specific FAQ array merged on top of site-wide defaults (the 6 questions from the prototype), artist entries override defaults by matching `question` key. Always at least the defaults present.

#### 3c. Section visibility flags (boolean PHP vars)
Compute once before rendering:
- `$show_hero = true` (always)
- `$show_portfolio = !empty($artist['portfolio'])`  
- `$show_about = !empty($artist['bio'])`  
- `$show_testimonials = !empty($artist['testimonials'])`  
- `$show_instagram = !empty($artist['instagram_feed'])`  
- `$show_faq = true` (always, with fallback defaults)  
- `$show_location = !empty($artist['location'])`  

#### 3d. HTML document (heredoc rendering)
Render a complete HTML5 document. Structure (top to bottom):

##### `<head>`
- `<meta charset="UTF-8">`, viewport meta
- `<title>{display_name} — Tatuador {style} em {city} | VAIF</title>` (omit city if no location)
- Meta description: auto-generated from artist data (e.g. `"{display_name} — Tatuador especialista em {style} em {city}. Agende sua sessão pelo WhatsApp."`)
- Open Graph tags: `og:title`, `og:description`, `og:image` (from `profile_photo` or first portfolio image), `og:type` = `website`
- Twitter Card: `twitter:card` = `summary_large_image`, `twitter:title`, `twitter:description`, `twitter:image`
- JSON-LD structured data (three blocks):
  - `Person` (for `display_name`, with `sameAs` = Instagram URL if handle present)
  - `LocalBusiness` (if `location` present — uses location fields)
  - `FAQPage` (only with the rendered FAQ pairs)
- Google Fonts preconnects + Cormorant Garamond + Montserrat (same as `index.php`)
- Facebook Pixel snippet (exact copy from `index.php` lines 12-24)
- Matomo snippet (exact copy from `index.php` lines 25-36)
- Facebook Pixel `<noscript>` fallback (copy from `index.php` line 37)
- Favicon links (copy from `index.php` lines 38-44)
- `<link rel="stylesheet" href="/style.css">` — reuses shared design tokens
- Inline `<style>` block with artist-page-specific CSS (all 8 section styles from the prototype). Organised with section comments matching the prototype (Hero, Portfolio, About, Booking CTA, Testimonials, Instagram, FAQ, Location, plus nav, footer, responsive, skip-link, back-to-top, mobile CTA bar).

##### `<body>`
- Skip-to-content link
- Fixed navbar (VAIF logo + section anchor links — Portfolio, Sobre, Depoimentos, FAQ, Local — populated dynamically based on `$show_*` flags)
- `<main id="main-content">` wrapping all 8 sections

**Section 1: Hero** (always renders)
- Full-viewport background image (`profile_photo` inline as `<img>` with overlay gradients matching prototype CSS)
- Eyebrow text: auto-generated style description, e.g. `"Tatuador Especialista em {style}"`
- `<h1>`: display name with gold italic span on last name
- Subheadline paragraph
- CTA buttons: WhatsApp (primary, gold fill) → anchors to `#booking`

**Section 2: Portfolio** (hidden if empty)
- Section header with tag "Portfólio", heading, tattoo-divider
- 3-column grid (responsive → 2 → 2) of square images from `portfolio` array
- Each image: `src="artists/{slug}/media/{filename}"`, `loading="lazy"`, `alt` auto-generated

**Section 3: About** (hidden if empty)
- Section header with tag "Sobre o Artista", heading with artist name
- Two-column grid (photo left, text right; stacks on mobile)
- Profile photo from `profile_photo` with `artists/{slug}/media/` prefix
- Bio text rendered as-is (config contains HTML or plain text; support basic markdown via a simple `nl2br` + auto-link approach — no Markdown parser dependency)
- Specialty tags from `style` field (split by comma/pipe)

**Section 4: Booking CTA** (always renders)
- Dark gradient background with radial gold glow (matching prototype `#booking` styles)
- Booking box card: label "Agende Sua Sessão", heading, description paragraph
- WhatsApp button with SVG icon, `href=` the WhatsApp link template
- **Matomo onclick**: `_paq.push(['trackEvent', 'Artista', 'CTA_WhatsApp', '{slug}'])`  
- `target="_blank" rel="noopener noreferrer"`

**Section 5: Testimonials** (hidden if empty)
- Section header "Depoimentos" / "O que meus clientes dizem"
- 3-column card grid (collapses to 1 on mobile)
- Each card: 5-star rating, quote text in italics, author with avatar initials + name
- If `photo` field present in testimonial item, use `<img>` instead of initials

**Section 6: Instagram Feed** (hidden if `instagram_feed` is falsy or absent)
- Section header "Instagram" / "Acompanhe o dia a dia"
- 4-column image grid (collapses to 3 on mobile) — **static placeholder images** (no live API call). Use `placehold.co` squares labeled with post numbers.
- Instagram handle link: `https://instagram.com/{instagram_handle}` (open in new tab)
- Note: Instagram's API requires auth; placeholder images are acceptable for MVP per prototype

**Section 7: FAQ** (always renders)
- Section header "Dúvidas Frequentes" / "Perguntas que sempre recebo"
- Accordion list using CSS `grid-template-rows: 0fr` → `1fr` animation (from prototype)
- First item open by default (`aria-expanded="true"`, `.active` class)
- Each item: `<button>` question + `<div>` answer
- Toggle logic: minimal inline JS (click handler on `.faq-question` toggles `.active` on parent `.faq-item` and updates `aria-expanded`)

**Section 8: Location** (hidden if absent)
- Section header "Localização" / "Onde estou atendendo"
- Two-column grid (details left, map right; stacks on mobile)
- Location details card: studio name heading, `<address>` with formatted address, info items (transit, parking, etc. if provided)
- Google Maps iframe: `<iframe src="{maps_embed_url}">` with grayscale filter CSS (from prototype)

##### Post-sections
- Closing CTA section (repeat booking CTA at bottom before footer)
- Footer: VAIF branding + copyright
- Back-to-top button (fixed, bottom-right)
- Sticky mobile CTA bar (visible ≤768px, fixed bottom)
- Minimal JS: hamburger menu toggle, FAQ accordion, back-to-top visibility on scroll, smooth scroll for anchor links, mobile nav close on link click

#### 3e. 404 path
If slug is empty, invalid, or config file not found:
- `http_response_code(404)`
- Render a minimal 404 page using the same design system (dark theme, serif heading, gold accent) with message "Artista não encontrado" and a link back to vaif.com.br

---

### 4. Add acceptance tests

**File:** `tests/acceptance_test.php` (append)

**What:** New test block that:
- Starts a PHP dev server pointing at `artists/` if not already running
- Fetches `/artists/joao-silva` (the example config) and `/artists/nonexistent`
- Assertions:
  - `/artists/joao-silva` returns 200, HTML contains "João Silva", all 8 section IDs present, WhatsApp link contains correct number, Matomo event present in source, JSON-LD blocks present
  - `/artists/nonexistent` returns 404
  - `/artists/` (no slug) returns 404

---

## Ambiguities / open questions

1. **Markdown rendering for bio**: The config specifies `bio` as "markdown." The prototype uses plain HTML paragraphs. **Decision**: Accept HTML in the bio field (artists write raw HTML or line-break-separated paragraphs). A simple `nl2br()` + auto-link approach. No Composer dependency on a Markdown parser — keeps it zero-dependency matching the rest of the codebase.

2. **Instagram Feed — live or static**: The `instagram_feed` boolean toggles a section. But there's no Instagram API integration in scope. **Decision**: Render static placeholder images (matching prototype) when the section is enabled. The Instagram handle link is real; the grid images are placeholders. This is acceptable for MVP — the section proves the config toggle works.

3. **FAQ merging logic**: "Per-artist entries merged with site-wide defaults." **Decision**: Hard-code the 6 default FAQ questions (from the prototype) in the front-controller. Artist config's `faq` array entries override defaults where the `question` string matches exactly. Any artist-only entries are appended. This keeps it simple — no external FAQ config file needed.

4. **Image paths**: Config stores relative paths like `hero.jpg`. The front-controller prepends `artists/{slug}/media/`. **Decision**: The config author puts just the filename; the template constructs the full path. This keeps configs portable and avoids hard-coded paths.

5. **Nginx routing**: Issue #22 (blocked by) handles the nginx rewrite so `/artists/<slug>` hits `/artists/index.php`. This plan assumes that is in place or will be done separately. The front-controller is self-contained and works under `php -S` for development regardless.

6. **Testing approach**: No PHPUnit or framework in this repo. Acceptance tests are curl-based PHP scripts. **Decision**: Follow the existing `tests/acceptance_test.php` pattern — PHP script with `test()` helper that curl-fetches pages and asserts on string content.

---

## Existing test impact

- `tests/acceptance_test.php` — append new artist page test block (no modifications to existing tests)
- `tests/onboard_acceptance_test.php` — no impact (separate concern)
- `tests/e2e.mjs` — no impact (separate concern)
