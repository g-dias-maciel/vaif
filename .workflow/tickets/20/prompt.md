## Task: Create an implementation plan for ticket #20

Read the ticket below, explore the codebase to understand current state,
and write a concrete implementation plan.

### Ticket
# Build Spec — Blog + Artist Landing Pages for vaif-lp

Labels: implementation

## Problem Statement

vaif.com.br has a single landing page and a calculator funnel — no content infrastructure. The agency needs to attract organic search traffic (Google + AI search) and close artists with dedicated landing pages, but there is no blog, no artist pages, and no automated page-creation pipeline. Every new artist means a manual PHP file. The calculadora.php codebase has significant duplication (~1,200 lines of inline CSS/JS copied from shared files) that makes maintenance brittle.

## Solution

Add a blog and artist landing-page system to vaif-lp that:
- Renders AI-authored markdown blog posts via PHP with proper SEO metadata
- Generates config-driven artist pages from PHP config files with 8 configurable sections
- Creates artist pages automatically via an authenticated n8n webhook endpoint on contract close
- Refactors calculadora.php to eliminate code duplication (extract shared CSS/JS to external files)
- Produces dynamic XML sitemaps covering blog posts and artist pages
- Emits JSON-LD structured data (BlogPosting, LocalBusiness, Person, FAQPage, Organization) for Google + AI search visibility

Target scale: 5–20 artists in 6 months. No CMS, no framework, no build step — pure PHP with the existing design system.

## User Stories

### Blog

1. As a site visitor, I want to browse a blog listing page at `/blog` so I can discover educational content about tattoo studio management and growth
2. As a site visitor, I want to read a full blog post at `/blog/<slug>` with clean typography and readable layout
3. As a site visitor, I want blog posts to load fast on mobile so I can read on the go
4. As an SEO strategist, I want each blog post to emit BlogPosting JSON-LD structured data so Google can index it correctly
5. As an SEO strategist, I want a dynamic `/sitemap.xml` that lists all published blog posts
6. As a content editor, I want to write blog posts as simple markdown files with YAML frontmatter so I can focus on writing, not HTML
7. As a content editor, I want to save a blog post as a draft (`draft: true`) so I can preview it before publishing
8. As a content editor, I want to see blog posts ordered by date (newest first) on the listing page
9. As a content editor, I want draft posts hidden from the listing page and unreachable at their URL
10. As a developer, I want missing or malformed frontmatter to skip the post gracefully (warning log, no error page) so one bad file does not break the blog

### Artist Pages

11. As a prospective client, I want to see an artist's portfolio, biography, testimonials, and studio location on a single page at `/artists/<slug>` so I can decide whether to book
12. As a prospective client, I want to click a "Book via WhatsApp" button that opens WhatsApp with a pre-filled message so I can inquire instantly
13. As a prospective client, I want to see client testimonials to build trust in the artist
14. As a prospective client, I want to read an FAQ section to get answers before contacting the artist
15. As a prospective client, I want to see the studio location on an embedded Google Map so I know where to go
16. As an artist, I want my Instagram feed displayed on my page so clients can see my latest work
17. As an SEO strategist, I want artist pages to emit LocalBusiness + Person + FAQPage JSON-LD so they rank for "[artist name] tatuador" searches
18. As an SEO strategist, I want a dynamic sitemap that lists all artist pages
19. As an admin, I want to create an artist page by calling a secure API endpoint (so n8n can create it automatically on contract close)
20. As an admin, I want the API endpoint to reject requests without a valid X-Api-Key so unauthorized creation is blocked
21. As an admin, I want the API endpoint to validate input fields (slug, display_name, whatsapp_number) so bad data does not corrupt the system
22. As a visitor using a screen reader, I want artist pages to be navigable and accessible

### Calculadora Refactor

23. As a developer, I want calculadora.php to load shared CSS from external files instead of inlining 1,200+ lines so I can maintain styles in one place
24. As a developer, I want calculadora.php to load shared JS from external files instead of duplicating calculator.js and main.js logic
25. As a product owner, I want the calculator funnel to behave identically after the refactor — same conversion rate, same UX
26. As a tester, I want the refactored calculadora-v2.php to pass all existing acceptance tests before the old page is swapped out

### Sitemap + SEO

27. As an SEO strategist, I want a dynamic `/sitemap.xml` that automatically includes new blog posts and artist pages without manual updates
28. As an SEO strategist, I want `robots.txt` to allow AI-search crawlers (OAI-SearchBot) while blocking training crawlers (GPTBot, Google-Extended)

## Implementation Decisions

### Architecture — front-controller with path parsing

