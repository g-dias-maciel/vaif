# Artist landing pages — config loader, template, front-controller

Labels: implementation

## Parent

[Build Spec: Blog + Artist Landing Pages for vaif-lp](https://github.com/g-dias-maciel/vaif/issues/20)

## What to build

A PHP front-controller at `/artists/index.php` that renders a config-driven artist landing page with 8 sections. Each artist has a PHP config file; the template renders whichever sections have data.

## Acceptance criteria

### Artist config loader
- [ ] Loads `artists/config/{slug}.php` via `$artist = include "config/{$slug}.php"`
- [ ] Validates required fields are present (`slug`, `display_name`, `whatsapp_number`)
- [ ] Returns structured artist data for template rendering
- [ ] Missing config file returns 404

### Artist page template (8 sections, each hidden if data is absent except FAQ)
- [ ] **Hero** — artist photo, display name, headline, subheadline. Auto-generated headline/subheadline from identity fields if not provided in config
- [ ] **Portfolio** — grid or carousel of tattoo work images from `portfolio` array. Hidden if `portfolio` absent or empty
- [ ] **About/Bio** — markdown-rendered bio text. Hidden if `bio` absent
- [ ] **Booking CTA** — WhatsApp button as primary conversion. Link template: `https://wa.me/{number}?text=Ola, vim pelo seu site no vaif.com.br`. Default CTA text if `cta_text` absent. Matomo fires `Artista / CTA_WhatsApp / {slug}` onclick
- [ ] **Testimonials** — client reviews with optional photo. Hidden if `testimonials` absent
- [ ] **Instagram Feed** — boolean toggle (`instagram_feed`). When enabled, section renders; when absent/false, hidden
- [ ] **FAQ** — accordion of question/answer pairs (per-artist entries merged with site-wide defaults). Always renders
- [ ] **Studio Location** — address text + embedded Google Map iframe from `location.maps_embed_url`. Hidden if `location` absent

### Design
- [ ] Uses vaif-lp design system: CSS custom properties, Cormorant Garamond + Montserrat fonts, dark theme, gold accents
- [ ] Visual reference: `.scratch/artist-landing/prototype/artist-page.html`
- [ ] Mobile-first responsive (breakpoints at 900/768/600px)
- [ ] Images referenced relative to `artists/{slug}/media/`

### General
- [ ] Front-controller uses `onboard/index.php` pattern: `parse_url()` + manual routing
- [ ] `declare(strict_types=1)`
- [ ] Facebook Pixel + Matomo tracking snippets
- [ ] LocalBusiness + Person + FAQPage JSON-LD in `<head>`
- [ ] Open Graph + Twitter Card meta tags in `<head>`

## Blocked by

- [Nginx routing for /blog and /artists](https://github.com/g-dias-maciel/vaif/issues/22)
