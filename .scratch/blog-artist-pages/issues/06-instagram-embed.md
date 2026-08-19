Type: research
Status: resolved
Blocked by: 03

## Question

How should the Instagram feed be embedded on artist landing pages?

## Recommendation

**Static gallery**, with Juicer.io as a fallback for artists who insist on live feeds.

### Why static gallery

1. **Unbreakable** — Instagram API deprecation, HTML changes, rate limits, auth requirements — none matter when you host your own images.
2. **Perfect GDPR posture** — zero external requests, zero tracking scripts, zero cookies.
3. **Matches the architecture** — pure PHP with JSON config files per artist. No Composer, no OAuth, no cron jobs.
4. **Aesthetic control** — photos can be curated, color-corrected, and cropped to match the dark luxury theme.
5. **Performance** — pre-loaded images from same origin in `<img>` tags with `loading="lazy"`. No JS waterfalls.

### Option evaluations

| Criterion | Basic Display API | oEmbed | Static Gallery | Third-Party | Scraping |
|-----------|:---:|:---:|:---:|:---:|:---:|
| Reliability | ⭐⭐ | ⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐ |
| Setup Complexity | ⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐ |
| Performance | ⭐⭐⭐⭐⭐ | ⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐ | ⭐⭐⭐⭐ |
| GDPR/Privacy | ⭐⭐⭐ | ⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐ | ⭐⭐⭐⭐⭐ |
| PHP Fit | ⭐⭐⭐ | ⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐ | ⭐⭐⭐ |

**Disqualified:** oEmbed (single-post only, loads Facebook tracking), SnapWidget (privacy disaster: GA + AdSense + Facebook cookies), Instagram Basic Display API (needs Facebook Business Account + App Review + OAuth token rotation — unreasonable for non-technical tattoo artists).

**Server-side scraping** is technically viable (pgrimaud/instagram-user-feed, 946 stars) but requires constant maintenance — the changelog shows emergency fixes every few months when Instagram changes its DOM.

**Third-party services:** Juicer.io is the best option (no cookies on embeds, no visitor tracking, GDPR compliant, dark themes, free tier for 1 source). Elfsight sets cookies and costs $24/mo for 20 artists. Curator.io is $23/mo for 5 sources.

### Implementation approach (static gallery)

Artist config file (`config/artists/<slug>.json`):
```json
{
  "instagram": {
    "handle": "@blackanchor.ink",
    "profile_url": "https://www.instagram.com/blackanchor.ink/",
    "follower_count": 4200,
    "images": [
      {"src": "/uploads/blackanchor/ig-001.jpg", "caption": "Back piece work in progress"},
      {"src": "/uploads/blackanchor/ig-002.jpg", "caption": "Custom sleeve finished"}
    ]
  }
}
```

PHP template renders images directly from config — pure `<img>` tags, no JS, no external requests.

Optional: a simple PHP upload page (~50 lines, `.htpasswd`-protected) lets artists self-manage their 8 gallery images and follower count.

### Juicer.io as fallback

For artists who want truly live feeds: Juicer free tier (1 source, dark theme, no tracking, 24h refresh). Embed snippet is ~2KB JS.

### Sources

- oEmbed providers.json — confirms Instagram oEmbed at `graph.facebook.com/v16.0/instagram_oembed`
- Facebook dev docs (developers.facebook.com/docs/instagram*) — return 400 for non-JS requests (API hostile posture)
- pgrimaud/instagram-user-feed — GitHub (946 stars, login mandatory, frequent DOM fixes)
- Juicer.io — pricing + privacy policy (no cookies on embeds, GDPR compliant)
- Elfsight — pricing + privacy (sets `elfsight_viewed_recently` cookie)
- SnapWidget — privacy (GA, AdSense, AddThis, Facebook cookies — worst for GDPR)
- Curator.io — pricing page
- LightWidget — landing page

Full research: `/tmp/opencode/instagram-research.md`