Both blog and artist pages use the `onboard/index.php` front-controller pattern: a single `index.php` per directory that parses `$_SERVER['REQUEST_URI']` and routes manually. This avoids any framework dependency while enabling clean URLs.

**Nginx rules** (Coolify custom Nginx config):
```nginx
location /blog/ { try_files $uri $uri/ /blog/index.php?$args; }
location /artists/ { try_files $uri $uri/ /artists/index.php?$args; }
```

**URL structure:**
- `/blog` — post listing (archive page)
- `/blog/<post-slug>` — individual post
- `/artists/<artist-slug>` — individual artist page
- `/` and `/calculadora/` — existing pages, unchanged

### Blog storage — markdown files with YAML frontmatter

Blog posts are `.md` files in `content/blog/`, each with a YAML frontmatter block delimited by `---`. A ~20-line PHP parser extracts metadata and body content. No external YAML library — the format is minimal flat YAML (`key: value` per line).

**Directory layout:**
```
content/blog/
├── aumentar-faturamento-tatuador.md
├── como-escolher-estilo-tatuagem.md
├── images/
│   └── aumentar-faturamento.jpg
└── ...
```

**Frontmatter schema** (from the prototype):
```yaml
---
title: Como aumentar seu faturamento como tatuador
date: 2026-08-01
slug: aumentar-faturamento-tatuador
description: Meta description for SEO
author: VAIF
featured_image: images/aumentar-faturamento.jpg
tags: faturamento, estrategia, negocios
category: Negocios
draft: false
---
```

**Required fields:** `title`, `date` (YYYY-MM-DD format).  
**Optional fields:** `slug` (auto-derived from title if missing: lowercase, hyphens, ASCII-stripped), `description` (defaults to first ~160 chars of body), `author`, `featured_image`, `tags` (comma-separated, freeform), `category` (freeform), `draft` (absent or `false` = published).  
**Draft handling:** `draft: true` hides the post from listing and makes it unreachable at its URL (returns 404/redirect).  
**Error handling:** missing required fields → logged warning, post skipped. No error pages.

**BlogPosting JSON-LD schema** is derived from frontmatter + body. No dedicated frontmatter fields are needed.

### Blog listing page — archive view

The `/blog` page renders a list of published posts (newest first) with: post title, date, description excerpt, tags/category badges, featured image thumbnail. No pagination for initial release — simple list. Draft posts excluded.

### Artist page template — 8 config-driven sections

Each artist page is rendered from a PHP config file via `$artist = include "config/{$slug}.php"`. The template renders 8 sections. Each section is hidden if its config data is absent (except FAQ, which always renders — it merges per-artist entries with site-wide defaults).

**Section order:**
1. **Hero** — artist photo, display name, headline, subheadline
2. **Portfolio/Gallery** — grid or carousel of tattoo work images
3. **About/Bio** — artist story and experience (markdown rendered)
4. **Booking CTA** — WhatsApp button as the primary conversion action
5. **Testimonials** — client reviews with optional photo
6. **Instagram Feed** — boolean toggle; when enabled, embeds the artist's Instagram grid
7. **FAQ** — accordion of questions/answers (per-artist entries merged with site-wide defaults)
8. **Studio Location** — address + embedded Google Map iframe

**Config file directory layout:**
```
artists/
├── config/
│   ├── joao-silva.php
│   ├── maria-souza.php
│   └── ...
├── joao-silva/
│   └── media/
│       ├── hero.jpg
│       ├── portfolio/
│       │   ├── 1.jpg
│       │   └── 2.jpg
│       └── testimonials/
│           └── a.jpg
├── index.php          ← front-controller
└── ...
```

**Artist config schema** (PHP return array):
```php
return [
    // Required identity
    "slug"             => "joao-silva",
    "display_name"     => "Joao Silva",
    "instagram_handle" => "joaosilvatattoo",
    "style"            => "Realismo Preto e Cinza",
    "profile_photo"    => "media/hero.jpg",
    "whatsapp_number"  => "5511999999999",

    // Hero (auto-generated defaults if n8n omits)
    "hero_headline"    => "Joao Silva — Realismo Preto e Cinza",
    "hero_subheadline" => "Transformo ideias em arte na pele ha 12 anos em Sao Paulo",

    // Optional sections
    "portfolio"        => ["media/portfolio/1.jpg", "media/portfolio/2.jpg"],
    "bio"              => "Markdown bio text...",
    "cta_text"         => "Agende sua consultoria gratuita",
    "testimonials"     => [
        ["name" => "Cliente A", "text" => "...", "photo" => "media/testimonials/a.jpg"],
    ],
    "instagram_feed"   => true,
    "faq"              => [
        ["question" => "Quanto custa uma tatuagem realista?", "answer" => "..."],
    ],
    "location" => [
        "street"         => "Rua Augusta, 1234",
        "neighborhood"   => "Consolacao",
        "city"           => "Sao Paulo",
        "state"          => "SP",
        "zip"            => "01304-001",
        "maps_embed_url" => "https://www.google.com/maps/embed?...",
    ],
];
```

