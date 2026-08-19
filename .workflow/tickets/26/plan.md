# Implementation Plan: SEO — structured data, sitemap, robots.txt

## Findings from codebase exploration

- **robots.txt**: Does not exist anywhere in the repo. Will be created from scratch.
- **SEO research**: `.scratch/blog-artist-pages/research/05-seo-strategy.md` does not exist. JSON-LD schemas will be built from the ticket's acceptance criteria and Schema.org best practices.
- **Existing meta tags**: `index.php` and `calculadora.php` have `<meta charset>` and `<meta name="viewport">` only. No canonical, OG, Twitter Card, or structured data exists. This ticket should add them.
- **Routing**: No `.htaccess` exists. The server (Coolify/nginx) serves `.php` files directly. The `/sitemap.xml` URL needs a rewrite — either via nginx config or by naming the file `sitemap.xml` and configuring PHP to process `.xml` extensions, or by using `sitemap.php` with an nginx rewrite rule.
- **Existing patterns**: Pages are plain PHP files. Components use `<?php include ... ?>`. Dynamic data follows the `onboard/index.php` pattern — server-side PHP rendering with `render_html()` helper.
- **Tests**: PHP curl-based acceptance tests (`tests/acceptance_test.php`) and Playwright E2E (`tests/e2e.mjs`). Both expect a local PHP server at `localhost:8000`.
- **Blockers**: Tickets #23 (blog system) and #24 (artist landing pages) must land first — they create `content/blog/*.md` and `artists/config/*.php` that the sitemap and structured data depend on.

---

## Changes (dependency order)

### 1. Server routing: map `/sitemap.xml` to PHP handler

**File:** `packages/lp/sitemap.php` (new)
**Server config:** Coolify/nginx — add rewrite rule mapping `/sitemap.xml` → `sitemap.php`

**What:** Create a PHP script that outputs a dynamic XML sitemap at `/sitemap.xml`. Since the server serves `.php` files directly and no `.htaccess` exists, the simplest path is to name the file `sitemap.php` and configure nginx to rewrite `/sitemap.xml` to it. Alternative: name the file `sitemap.xml` and add an nginx `location` block to pass `.xml` through `fastcgi` to PHP. The plan assumes the nginx rewrite approach (least disruptive).

**Why:** First change because it introduces the file that `robots.txt` points to, and sets the pattern for dynamic content scanning that blog/artist JSON-LD will also use.

**Logic:**
- Set `Content-Type: application/xml` header
- Hardcode static page entries: `/` (index.php) at priority 1.0, `/calculadora/` (calculadora.php) at priority 0.8, both `changefreq: weekly`
- Scan `content/blog/*.md` — parse frontmatter for `draft: true`/`published: false` to exclude, extract `date` for `<lastmod>`. Set `changefreq: weekly`, `priority: 0.7`
- Scan `artists/config/*.php` — include each config to get the artist slug; set `changefreq: monthly`, `priority: 0.6`
- Use file `mtime` as fallback `<lastmod>` when frontmatter date is unavailable
- Output valid XML with `<urlset>` root element and `<url>` children

**Assumptions to clarify with #23 and #24:**
- Blog markdown frontmatter format — specifically the key for `draft`/`published` status and the `date` field name
- Artist config file structure — specifically how the artist slug/URL path is derived from the config filename

---

### 2. Create `robots.txt`

**File:** `packages/lp/robots.txt` (new)

**What:** A static text file at the web root with AI-crawler-aware rules.

**Content:**
```
User-agent: OAI-SearchBot
Allow: /

User-agent: GPTBot
Disallow: /

User-agent: Google-Extended
Disallow: /

User-agent: *
Allow: /

Sitemap: https://vaif.com.br/sitemap.xml
```

**Why:** Second — depends only on the sitemap URL being determined (step 1).

---

### 3. Add meta tags to `index.php` (homepage)

**File:** `packages/lp/index.php`, `<head>` section (lines 3–45)

**What:** Add canonical, Open Graph, and Twitter Card meta tags to the homepage.

**Changes:**
- `<link rel="canonical" href="https://vaif.com.br/">`
- OG tags: `og:title`, `og:description`, `og:image`, `og:type` (website), `og:url`
- Twitter Card: `twitter:card` (summary_large_image), `twitter:title`, `twitter:description`, `twitter:image`
- Use static values matching the existing `<title>` content
- `og:image` and `twitter:image` should point to a 1200×630px social share image (confirm if one exists at `img/`; if not, flag as missing dependency)

