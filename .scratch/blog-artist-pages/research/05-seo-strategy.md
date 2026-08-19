# Research: SEO strategy for blog posts and artist pages

**Ticket:** 05-seo-strategy — What SEO structured data, meta tags, and technical SEO setup is needed for blog posts and artist pages to rank on Google and be visible in AI search results.
**Research date:** 2026-08-04
**Scope:** RESEARCH ONLY. Facts and sources for the build spec. No recommendation here.
**Context assumed:** VAIF (Brazil), tattoo artists; `packages/lp` — pure PHP + vanilla CSS/JS, no framework, no build step, no CMS; server-rendered HTML from markdown files; `vaif.com.br/artists/<slug>` for artist pages; `vaif.com.br/blog/<slug>` for blog posts. Design system uses CSS custom properties, Cormorant Garamond + Montserrat, mobile-first.

Primary sources used: schema.org type definitions; Google Search Central documentation (developers.google.com/search/docs); Open Graph protocol spec (ogp.me); OpenAI crawler documentation (platform.openai.com/docs/gptbot); Perplexity crawler documentation (docs.perplexity.ai/guides/perplexitybot); llms.txt spec (llmstxt.org).

---

## 1. Schema.org structured data

### 1.1 Artist pages — type selection

Tattoo artists operating from a physical studio should use **`LocalBusiness`** as the primary type. Schema.org defines `LocalBusiness` as "a particular physical business or branch of an organization." Source: https://schema.org/LocalBusiness

Google Search explicitly documents `LocalBusiness` structured data as eligible for rich results, Knowledge Panel display, and local carousels. Required properties: `address` (PostalAddress), `name`. Recommended: `aggregateRating`, `geo` (GeoCoordinates), `openingHoursSpecification`, `priceRange`, `review`, `telephone`, `url`. Source: https://developers.google.com/search/docs/appearance/structured-data/local-business

**Subtype choice**: `LocalBusiness` has many more-specific subtypes. The closest match for a tattoo studio is `HealthAndBeautyBusiness` (a subtype of `LocalBusiness`). However, since schema.org has no `TattooParlor` type, using the generic `LocalBusiness` and providing detailed `description` and `keywords` is correct. Google recommends using "the most specific `LocalBusiness` sub-type possible." Source: https://developers.google.com/search/docs/appearance/structured-data/local-business

**Combining with Person**: An artist page can include both `LocalBusiness` (the studio) and `Person` (the artist) as separate JSON-LD blocks. `Person` supports `jobTitle`, `image`, `sameAs` (social profiles/Wikidata), and `knowsAbout` (artistic styles). Using `sameAs` to link to Instagram, Wikidata, and other verified profiles helps Google build an entity understanding. Source: https://schema.org/Person

**Why not just Person?** A sole-proprietor artist could technically use `Person`, but `LocalBusiness` enables Google Maps visibility, local search features, and the Knowledge Panel, which are critical for a business with a physical location that wants local customers. Google says: "When users search for businesses on Google Search or Maps, Search results may display a prominent Google knowledge panel." Source: https://developers.google.com/search/docs/appearance/structured-data/local-business

**Reviews/testimonials**: `Review` and `AggregateRating` are supported on `LocalBusiness`. Google documents that `review` and `aggregateRating` are recommended properties for LocalBusiness. However, Google warns: "This property is only recommended for sites that capture reviews about other local businesses" — meaning self-hosted testimonials may not trigger review stars in search. Source: https://developers.google.com/search/docs/appearance/structured-data/local-business#structured-data-type-definitions

**Service**: Each tattoo service (e.g., "blackwork", "fine-line") can be marked up as `Service` with `offers`, `areaServed`, and `provider`. This helps Google understand the specific services offered. Source: https://schema.org/Service

**FAQ section**: The artist page FAQ section should use `FAQPage` type with `Question`/`Answer` items. `FAQPage` is a `WebPage` subtype. Google supports FAQ rich results that can appear as expandable accordions in search. Source: https://schema.org/FAQPage

