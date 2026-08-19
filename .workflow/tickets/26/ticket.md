# SEO — structured data, sitemap, robots.txt

Labels: implementation

## Parent

[Build Spec: Blog + Artist Landing Pages for vaif-lp](https://github.com/g-dias-maciel/vaif/issues/20)

## What to build

Add JSON-LD structured data to blog and artist pages, generate a dynamic XML sitemap, and update robots.txt for AI-search crawler compatibility.

## Acceptance criteria

### Structured data (JSON-LD)
- [ ] Blog posts emit `BlogPosting` schema (with `Article` subtype) derived from frontmatter + body content
- [ ] Artist pages emit `LocalBusiness` + `Person` + `FAQPage` + `BreadcrumbList` schemas
- [ ] `Organization` schema with `sameAs` links to social profiles on relevant pages (for entity optimization)
- [ ] All JSON-LD is inline `<script type="application/ld+json">` in `<head>`
- [ ] FAQ schema uses the `Question`/`Answer` nested structure (the AI-search lever)
- [ ] Validate JSON-LD output is valid JSON with no syntax errors

### Dynamic sitemap
- [ ] `/sitemap.xml` dynamically scans `content/blog/*.md` for published posts and `artists/config/*.php` for artist pages
- [ ] Outputs valid XML sitemap with `<urlset>` and `<url>` entries per page
- [ ] Each entry includes `<lastmod>` (file modification time), `<changefreq>` (weekly for blog, monthly for artists), and `<priority>`
- [ ] Draft blog posts are excluded from sitemap
- [ ] Returns correct `Content-Type: application/xml` header
- [ ] Static pages (`/`, `/calculadora/`) included in sitemap

### robots.txt
- [ ] `Allow: OAI-SearchBot` for AI search visibility
- [ ] `Disallow: GPTBot` to block OpenAI training crawler
- [ ] `Disallow: Google-Extended` to block Google AI training crawler
- [ ] Links to `/sitemap.xml`
- [ ] Existing rules (if any) preserved

### Meta tags (should already be present from blog/artist tickets, verify)
- [ ] Canonical URL on every page
- [ ] Open Graph: `og:title`, `og:description`, `og:image`, `og:type`, `og:url`
- [ ] Twitter Card: `twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`

### Reference
- Full SEO research with concrete JSON-LD snippets: `.scratch/blog-artist-pages/research/05-seo-strategy.md`

## Blocked by

- [Blog system](https://github.com/g-dias-maciel/vaif/issues/23)
- [Artist landing pages](https://github.com/g-dias-maciel/vaif/issues/24)