**Key design decisions:** Images stored relative to artist directory (`artists/{slug}/media/`). FAQ always renders — per-artist entries merge with site-wide defaults. CTA WhatsApp link template: `https://wa.me/{number}?text=Ola, vim pelo seu site no vaif.com.br`. Matomo fires `Artista / CTA_WhatsApp / {slug}` on CTA click (derived from config, not stored). n8n auto-generates hero headline/subheadline from identity fields if not provided.

**Design system adherence:** The artist page must use the vaif-lp design system — CSS custom properties (`--gold`, `--bg-dark`, `--bg-card`, `--text-main`, `--text-muted`, `--border-color`), Cormorant Garamond + Montserrat fonts, gold accent, dark theme, mobile-first responsive. The prototype at `.scratch/artist-landing/prototype/artist-page.html` demonstrates the intended layout and styling.

### n8n page creation endpoint — `POST /api/artists/create.php`

A secure PHP endpoint that n8n calls when a contract closes:

- **Auth:** `X-Api-Key` header checked against `API_CREATE_KEY` env var
- **Input:** JSON body with artist config fields (same schema as config file)
- **Behavior:** Creates `artists/config/{slug}.php` with `<?php return [...];`, creates `artists/{slug}/media/` directory, writes config atomically (temp file + rename)
- **Validation:** Required fields (`slug`, `display_name`, `whatsapp_number`) are validated; rejected with 422 if missing/invalid
- **Response:** `{ success: true, url: "/artists/{slug}" }` or `{ success: false, error: "..." }`
- **Error handling:** Atomic writes with rollback on failure; errors logged via `error_log()`

**Prerequisite:** A persistent Docker volume mounted at the artists directory so config files survive Coolify redeploys.

### Calculadora refactor — side-by-side replacement

The refactor follows a 5-step incremental plan, building `calculadora-v2.php` alongside the existing `calculadora.php`:

**Step 1 — Fix CSS incompatibility:** Unify the `--text-muted` value between `style.css` and calculadora's inline styles. Both pages use the IntersectionObserver (from main.js) for fade-in animations.

**Step 2 — Extract core JS functions:** Move `handleLeadSubmitCore()` and `mostrarTelaSucessoCore()` (validate, API call, branching logic) into `js/calculator.js`. Update `index.php` inline scripts to use them.

**Step 3 — Extract calculadora-specific CSS:** ~47 selectors (carousel, confirmation, specialist card, analyzing overlay) into `css/calculadora.css`. Calculadora page loads both `style.css` and `calculadora.css`.

**Step 4 — Create calculadora-page.js:** Wraps core functions from `calculator.js` with calculadora-specific DOM (analyzing overlay, progress bar, confirmation page). Calculadora loads: `main.js` + `calculator.js` + `calculadora-page.js`.

**Step 5 — Build calculadora-v2.php:** Loads 5 shared files (`style.css`, `calculadora.css`, `main.js`, `calculator.js`, `calculadora-page.js`), zero inline CSS/JS. Swapped in only after all tests pass.

**Side-by-side safety:** `calculadora.php` stays live during development. `calculadora-v2.php` replaces it only when acceptance + E2E tests pass identically on both.

### SEO — structured data, sitemaps, and AI search

**Structured data (JSON-LD inline `<script>` tags):**
- **Blog posts:** `BlogPosting` schema derived from frontmatter + body
- **Artist pages:** `LocalBusiness` + `Person` + `FAQPage` (with `BreadcrumbList` for hierarchy)
- **Umbrella brand** (on relevant pages): `Organization` with `sameAs` links to social profiles for entity optimization
- **FAQ schema:** Per-artist FAQ entries are the primary AI-search lever (Google AI Overviews / ChatGPT / Perplexity)

**Sitemaps:** A dynamic PHP script at `/sitemap.xml` that:
1. Scans `content/blog/*.md` for published, non-draft blog posts
2. Scans `artists/config/*.php` for active artist configs
3. Outputs valid XML sitemap with `<lastmod>`, `<changefreq>`, and `<priority>` per URL

**robots.txt updates:** Allow OAI-SearchBot; disallow GPTBot and Google-Extended.