**Why:** Meta tags are foundational — they'll be reused as a pattern for blog/artist pages. The existing `index.php` has none of these.

---

### 4. Add meta tags to `calculadora.php`

**File:** `packages/lp/calculadora.php`, `<head>` section (lines 3–30)

**What:** Same canonical, OG, and Twitter Card tags as the homepage, but with calculator-specific values.

**Changes:**
- `<link rel="canonical" href="https://vaif.com.br/calculadora/">`
- OG tags with calculator-specific title/description
- Twitter Card tags matching

**Why:** Same pattern as step 3. Static page, static values.

---

### 5. Add Organization JSON-LD to homepage

**File:** `packages/lp/index.php`, inside `<head>`

**What:** Inline `<script type="application/ld+json">` with Organization schema and `sameAs` links.

**Schema includes:**
```json
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "VAIF",
  "url": "https://vaif.com.br",
  "logo": "https://vaif.com.br/img/vaif_logo.png",
  "description": "Agência de Escala para Estúdios de Tatuagem",
  "sameAs": [
    "https://instagram.com/vaifmarketing"
  ],
  "contactPoint": {
    "@type": "ContactPoint",
    "email": "contato@vaif.com.br",
    "contactType": "sales"
  }
}
```

**Why:** Entity optimization — helps search engines associate VAIF's web presence with its social profiles. Placed only on the homepage.

---

### 6. Add JSON-LD to blog post pages (`BlogPosting`)

**File:** The blog post template/renderer created by ticket #23 (exact file TBD — likely a PHP script that reads `.md` frontmatter)

**What:** Inline `<script type="application/ld+json">` with BlogPosting schema, derived from frontmatter + body content.

**Schema includes:**
- `@type`: `BlogPosting` (as subtype of `Article`)
- `headline`: from frontmatter `title`
- `description`: from frontmatter `description` or first 160 chars of body
- `author`: `{ "@type": "Person", "name": "..." }` from frontmatter `author`
- `datePublished`: ISO 8601 from frontmatter `date`
- `dateModified`: from file mtime or frontmatter `updated`
- `image`: from frontmatter `image` (featured image URL)
- `publisher`: reference to Organization `{ "@type": "Organization", "name": "VAIF" }`
- `mainEntityOfPage`: the post URL

