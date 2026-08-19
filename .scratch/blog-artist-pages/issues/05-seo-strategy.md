Type: research
Status: resolved
Blocked by: none

## Question

What SEO structured data, meta tags, and technical SEO setup is needed for blog posts and artist pages to rank on Google and be visible in AI search results?

## Answer

See full research: [research/05-seo-strategy.md](research/05-seo-strategy.md) (547 lines, covers all 6 areas with primary-source citations and concrete JSON-LD snippets).

**Key findings:**

- **Artist pages:** `LocalBusiness` + `Person` + `FAQPage` schema; `BreadcrumbList` for hierarchy
- **Blog posts:** `BlogPosting` (with `Article` subtype); `BreadcrumbList`
- **Umbrella brand:** `Organization` with `sameAs` links to social profiles for entity optimization
- **Meta tags:** standard Open Graph + Twitter Cards + canonical URLs
- **Sitemaps:** Dynamic PHP generation (no framework needed) — scans artist configs and blog markdown files
- **AI search:** Google explicitly ignores `llms.txt` and "AEO" markup; FAQ schema and clear content hierarchy are the real levers. `llms.txt` is zero-cost supplement for non-Google AI crawlers.
- **robots.txt:** Recommended config with AI bot-specific directives (OAI-SearchBot allow, GPTBot/Google-Extended disallow)
- **Page speed:** Architecture is already fast (server-rendered HTML). Main optimizations: WebP images, srcset, lazy loading, markdown render caching.