**Meta tags:** Every page includes canonical URL, Open Graph (title, description, image), and Twitter Card meta tags.

**Asset optimization:** WebP images where possible, `srcset` for responsive images, `loading="lazy"`, semantic HTML.

### Shared design patterns

**New pages follow the `onboard/index.php` pattern:**
- `declare(strict_types=1)` at the top
- `parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)` for route parsing
- Function-scoped helpers (no classes)
- Inline `<style>` or external CSS (following the design system)
- Inline `<script>` or external JS (IIFE modules, `DOMContentLoaded` bootstrap)
- Facebook Pixel + Matomo tracking snippets

**New API endpoints follow the `api/leads/submit.php` pattern:**
- `getenv()` for credentials
- PDO for MySQL
- JSON response with `{ success: bool }` shape
- `error_log()` for errors
- Graceful degradation — never block the caller on non-critical failure

**Components:** Reusable PHP partials (nav, footer, hero section fragment) may be created in `components/` if shared across multiple pages.

## Testing Decisions

### What makes a good test
- Test external behavior (HTTP response, HTML structure, JSON shape), not implementation details
- Test the seams defined in this spec — the HTTP endpoints where requests enter the system
- Use curl-based PHP acceptance tests (matching existing pattern in `tests/acceptance_test.php`)
- Use Playwright for browser-level E2E (matching existing pattern in `tests/e2e.mjs`)

### Test suite breakdown

| Test file | Seam tested | What it validates |
|---|---|---|
| `tests/acceptance_test.php` (extended) | All HTTP pages | Blog listing loads, blog post renders, artist pages render, calculadora-v2 parity |
| `tests/artist_acceptance_test.php` (new) | `/artists/<slug>` | All 8 sections render, JSON-LD present, WhatsApp CTA link correct, 404 on missing artist, accessible navigation |
| `tests/blog_acceptance_test.php` (new) | `/blog`, `/blog/<slug>` | Listing excludes drafts, post renders markdown, frontmatter-derived meta tags, BlogPosting schema |
| `tests/api_acceptance_test.php` (new) | `POST /api/artists/create.php` | Auth rejection, required field validation, successful creation, idempotent (re-creating same slug) |
| `tests/sitemap_acceptance_test.php` (new) | `/sitemap.xml` | Valid XML, lists blog posts, lists artist pages, correct lastmod |
| `tests/e2e.mjs` (extended) | Full browser flow | Blog post renders correctly styled, artist page WhatsApp CTA works, calculadora-v2 conversion funnel intact |

### Existing test reuse
- All existing acceptance tests must pass unchanged on `index.php` and `calculadora.php`
- `calculadora-v2.php` must pass the same tests as `calculadora.php` before swap
- New PHP tests follow the `assert_*()` helper pattern from `tests/acceptance_test.php` (self-contained, no test framework dependency)

## Out of Scope

- Pagination for blog listing (simple list suffices for initial release; pagination is a follow-up)
- Image upload/processing in the n8n webhook (minimal viable: creates config file only; images are uploaded separately)
- Admin UI for managing artist pages or blog posts (management is via file system + n8n)
- Blog post RSS/Atom feed
- Category/tag filtering on the blog listing page
- Artist page analytics tracking beyond Matomo CTA click (full funnel tracking is a separate PRD)
- Payment double-check in the n8n webhook (the minimal version creates on contract close trigger alone)
- `llms.txt` file generation (zero-cost supplement for non-Google AI crawlers; add later if needed)

## Further Notes

- The artist landing page prototype at `.scratch/artist-landing/prototype/artist-page.html` is the visual reference for the PHP template — it demonstrates the intended layout, typography, color usage, and section ordering
- SEO research at `.scratch/blog-artist-pages/research/05-seo-strategy.md` contains concrete JSON-LD snippets for each schema type
- n8n research at `.scratch/blog-artist-pages/research/04-n8n-page-creation.md` contains the full n8n workflow design
- The persistent Docker volume in Coolify is a deployment infrastructure prerequisite — it must be configured before the n8n endpoint goes live
- TDD is mandatory per CLAUDE.md: write tests before implementation code
- The `calculadora-v2.php` refactor is the riskiest change — it touches the primary lead-gen funnel; build it behind the old page and swap only after tests pass
- All artist page images reference paths relative to the artist's directory (`artists/{slug}/media/`), not absolute URLs — the PHP renderer resolves these at render time

### Rules
- Output ONLY the plan — no implementation code
- For each change: what file(s), what change, why
- List changes in dependency order
- Flag any ambiguity or missing information
- Note which existing tests will need updating

### Output
Write the plan to `.workflow/tickets/20/plan.md`
