## Destination

Spec for adding a blog and artist landing-page system to vaif-lp. AI-authored markdown blog posts rendered by PHP, plus a config-driven artist template (8 sections: hero, portfolio, about, booking CTA via WhatsApp, testimonials, Instagram feed, FAQ, location) at `vaif.com.br/artists/<slug>`. Artist pages created automatically via n8n webhook on contract close. SEO optimized for Google rankings + AI search visibility. Includes refactoring calculadora.php code duplication. Target scale: 5-20 artists in 6 months. End state: a detailed build spec ready for implementation.

## Notes

- **Repo**: `packages/lp` — pure PHP + vanilla CSS/JS, no framework, no build step, no CMS
- **Deployment**: Coolify with env vars; n8n for automation; Matomo + Facebook Pixel for analytics
- **Design system**: CSS custom properties (--gold, --bg-dark, --bg-card, --text-main, --text-muted, --border-color, --accent-red, --accent-green); Cormorant Garamond + Montserrat; mobile-first with breakpoints at 900/768/600px
- **Skills to consult**: `/grilling`, `/domain-modeling`, `/prototype`, `/research`
- **TDD**: The CLAUDE.md mandates test-first development for any feature/fix/refactor
- **Plan, don't do**: This map produces a spec to hand off, not a working implementation

## Decisions so far

- [How can n8n trigger artist page creation?](issues/04-n8n-page-creation.md) — n8n HTTP Request → PHP endpoint (`POST /api/artists/create.php`); needs persistent Docker volume in Coolify; minimal version is single-webhook config-file creation
- [What SEO setup for blog + artist pages?](issues/05-seo-strategy.md) — `LocalBusiness`+`Person`+`FAQPage` for artists, `BlogPosting` for posts; dynamic PHP sitemaps; FAQ schema is the AI-search lever; concrete JSON-LD snippets in research

## Not yet specified

- Blog listing/index page design (archive view, pagination, category/tag filtering)
- Content publishing workflow (how AI-authored posts get reviewed, committed, and deployed)
- Artist page analytics/tracking — how Matomo and Facebook Pixel events are wired on artist pages
- n8n workflow details for artist page creation — exact triggers, data passed, error handling
- Testing strategy for blog and artist pages (Playwright e2e, PHP acceptance tests)
- Artist page URL slug strategy (manual or derived from name?)

## Out of scope

<!-- work consciously ruled beyond the destination -->
