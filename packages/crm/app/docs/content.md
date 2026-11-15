# Weekly blog content

The CRM owns the weekly blog workflow: topic calendar → AI draft → Copilot
review → human approval → GitHub commit → Coolify deploy. The LP renders the
final markdown at `vaif.com.br/blog/<slug>`.

**Nothing is published without a staff approval.** The Copilot only reads and
suggests; the admin decides.

## Flow

```text
content:generate (Monday 08:00)
  → oldest due blog_topic
  → GET /api/artists/profile.php?slug=… (LP fact sheet)
  → OpenRouter draft (structured JSON)
  → deterministic quality gate
  → Copilot review (overview, score, checks, issues)
  → blog_posts.status = needs_review
  → staff opens CRM → Conteúdo
  → reads overview + preview → writes comments → regenerates or approves
  → PublishBlogPost
  → GitHub Contents API commit
  → Coolify deploy
  → verify public URL + BlogPosting schema
  → IndexNow ping
  → status = published
```

## Staff screens

| URL | Purpose |
|---|---|
| `/content` | Calendar, review queue, in-flight drafts, published archive |
| `/content/{post}` | Admin overview, Copilot review, preview, regeneration comments, approval |

Only `staff` can open these screens. Artists get 403.

## Statuses

| Status | Meaning |
|---|---|
| `generating` | The AI is writing or rewriting the draft |
| `needs_review` | Ready for the admin |
| `approved` | Admin approved; publication is queued |
| `publishing` | GitHub commit sent; waiting for the public URL |
| `published` | Live and verified |
| `failed` | Generation or publish failed; see the feedback banner |
| `rejected` | Archived by the admin |

## Commands

```bash
# Queue the oldest due topic (the scheduler runs this on Monday)
php artisan content:generate

# Queue one specific topic
php artisan content:generate --topic=<uuid>

# Regenerate a post with comments
php artisan content:generate --post=<uuid> --feedback="Encurtar a introdução…"

# Seed the first 12 topics for an artist
php artisan content:seed-topics --artist-id=<uuid> --start=2026-11-16
```

## Configuration

| Variable | Purpose |
|---|---|
| `CONTENT_ENABLED` | Turns the weekly command and scheduler on |
| `CONTENT_WEEKLY_DAY` / `CONTENT_WEEKLY_TIME` | Schedule (default Monday 08:00) |
| `CONTENT_QUEUE` | Queue name; the container runs a dedicated `content` worker |
| `CONTENT_MODEL` | Draft model (OpenRouter id) |
| `CONTENT_REVIEW_MODEL` | Copilot review model |
| `CONTENT_MAX_TOKENS` | Draft token ceiling |
| `LP_BASE_URL` | Public LP base URL |
| `LP_ARTIST_API_URL` | Artist profile feed |
| `LP_ARTIST_API_KEY` | Must match the LP app |
| `GITHUB_CONTENT_TOKEN` | Fine-grained PAT with **Contents: write** on the repo |
| `GITHUB_CONTENT_REPO` | `owner/repo` |
| `GITHUB_CONTENT_BRANCH` | Default `main` |
| `GITHUB_CONTENT_PATH` | Default `packages/lp/content/blog` |
| `INDEXNOW_KEY` / `INDEXNOW_KEY_LOCATION` | Must match the key file in the LP |
| `CONTENT_VERIFY_ATTEMPTS` / `CONTENT_VERIFY_SLEEP` | Deploy verification window |
| `CONTENT_MAIL` / `CONTENT_RECIPIENTS` | Staff notification e-mail |

## Artist eligibility

The workflow only creates content for an Artist with
`artist_settings.landing_page_slug` set **and** a live LP page. Configure the
slug in **Artistas → Editar → Slug da landing page**.

For Adriano Santos, the slug is `adriano-santos`.

## Landing-page intake

The CRM artist page has **Landing page → Formulário para o artista**. Clicking
**Gerar link para o artista** asks the LP for a single-use intake token and
shows a link like:

```text
https://vaif.com.br/novo-artista/<token>?name=...&whatsapp=...&instagram=...
```

Send that link to the artist. The form collects the full landing-page brief:
name, contact, bio, photos, portfolio, before/after, stats, testimonials, FAQ,
location and opening hours. Name, WhatsApp and Instagram are prefilled from the
CRM.

When the artist submits, the page is created as a **draft** in the LP artist
panel for review. The link is single-use; generate another one if it expires or
is lost. For an artist with a published page, edit it in the LP panel rather
than creating a new intake.

The **Painel de artistas da LP** button in the landing-page header opens the
review/publish panel. It uses `LP_ADMIN_TOKEN`, which must match
`ARTISTS_ADMIN_TOKEN` in the LP app.

## Publishing and Coolify

The commit goes to `packages/lp/content/blog/<slug>.md`. The LP is a separate
Coolify application whose base directory is `packages/lp`; it deploys from
`main`.

Set **Watch Paths** on both Coolify apps so a content commit only redeploys the
LP:

- LP app: `packages/lp/**`
- CRM app: `packages/crm/**`

Without watch paths, a blog commit can redeploy the CRM unnecessarily.

## Claims policy

Allowed without a source (subjective editorial positioning):

- "um dos principais tatuadores de cobertura da região"
- "referência em realismo preto e cinza em Poços de Caldas"
- "reconhecido pelo cuidado com o acabamento"

Requires the fact sheet (objective claims):

- years of experience, number of tattoos, rating
- city, neighborhood, studio, address, hours
- specialties, services, price range

Forbidden:

- "o melhor tatuador do Brasil", "nº 1", "o único"
- invented awards, certifications, rankings
- medical guarantees ("não dói", "cicatriza sem falhas")
- fake testimonials or prices

The deterministic gate blocks the obvious cases; the Copilot review flags
unsupported objective claims and keeps the subjective positioning.

## First run

1. Apply `packages/crm/db/migrations/0046_blog_content.sql`.
2. Set `LP_ARTIST_API_KEY` in both apps.
3. Set `GITHUB_CONTENT_TOKEN` in the CRM.
4. Set `landing_page_slug` for Adriano in **Artistas → Editar**.
5. Seed topics:

   ```bash
   php artisan content:seed-topics --artist-id=<adriano-uuid> --start=2026-11-16
   ```

6. Open **Conteúdo** and click **Gerar próximo agora**.
7. Review the Copilot overview and preview. Write comments or approve.