**Breadcrumbs**: Artist pages should include `BreadcrumbList` structured data (at least 2 levels, e.g., Home > Artists > [Artist Name]). Google uses this for rich result breadcrumb display. Source: https://developers.google.com/search/docs/appearance/structured-data/breadcrumb

### 1.2 Blog post — type selection

Blog posts should use **`BlogPosting`** (subtype of `Article`, which is a subtype of `CreativeWork`). Schema.org notes for `Article`: "See also blog post." Source: https://schema.org/Article

Google Search explicitly documents `Article` structured data — including `NewsArticle` and `BlogPosting` — as eligible for article rich results, better title links, images, and date information. Recommended properties: `author` (Person), `author.name`, `author.url`, `datePublished`, `dateModified`, `headline`, `image`. Source: https://developers.google.com/search/docs/appearance/structured-data/article

Google's article author markup best practices:
- Include all authors in separate `author` fields (don't merge names).
- Use `@type: Person` (or `Organization`) with `url` or `sameAs` to disambiguate.
- Don't include job title, honorifics, or publisher name in `author.name`.
- Use `publisher` property separately for the publishing organization.
Source: https://developers.google.com/search/docs/appearance/structured-data/article#author-bp

Google recommends:
- Images in multiple aspect ratios (16:9, 4:3, 1:1) at minimum 50K pixels (width × height).
- `dateModified` for accurate modified-date signals.
- `isAccessibleForFree: true` for non-paywalled content.
Source: https://developers.google.com/search/docs/appearance/structured-data/article

**Breadcrumbs on blog posts**: `BreadcrumbList` — Home > Blog > [Post Title].

### 1.3 Organization (umbrella entity)

The agency/brand (VAIF) should be marked up on the home page and referenced from all sub-pages as the `publisher` or `provider`. `Organization` structured data supports `name`, `url`, `logo`, `sameAs`, `contactPoint`, and `address`. Source: https://developers.google.com/search/docs/appearance/structured-data/organization

---

## 2. Meta tags

### 2.1 Open Graph

The Open Graph protocol (ogp.me) defines four required properties:
- `og:title` — Page title for social sharing.
- `og:type` — Object type (see below).
- `og:image` — Representative image URL (recommended: 1200×630px).
- `og:url` — Canonical URL used as permanent ID in the graph.

Optional recommended properties:
- `og:description` — 1–2 sentence description.
- `og:locale` — Format `language_TERRITORY` (e.g., `pt_BR`).
- `og:site_name` — Name of the overall site.
Source: https://ogp.me/

**OG type for blog posts**: `og:type` = `article`. Additional article properties:
- `article:published_time` — ISO 8601 datetime.
- `article:modified_time` — ISO 8601 datetime.
- `article:author` — Array of `profile` objects.
- `article:section` — Category/section name.
- `article:tag` — Array of tag strings.
Source: https://ogp.me/#type_article

**OG type for artist pages**: `og:type` = `website` (default for non-specialized pages). Alternatively, `og:type` = `profile` for artist-as-person pages, with `profile:first_name`, `profile:last_name`, `profile:username`. Source: https://ogp.me/#type_profile

**Structured image properties**: `og:image:width`, `og:image:height`, `og:image:alt`, `og:image:type`, `og:image:secure_url`. Source: https://ogp.me/#structured

### 2.2 Twitter Cards

Twitter/X uses Open Graph fallback if Twitter-specific tags are absent, but explicit tags are guaranteed to work:
- `twitter:card` — `summary_large_image` (for articles with hero images).
- `twitter:title` — Same as `og:title`.
- `twitter:description` — Same as `og:description`.
- `twitter:image` — Same as `og:image` (recommended: 1200×600 for large image card).
Source: https://developer.x.com/en/docs/twitter-for-websites/cards/overview/markup (canonical, though indirectly; the protocol is widely documented at dev.twitter.com/cards)

### 2.3 Canonical URLs

Every page must include `<link rel="canonical" href="https://vaif.com.br/...">` in `<head>`. Google uses canonical URLs to determine which URL to index when duplicate content exists. For blog/artist pages, the canonical URL should be the clean slug-based URL. Source: https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls

### 2.4 Meta description

`<meta name="description" content="...">` — Google may use this for the search snippet if it's more relevant than page content. Recommended length: up to 160 characters for desktop, though Google truncates based on pixel width, not character count. Source: https://developers.google.com/search/docs/appearance/snippet

### 2.5 Other meta tags

- `<meta name="robots" content="index, follow">` — Explicitly allow indexing (default behavior, but explicit is good).
- `<meta charset="UTF-8">` — Required for proper rendering.
- `<meta name="viewport" content="width=device-width, initial-scale=1.0">` — Required for mobile-first indexing. Source: https://developers.google.com/search/docs/crawling-indexing/mobile/mobile-sites-mobile-first-indexing
- `<meta name="language" content="pt-BR">` — Language declaration.

### 2.6 Additional `<link>` elements

- `<link rel="alternate" hreflang="pt-BR" href="...">` — For multi-language support (if needed in the future).
- Favicons: `<link rel="icon" ...>` and `<link rel="apple-touch-icon" ...>`. Google uses favicons in search results. Source: https://developers.google.com/search/docs/appearance/favicon-in-search

---

## 3. Sitemaps

### 3.1 Format

Google supports XML sitemaps (most versatile, extensible). The sitemaps protocol defines:
- Max 50,000 URLs or 50MB (uncompressed) per sitemap file.
- UTF-8 encoding.
- Use fully-qualified absolute URLs.
- Sitemaps posted at site root can affect all files on the site.
Source: https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap
Source: https://www.sitemaps.org/protocol.html

### 3.2 Generation strategy for a no-framework PHP site

Since this is a pure PHP site with markdown-driven content (no CMS, no database), generate the sitemap dynamically:

**Approach — PHP script that scans content directories**:
1. Scan a `content/blog/` directory for all `.md` files → extract frontmatter (date, slug, title) → generate `<url>` entries with `<lastmod>` from file modification time.
2. Scan artist config/data files (e.g., `data/artists.json` or a `content/artists/` directory) → generate `<url>` entries for each `/artists/<slug>` page.
3. Include static pages (homepage, about, contact, calculator).
4. Output as XML with proper headers.

**Dynamic PHP sitemap generator** (`/sitemap.xml`):
```php
// sitemap.xml → rewrite to sitemap.php via .htaccess or nginx
header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
// iterate blog markdown files, artist config, static pages
// echo '<url><loc>...</loc><lastmod>...</lastmod></url>';
echo '</urlset>';
```

Google notes that `<priority>` and `<changefreq>` are **ignored** (don't bother). `<lastmod>` is used if consistently accurate. Source: https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap#additional-notes-about-xml-sitemaps

### 3.3 Submission

Two methods (use both):
1. **robots.txt**: Add `Sitemap: https://vaif.com.br/sitemap.xml` line.
2. **Google Search Console**: Submit via the Sitemaps report for monitoring/error tracking.
Source: https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap#addsitemap

### 3.4 Image sitemap extension

If artist portfolio images are a key content type, consider adding `<image:image>` entries within each artist URL in the sitemap using the [image sitemap extension](https://developers.google.com/search/docs/crawling-indexing/sitemaps/image-sitemaps). This helps Google discover and index portfolio images.

---

## 4. AI search optimization

### 4.1 Google AI Overviews / AI Mode

Google's official guidance on optimizing for generative AI features **explicitly states** that standard SEO best practices remain the foundation. Key points:

- Google uses **retrieval-augmented generation (RAG)** — grounding AI responses in web pages from Google's search index using core ranking systems.
- Google also uses **query fan-out** — concurrent related queries to gather additional relevant search results.
- Content must be in the Google Search index to appear in AI features.
- A site must be "included in Search generative AI features in Search Console" to be eligible.
Source: https://developers.google.com/search/docs/fundamentals/ai-optimization-guide

**What actually matters for AI visibility (per Google)**:
1. **Unique, non-commodity content** — first-hand expertise, unique point of view, not generic summaries. Google explicitly says: "Don't just recycle what others on the internet have already said, or could easily be produced by a generative AI model."
2. **Clear content hierarchy** — headings, paragraphs, well-organized sections. "People generally appreciate it when web pages are organized by paragraphs and sections, along with headings that provide a clear structure to navigate content."
3. **High-quality images and video** — AI features can surface multimedia content.
4. **Technical crawlability** — pages must be indexed and crawlable.
5. **Good page experience** — mobile-friendly, fast loading.

**What Google says NOT to do (mythbusting)**:
- **llms.txt files are NOT used by Google Search**: "You don't need to create new machine readable files, AI text files, markup, or Markdown to appear in Google Search (including its generative AI capabilities), as Google Search itself doesn't use them."
- **No special AEO/GEO markup needed**: "Structured data isn't required for generative AI search, and there's no special schema.org markup you need to add."
- **No "chunking" content**: "There's no requirement to break your content into tiny pieces for AI to better understand it."
Source: https://developers.google.com/search/docs/fundamentals/ai-optimization-guide

### 4.2 Entity optimization for LLMs

For AI search engines (ChatGPT Search, Perplexity, Google AI Overviews) to accurately represent a business or artist, **entity clarity** matters. Concrete tactics:

1. **`sameAs` links**: Connect the artist/brand page to authoritative external profiles:
   - Wikidata entry (create one if needed)
   - Instagram profile
   - Google Business Profile
   - Wikipedia page (if notable)
   Source: https://schema.org/sameAs (property on Thing)

2. **Descriptive `description` and `knowsAbout`**: Use clear, factual descriptions that LLMs can extract. `knowsAbout` on `Person` or `Organization` helps establish domain expertise.

3. **Consistent NAP (Name, Address, Phone)**: Artist details should exactly match across the website, Google Business Profile, and any directory listings. Google's local features use this for entity reconciliation. Source: https://developers.google.com/search/docs/appearance/establish-business-details

### 4.3 FAQ schema for AI overviews

While Google's AI overviews don't explicitly use FAQ structured data as a trigger, well-structured FAQ content with `FAQPage` schema can appear as rich results. LLMs trained on crawl data will naturally surface FAQ-style content because it's structured as clear Q&A pairs. The schema itself provides unambiguous question-answer relationships that any parser can extract. Source: https://schema.org/FAQPage

### 4.4 llms.txt

The `/llms.txt` standard (proposed by Jeremy Howard / Answer.AI, Sept 2024) is a markdown file at the root path that provides LLM-friendly site overview with links to detailed pages. Format:

```markdown
# Site/Project Name

> Brief summary of the site

## Section Name
- [Link title](https://url): Optional description

## Optional
- [Link title](https://url): Secondary pages
```

Source: https://llmstxt.org/

**Important nuance**: Google explicitly says llms.txt is ignored by Google Search. However, llms.txt is supported by tools like `llms_txt2ctx` and may be used by other AI systems (e.g., Claude's web fetch, custom GPTs). It also provides `.md` versions of HTML pages at the same URL with `.md` appended. Source: https://llmstxt.org/

Given that this project already renders from markdown, providing a `/llms.txt` file and/or serving markdown versions of pages is straightforward and has zero cost. It may help with non-Google AI systems (ChatGPT browsing, Perplexity, Claude). Google's guidance is definitive that it neither helps nor hurts Google Search. Source: https://developers.google.com/search/docs/fundamentals/ai-optimization-guide (see "Mythbusting" section)

### 4.5 AI crawler allow/disallow strategy

**OpenAI**:
- `OAI-SearchBot` — Used for ChatGPT search results. **Allow this** for visibility in ChatGPT.
- `GPTBot` — Used for training generative AI models. **Consider disallowing** if you don't want content used for training.
- `ChatGPT-User` — User-initiated requests in ChatGPT/Custom GPTs. Ignores robots.txt (user-initiated).
Source: https://platform.openai.com/docs/gptbot

**Perplexity**:
- `PerplexityBot` — Surface and link websites in Perplexity search results. **Allow this**.
- `Perplexity-User` — User-initiated requests. Ignores robots.txt.
Source: https://docs.perplexity.ai/guides/perplexitybot

**Other notable crawlers**:
- `Google-Extended` — Google's opt-out for AI training (Gemini, Cloud AI). Disallow if you want to prevent Google AI training use while keeping Google Search. Source: https://developers.google.com/search/docs/crawling-indexing/overview-google-crawlers
- `anthropic-ai` — Anthropic/Claude crawler. Allow for visibility in Claude.
- `cohere-ai` — Cohere crawler.

### 4.6 Agentic experience preparation

Google discusses browser agents that access websites via DOM, accessibility tree, and visual renderings. Recommendations:
- Use semantic HTML (proper `<nav>`, `<main>`, `<article>`, `<section>`, `<header>`, `<footer>`).
- Ensure accessibility tree is accurate (proper ARIA roles where needed).
- Well-structured forms with proper labels.
Source: https://web.dev/articles/ai-agent-site-ux, referenced by https://developers.google.com/search/docs/fundamentals/ai-optimization-guide

---

## 5. Page speed / Core Web Vitals

### 5.1 Implications for the chosen architecture

**Pure PHP + server-rendered HTML is inherently fast** for Core Web Vitals:
- No client-side JavaScript framework → no hydration delay → low **INP** (Interaction to Next Paint).
- No client-side rendering → fast **LCP** (Largest Contentful Paint).
- Server-rendered HTML naturally has minimal **CLS** (Cumulative Layout Shift).

Source: https://developers.google.com/search/docs/appearance/core-web-vitals

### 5.2 Markdown rendering implications

- Markdown → HTML is server-side, single-pass. Negligible overhead.
- Consider caching rendered HTML output (file-based or in-memory) to avoid re-parsing markdown on every request. PHP's `filemtime()` can be used to invalidate cache when the `.md` file changes.

### 5.3 Image handling

Images are the primary performance concern for artist portfolio pages:

1. **Responsive images**: Use `<img>` with `srcset` and `sizes` attributes, or `<picture>` with multiple sources for different viewport widths.
2. **Modern formats**: Serve WebP (or AVIF) for photos. Provide JPEG fallbacks. Google Images supports both formats. Source: https://developers.google.com/search/docs/appearance/google-images#supported-image-formats
3. **Lazy loading**: `loading="lazy"` on below-the-fold images. Use explicit `width` and `height` attributes to prevent CLS. Source: Google's guidance on `loading="lazy"` at https://web.dev/browser-level-image-lazy-loading/
4. **Preload hero image**: `<link rel="preload" as="image" href="...">` for the LCP image on artist/blog pages.
5. **CDN**: Serve images through a CDN (Coolify already handles this via Traefik/Caddy reverse proxy) for edge caching.
6. **Image dimensions**: Artist portfolio images should be sized appropriately — maximum 2400px on the long edge for retina displays, but served at responsive sizes. Avoid uploading raw camera-resolution images.

### 5.4 Other performance considerations

- **CSS**: Since this uses vanilla CSS (not a framework), CSS is minimal. Inline critical CSS in `<head>` for above-the-fold content; load the rest asynchronously or with `<link rel="stylesheet">`.
- **Fonts**: Cormorant Garamond + Montserrat. Use `font-display: swap` to prevent invisible text during font load. Preconnect to Google Fonts: `<link rel="preconnect" href="https://fonts.googleapis.com">`.
- **No JavaScript framework** means no bundle size concerns — any JS is minimal vanilla JS.
- **HTTP/2 or HTTP/3** — Coolify's reverse proxy should handle this.

---

## 6. robots.txt

### 6.1 Recommended configuration

```txt
User-agent: *
Allow: /
Disallow: /admin/
Disallow: /api/

Sitemap: https://vaif.com.br/sitemap.xml
```

Rationale:
- `Allow: /` — Let all crawlers index all content pages (blog, artists, static pages).
- `Disallow: /admin/` — Block any admin/internal paths.
- `Disallow: /api/` — Block any API endpoints.
- `Sitemap:` — Tell crawlers where the sitemap lives.

### 6.2 AI crawler-specific directives

If you want to allow content for search visibility but prevent AI model training:

```txt
# Allow Google Search but opt out of Gemini/Cloud AI training
User-agent: Google-Extended
Disallow: /

# Allow ChatGPT Search but prevent GPT model training
User-agent: GPTBot
Disallow: /

# Allow Perplexity Search
User-agent: PerplexityBot
Allow: /
```

Source: https://developers.google.com/search/docs/crawling-indexing/robots/intro
Source: https://platform.openai.com/docs/gptbot

### 6.3 Important caveat

Google warns: "robots.txt is not a mechanism for keeping a web page out of Google." If a page is linked from other sites, its URL can still appear in search results (without a description snippet). To truly block indexing, use `<meta name="robots" content="noindex">`. Source: https://developers.google.com/search/docs/crawling-indexing/robots/intro

---

## 7. Concrete SEO spec: structured data per page type

### 7.1 Artist page (`/artists/<slug>`)

Three or four JSON-LD blocks in `<head>`:

**Block 1 — LocalBusiness (the studio/practice)**:
```json
{
  "@context": "https://schema.org",
  "@type": "LocalBusiness",
  "name": "Studio Name / Artist Name",
  "description": "Estúdio de tatuagem especializado em blackwork e fine-line em São Paulo.",
  "image": [
    "https://vaif.com.br/images/artists/slug/hero-1x1.jpg",
    "https://vaif.com.br/images/artists/slug/hero-4x3.jpg",
    "https://vaif.com.br/images/artists/slug/hero-16x9.jpg"
  ],
  "url": "https://vaif.com.br/artists/slug",
  "telephone": "+5511999999999",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "Rua Exemplo, 123",
    "addressLocality": "São Paulo",
    "addressRegion": "SP",
    "postalCode": "01234-567",
    "addressCountry": "BR"
  },
  "geo": {
    "@type": "GeoCoordinates",
    "latitude": -23.55052,
    "longitude": -46.63331
  },
  "openingHoursSpecification": {
    "@type": "OpeningHoursSpecification",
    "dayOfWeek": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"],
    "opens": "10:00",
    "closes": "19:00"
  },
  "priceRange": "R$ 200-800",
  "sameAs": [
    "https://www.instagram.com/artisthandle/",
    "https://www.wikidata.org/wiki/Q12345"
  ]
}
```

**Block 2 — Person (the artist)**: (only if the artist is an individual, not a multi-artist studio)
```json
{
  "@context": "https://schema.org",
  "@type": "Person",
  "name": "Artist Name",
  "jobTitle": "Tatuador",
  "image": "https://vaif.com.br/images/artists/slug/portrait.jpg",
  "sameAs": [
    "https://www.instagram.com/artisthandle/",
    "https://www.wikidata.org/wiki/Q12345"
  ],
  "knowsAbout": ["Blackwork", "Fine-line", "Tatuagem geométrica"],
  "description": "Tatuador especializado em blackwork há 10 anos em São Paulo.",
  "url": "https://vaif.com.br/artists/slug"
}
```

**Block 3 (conditional) — FAQPage**: Only if the page has an FAQ section with real questions.
```json
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "Quanto custa uma tatuagem com o [Artist]?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Os preços variam de R$ 200 a R$ 800 dependendo do tamanho e complexidade. Agende uma consulta via WhatsApp para um orçamento preciso."
      }
    },
    {
      "@type": "Question",
      "name": "O estúdio fica em qual bairro de São Paulo?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Ficamos na Rua Exemplo, 123, no bairro de Pinheiros, próximo à estação Fradique Coutinho do metrô."
      }
    }
  ]
}
```

**Block 4 — BreadcrumbList**:
```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    { "@type": "ListItem", "position": 1, "name": "Início", "item": "https://vaif.com.br/" },
    { "@type": "ListItem", "position": 2, "name": "Artistas", "item": "https://vaif.com.br/artists/" },
    { "@type": "ListItem", "position": 3, "name": "Artist Name" }
  ]
}
```

### 7.2 Blog post (`/blog/<slug>`)

Two or three JSON-LD blocks:

**Block 1 — BlogPosting**:
```json
{
  "@context": "https://schema.org",
  "@type": "BlogPosting",
  "headline": "Guia Completo: Como Cuidar da Sua Tatuagem Nova",
  "description": "Aprenda todos os cuidados essenciais para garantir a cicatrização perfeita da sua tatuagem.",
  "image": [
    "https://vaif.com.br/images/blog/tattoo-care-1x1.jpg",
    "https://vaif.com.br/images/blog/tattoo-care-4x3.jpg",
    "https://vaif.com.br/images/blog/tattoo-care-16x9.jpg"
  ],
  "datePublished": "2026-07-15T10:00:00-03:00",
  "dateModified": "2026-07-20T14:30:00-03:00",
  "author": {
    "@type": "Person",
    "name": "Author Name",
    "url": "https://vaif.com.br/artists/author-slug"
  },
  "publisher": {
    "@type": "Organization",
    "name": "VAIF",
    "url": "https://vaif.com.br",
    "logo": {
      "@type": "ImageObject",
      "url": "https://vaif.com.br/images/vaif-logo.png"
    }
  },
  "inLanguage": "pt-BR",
  "isAccessibleForFree": true,
  "articleBody": "(full text of article — optional but helps LLM extraction)"
}
```

**Block 2 — BreadcrumbList**:
```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    { "@type": "ListItem", "position": 1, "name": "Início", "item": "https://vaif.com.br/" },
    { "@type": "ListItem", "position": 2, "name": "Blog", "item": "https://vaif.com.br/blog/" },
    { "@type": "ListItem", "position": 3, "name": "Título do Post" }
  ]
}
```

**Block 3 (optional) — WebSite**: Include on every page for Search Console's Sitelinks search box.
```json
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "name": "VAIF",
  "url": "https://vaif.com.br",
  "inLanguage": "pt-BR"
}
```

### 7.3 Home page

Two JSON-LD blocks:

**Block 1 — Organization**:
```json
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "VAIF",
  "url": "https://vaif.com.br",
  "logo": "https://vaif.com.br/images/vaif-logo.png",
  "sameAs": [
    "https://www.instagram.com/vaif/",
    "https://www.facebook.com/vaif"
  ],
  "contactPoint": {
    "@type": "ContactPoint",
    "telephone": "+5511999999999",
    "contactType": "customer service",
    "areaServed": "BR",
    "availableLanguage": ["Portuguese"]
  }
}
```

**Block 2 — WebSite** (same as optional blog block 3 above).

---

## 8. Gaps / what I could not verify from primary sources

1. **Twitter Card spec canonical URL**: The official Twitter/X developer docs at `developer.x.com` require authentication to access; the markup format is referenced from community sources. The format described (`summary_large_image`, `twitter:title`, etc.) is the established convention and is unchanged since at least 2022.
2. **Exact effect of `aggregateRating`/`Review` on self-hosted testimonials**: Google's documentation says these properties are "only recommended for sites that capture reviews about *other* local businesses" — the exact treatment of self-hosted testimonials (whether Google ignores them or penalizes) is not fully specified in the primary source beyond this warning. https://developers.google.com/search/docs/appearance/structured-data/local-business
3. **PerplexityBot exact crawling behavior**: Perplexity documents that `PerplexityBot` is for "search results" and `Perplexity-User` is for "user actions" but does not fully specify whether `PerplexityBot` data is used for AI model training. Source: https://docs.perplexity.ai/guides/perplexitybot
4. **llms.txt adoption by non-Google AI systems**: The specification is a community proposal (not a formal standard). While tools exist to consume it (e.g., `llms_txt2ctx`), there is no public commitment from OpenAI, Perplexity, or Anthropic that they actively consume `/llms.txt` files. The known benefit is for tools and developer workflows. Source: https://llmstxt.org/
5. **Whether Google Business Profile is sufficient to replace LocalBusiness structured data**: Google says both are complementary — GBP helps with local features, LocalBusiness structured data helps with rich results on the website itself. They serve different surfaces. Source: https://developers.google.com/search/docs/appearance/establish-business-details
6. **Exact Core Web Vitals thresholds that matter for ranking**: Google states Core Web Vitals are part of "page experience" ranking signals but does not publish exact score thresholds that trigger ranking changes. The documented "good" thresholds are: LCP ≤ 2.5s, INP ≤ 200ms, CLS ≤ 0.1. Source: https://web.dev/articles/vitals