**How it integrates:** The blog post PHP renderer (from #23) will have access to parsed frontmatter and post content. The JSON-LD script tag should be built as a PHP string from that parsed data and emitted in `<head>`. This ticket adds a helper function (e.g., `generateBlogPostingJsonLd()`) that #23's renderer calls.

**Why:** BlogPosting schema triggers rich results (article carousel, headline, date, author) in search results.

---

### 7. Add JSON-LD to artist pages (`LocalBusiness` + `Person` + `FAQPage` + `BreadcrumbList`)

**File:** The artist page template/renderer created by ticket #24 (exact file TBD)

**What:** Four inline `<script type="application/ld+json">` tags in `<head>`, each a separate schema block.

#### 7a. LocalBusiness schema
- `name`: artist/studio name from config
- `address`: from config (city, state — full address if available)
- `telephone`: from config
- `url`: the artist's VAIF page URL
- `priceRange`: "R$ 3.000 - R$ 15.000" or derived from config
- `image`: artist photo/logo from config
- `openingHoursSpecification`: if available in config
- `sameAs`: artist's Instagram and other social links from config

#### 7b. Person schema
- `name`: artist name
- `jobTitle`: "Tatuador"
- `worksFor`: reference to the LocalBusiness above (via `@id`)
- `image`: artist photo
- `sameAs`: artist's social profiles

#### 7c. FAQPage schema
- `mainEntity`: array of `Question`/`Answer` pairs
- Questions derived from artist config FAQ data (common objections, pricing FAQ, booking FAQ)
- Each question uses `@type: "Question"`, `name`, `acceptedAnswer` with `@type: "Answer"` and `text`
- This is the AI-search lever — FAQ schema is how content appears in AI Overviews and voice search results

#### 7d. BreadcrumbList schema
- Position 1: Home (`/`)
- Position 2: Artists index (`/artists/`)
- Position 3: Current artist page

**How it integrates:** The artist page PHP renderer (from #24) will have access to parsed config data. This ticket adds helper functions (e.g., `generateLocalBusinessJsonLd()`, `generatePersonJsonLd()`, `generateFaqPageJsonLd()`, `generateBreadcrumbListJsonLd()`) that #24's renderer calls.

**Why:** LocalBusiness helps with local SEO (Google Maps, local pack). Person builds E-E-A-T signals. FAQPage is the primary AI-search lever (triggers rich results and AI Overview citations). BreadcrumbList provides navigational structure in search snippets.

---

### 8. Create shared JSON-LD helper file

**File:** `packages/lp/components/SeoHelpers.php` (new)

**What:** A PHP file containing reusable functions for generating JSON-LD schemas. Extracted to avoid duplicating logic across homepage, blog, and artist pages.

**Functions:**
- `jsonLdScript(string $json): string` — wraps JSON in the `<script type="application/ld+json">` tag, validates it's valid JSON
- `generateOrganizationJsonLd(): string` — returns Organization schema (used by homepage, also referenced by blog posts as `publisher`)
- `generateBlogPostingJsonLd(array $post): string` — takes parsed frontmatter + content, returns BlogPosting schema
- `generateLocalBusinessJsonLd(array $artist): string` — takes artist config data, returns LocalBusiness schema
- `generatePersonJsonLd(array $artist): string` — takes artist config data, returns Person schema
- `generateFaqPageJsonLd(array $faqItems): string` — takes array of question/answer pairs, returns FAQPage schema
- `generateBreadcrumbListJsonLd(array $crumbs): string` — takes array of `{name, url}` pairs, returns BreadcrumbList schema

**Validation:** Each function uses `json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)` to guarantee valid JSON output. Wrap in try/catch to prevent broken pages on encoding errors.

**Why:** Centralizing JSON-LD generation avoids spread-out schema logic. The blog and artist tickets (#23, #24) include this file and call the relevant function. The blog post publisher can reference the Organization `@id` without duplicating the schema.

---

### 9. Update blog page renderer (#23 coordination)

**Integration point:** The blog post PHP renderer from ticket #23 must call `generateBlogPostingJsonLd()` from `SeoHelpers.php` and emit the result in `<head>`. It must also include the canonical URL, OG tags, and Twitter Card tags populated from frontmatter.

**What this ticket provides:** The `SeoHelpers.php` file with the `generateBlogPostingJsonLd()` function. The blog ticket is responsible for calling it.

**Pass to #23:**
- Path to include: `components/SeoHelpers.php`
- Function to call: `generateBlogPostingJsonLd($post)` where `$post` is an array with keys: `title`, `description`, `author`, `date`, `image`, `url`, `body`
- Also needs: canonical URL, OG meta, Twitter Card meta (ticket #26 can provide a helper or #23 writes them inline)

---

### 10. Update artist page renderer (#24 coordination)

**Integration point:** The artist page PHP renderer from ticket #24 must call the four artist JSON-LD generators from `SeoHelpers.php` and emit the results in `<head>`. It must also include canonical URL, OG tags, and Twitter Card tags populated from artist config.

**What this ticket provides:** `SeoHelpers.php` with the four artist schema generators.

**Pass to #24:**
- Path to include: `components/SeoHelpers.php`
- Functions to call: `generateLocalBusinessJsonLd()`, `generatePersonJsonLd()`, `generateFaqPageJsonLd()`, `generateBreadcrumbListJsonLd()`
- Expected data shapes for each function (array keys)

---

### 11. Update tests

#### `tests/acceptance_test.php`
Add test cases:
- `GET /robots.txt` returns 200, contains `OAI-SearchBot: Allow`, `GPTBot: Disallow`, `Google-Extended: Disallow`, `Sitemap: https://vaif.com.br/sitemap.xml`
- `GET /sitemap.xml` returns 200, Content-Type is `application/xml` or `text/xml`, contains `<urlset`, `<url>`, `<loc>`
- `GET /index.php` contains `<script type="application/ld+json">`, contains `Organization`, contains `sameAs`
- `GET /index.php` contains `<link rel="canonical"`, `og:title`, `og:description`, `og:image`, `twitter:card`
- `GET /calculadora.php` contains `<link rel="canonical"`, `og:title`, `twitter:card`
- Sitemap XML validates as well-formed (basic: opening/closing tags count match)

#### `tests/e2e.mjs`
Add test cases:
- Navigate to homepage, verify `<script type="application/ld+json">` present in DOM
- Navigate to calculadora, verify meta tags present
- (Blog/artist pages tested in their respective tickets)

#### New: `tests/seo_test.php` (new, optional)
Dedicated SEO test file that:
- Validates JSON-LD parses as valid JSON
- Checks JSON-LD `@context` is `https://schema.org`
- Checks `@type` values are correct per page
- Validates sitemap XML is well-formed and contains expected URL count

**Why:** The existing test suite has no SEO coverage. The acceptance tests are the natural place for HTTP-level checks. JSON-LD validation needs dedicated tests to catch encoding errors.

---

### 12. Update `.gitignore`

**File:** `packages/lp/.gitignore`

**What:** No changes needed for this ticket. `robots.txt` and `sitemap.php` should be committed. The `SeoHelpers.php` helper should be committed.

---

## Dependency graph

```
Server routing (sitemap.xml → sitemap.php)    │
                                               ├── robots.txt
SeoHelpers.php (shared JSON-LD helpers)       │
                                               ├── index.php (meta tags)
                                               ├── index.php (Organization JSON-LD)
                                               ├── calculadora.php (meta tags)
                                               ├── blog renderer (#23) uses SeoHelpers
                                               └── artist renderer (#24) uses SeoHelpers
```

All changes depend on `SeoHelpers.php` being created first (as the shared source of truth). The sitemap and robots.txt are independent of the JSON-LD work and can proceed in parallel.

---

## Ambiguities and missing information

1. **Social share image**: `og:image`/`twitter:image` needs a 1200×630px image. Does one exist? If not, this is a dependency — someone needs to create it or the meta tags will have broken image references.

2. **Blog frontmatter schema**: The exact YAML keys for `draft`/`published` status and `date` in blog `.md` files need to be known before writing the sitemap scanner. Coordinate with ticket #23.

3. **Artist config structure**: The exact data shape of `artists/config/*.php` files (how name, address, phone, FAQ, social links are stored) needs to be known before writing the JSON-LD generators. Coordinate with ticket #24.

4. **Artist FAQ data source**: Where do the `Question`/`Answer` pairs for FAQPage schema come from? Are they in the artist config, a separate file, or derived from a template? The ticket says artist pages get FAQPage schema — the data source needs to be confirmed.

5. **Blog/artist listing/index pages**: Does ticket #23 or #24 create index/listing pages (e.g., `/blog/`, `/artists/`)? If so, the sitemap should include them as additional `<url>` entries.

6. **Nginx vs Apache**: The absence of `.htaccess` suggests nginx (consistent with Coolify). The plan should be validated against the actual server config to confirm the rewrite approach for `/sitemap.xml`.

7. **URL path conventions**: What are the actual URL paths for blog posts and artist pages? (e.g., `/blog/slug` vs `/blog/slug.php` vs `/blog.php?slug=...`). This affects canonical URLs, BreadcrumbList URLs, and sitemap `<loc>` values. Coordinate with #23 and #24.

---

## Summary of files changed/created

| File | Action | Purpose |
|---|---|---|
| `packages/lp/sitemap.php` | Create | Dynamic XML sitemap |
| `packages/lp/robots.txt` | Create | AI-crawler-aware robots rules |
| `packages/lp/components/SeoHelpers.php` | Create | Shared JSON-LD generator functions |
| `packages/lp/index.php` | Modify | Add meta tags (canonical, OG, Twitter) + Organization JSON-LD |
| `packages/lp/calculadora.php` | Modify | Add meta tags (canonical, OG, Twitter) |
| `packages/lp/tests/acceptance_test.php` | Modify | Add SEO test assertions |
| Server config (nginx) | Modify | Rewrite `/sitemap.xml` → `sitemap.php` |
| Blog renderer (#23) | Integration | Call `SeoHelpers` for BlogPosting JSON-LD + meta tags |
| Artist renderer (#24) | Integration | Call `SeoHelpers` for 4 schemas + meta tags |
