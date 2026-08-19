# Implementation Plan: Blog System (Ticket #23)

## Summary

Build a self-contained blog front-controller at `/blog/index.php` with a custom YAML frontmatter parser and markdown renderer. No external libraries — pure PHP 8+. Follows the `onboard/index.php` front-controller pattern.

---

## Dependencies & Ordering

All changes below are ordered by dependency chain. Steps within the same number can be parallelized.

| # | File(s) | What | Why |
|---|---------|------|-----|
| 1 | `content/blog/sample-post.md` | Create a sample .md file with valid frontmatter + body | Test input — needed before any parser code runs |
| 2 | `content/blog/draft-post.md` | Create a sample .md file with `draft: true` | Test draft-exclusion logic |
| 3 | `lib/blog/parse-frontmatter.php` | Flat key:value YAML frontmatter parser (~20 lines) | Core parsing — no library. Splits on `---`, parses `key: value` lines into assoc array. Returns `[frontmatter, body]`. Rejects malformed input. |
| 4 | `lib/blog/render-markdown.php` | Minimal markdown-to-HTML renderer (~30 lines) | Converts `# headings`, `##`, `**bold**`, `*italic*`, `[links](url)`, `![alt](url)`, `- unordered`, `1. ordered`, blank-line-separated `<p>`, `>` blockquotes, code fences, horizontal rules. Line-by-line or regex — no library. |
| 5 | `lib/blog/slugify.php` | Slug helper: lowercase, non-ASCII → closest ASCII, `[^a-z0-9]+` → `-`, trim `-` | Auto-derives slug from title when frontmatter `slug` is missing |
| 6 | `lib/blog/load-posts.php` | Scans `content/blog/` for `.md` files, parses each, validates required fields (`title`, `date`), skips invalid with `error_log()` warning. Returns sorted array of post objects. | Single point of data loading consumed by both the listing and individual post routes |
| 7 | `blog/index.php` | Front-controller with three routes: `/blog` (listing), `/blog/<slug>` (single post), fallback (404). `declare(strict_types=1)`. `parse_url()` routing. | Main entrypoint. Follows `onboard/index.php` pattern exactly. |
| 8 | `style.css` | Add `.blog-post`, `.blog-listing`, `.blog-card`, `.blog-tag`, `.blog-category`, `.blog-featured-img`, `.blog-post-meta`, `.blog-post-body` CSS rules using existing design tokens | Blog content needs its own styling within the vaif-lp design system. Reuses `--gold`, `--text-main`, `--text-muted`, `--bg-card`, etc. |

---

## Step-by-step details

### Step 1 + 2: Sample content files

**`content/blog/sample-post.md`**
- Valid YAML frontmatter with all fields: `title`, `date`, `slug`, `description`, `author`, `featured_image`, `tags`, `category`, `draft: false`
- Body uses all markdown constructs the renderer must handle (headings, bold, italic, links, images, lists, blockquotes, code fences, horizontal rules, paragraphs)

**`content/blog/draft-post.md`**
- Minimal frontmatter with `draft: true`
- Body can be a single paragraph

### Step 3: `lib/blog/parse-frontmatter.php`

Function signature: `function parse_frontmatter(string $raw): array`

Logic:
1. Strip leading whitespace. If file doesn't start with `---\n`, return `[[], $raw]` (no frontmatter — treat whole file as body).
2. Find the closing `\n---\n` or `\n---` at end. Extract the YAML block.
3. Split YAML block by `\n`. For each line, find the first `:` to split key from value. Trim both.
4. Skip empty lines and lines without `:`.
5. Return `[$frontmatter, trim(body)]`.

Edge cases handled:
- No frontmatter delimiter → whole file is body
- Unterminated frontmatter → treat `---` at EOF as terminator
- Values with colons (URLs like `https://...`) → split only on first `:`
- Empty body → empty string
- Multiline values → **NOT supported** (flat key:value only, as spec requires)

### Step 4: `lib/blog/render-markdown.php`

Function signature: `function render_markdown(string $text): string`

Handles, in this order:
1. **Code fences** (```) — extract before any other processing, wrap in `<pre><code>`, reinsert with placeholders
2. **Headings** — `### ` → `<h3>`, `## ` → `<h2>`, `# ` → `<h1>`
3. **Horizontal rules** — standalone `---`, `***`, `___` → `<hr>`
4. **Blockquotes** — lines starting with `> ` → `<blockquote><p>...</p></blockquote>` (adjacent lines merged)
5. **Unordered lists** — lines starting with `- ` or `* ` → `<ul><li>` (adjacent lines grouped)
6. **Ordered lists** — lines starting with `1. ` → `<ol><li>` (adjacent lines grouped)
7. **Images** — `![alt](url)` → `<img src="url" alt="alt">`
8. **Links** — `[text](url)` → `<a href="url">text</a>`
9. **Bold** — `**text**` → `<strong>text</strong>`
10. **Italic** — `*text*` → `<em>text</em>`
11. **Inline code** — `` `code` `` → `<code>code</code>`
12. **Paragraphs** — blank-line-separated text blocks → `<p>...</p>`
13. **Reinsert code fences** — swap placeholders back

Important: images and links must be processed before bold/italic to avoid `**` inside URLs being captured. Code fences extracted first to prevent markdown inside code blocks from being rendered.

### Step 5: `lib/blog/slugify.php`

Function signature: `function slugify(string $text): string`

Logic:
1. `mb_strtolower()`
2. `iconv('UTF-8', 'ASCII//TRANSLIT')` — converts `ç`→`c`, `ã`→`a`, etc.
3. `preg_replace('/[^a-z0-9]+/', '-', ...)` — collapse non-alphanumeric to hyphens
4. `trim(..., '-')`

### Step 6: `lib/blog/load-posts.php`

Function signature: `function load_posts(string $dir): array`

Logic:
1. `glob("$dir/*.md")` — list all markdown files
2. For each file:
   - `file_get_contents()` → pass to `parse_frontmatter()`
   - Validate `title` and `date` are present and non-empty
   - If missing: `error_log("Blog post $file missing required field: title/date")`, continue
   - If `date` present but not `YYYY-MM-DD`: `error_log(...)`, skip
   - Derive `slug` from frontmatter `slug` field if present, else `slugify(title)`
   - Derive `description` from frontmatter if present; if missing, take first ~160 chars of body (strip markdown syntax crudely, then `mb_substr`)
   - Collect optional fields: `author`, `featured_image`, `tags` (split on comma), `category`, `draft` (boolean)
   - Store parsed post in array keyed by slug
3. Sort by `date` descending
4. Return sorted array

### Step 7: `blog/index.php`

Structure (following `onboard/index.php`):

```
<?php declare(strict_types=1);

// require lib files
// load posts
// parse_url() routing
// route /blog → listing
// route /blog/<slug> → single post (404 if not found, 404 if draft)
// fallback → 404
```

**`render_page()` helper** (like `render_html()` in onboard):
- Full HTML document with `<head>` containing:
  - Meta charset + viewport
  - Title (dynamic per route)
  - Favicon (same icons as `index.php`: favicon.ico, PNG sizes, apple-touch-icon, android-chrome, site.webmanifest)
  - Google Fonts preconnect + link (Cormorant Garamond + Montserrat, same as `index.php`)
  - Facebook Pixel snippet (ID: `752550821217294`, PageView event)
  - Matomo snippet (site ID 1, `//analytics.vaif.com.br/`)
  - Facebook `<noscript>` pixel fallback
  - Open Graph + Twitter Card meta (see below — set per route)
  - BlogPosting JSON-LD (see below — set per route)
  - `<link rel="stylesheet" href="/style.css">`
- `<body>` containing the page content, wrapped in appropriate CSS classes

**Route: `/blog` (listing page)**

- Include `components/Header.php` (or a blog-specific header if needed, but likely reuse the site header with a back-link)
- Render list of published, non-draft posts sorted by date desc
- Each entry is a card (`.blog-card`):
  - `featured_image` thumbnail (if present, `<img>` with `.blog-card-img`)
  - Title linked to `/blog/<slug>` (`.blog-card-title`)
  - Date formatted as `DD/MM/YYYY` (`.blog-card-date`)
  - Description excerpt (`.blog-card-excerpt`)
  - Tags as `.blog-tag` badges (if any)
  - Category as `.blog-category` badge (if present)
- Page title: "Blog — VAIF"
- No pagination (per spec)
- Meta tags: generic blog listing OG/Twitter

**Route: `/blog/<slug>` (single post)**

- Extract slug from path via `preg_match('#^/blog/([a-z0-9\-]+)$#', ...)`
- Look up post in loaded array by slug
- 404 if not found (HTTP 404 + error page)
- 404 if `draft: true` (same treatment — redirect or 404, per spec)
- Render full post:
  - Post title as `<h1>` (`.blog-post-title`)
  - Meta line: date + author (`.blog-post-meta`)
  - Featured image if present (`.blog-featured-img`)
  - Body rendered via `render_markdown()` (`.blog-post-body`)
  - Tags + category badges at bottom
- `<head>` meta:
  - `<title>`Post Title — VAIF Blog`</title>`
  - OG: `og:title`, `og:description`, `og:image` (from `featured_image`), `og:type: article`
  - Twitter: `twitter:card: summary_large_image`, `twitter:title`, `twitter:description`, `twitter:image`
  - JSON-LD BlogPosting: `@type: BlogPosting`, `headline`, `datePublished`, `dateModified`, `author`, `image`, `description`, `url`
- Page wrapper uses `.blog-post` class for CSS scoping

**404 fallback route:**
- `http_response_code(404)`
- Render a "Página não encontrada" error card with link back to `/blog`
- Consistent with the onboard 404 pattern

### Step 8: `style.css` additions

Add at end of file (before the responsive section if logical, or at very end):

**`.blog-listing`** — page wrapper, max-width centered, padding matching site sections
**`.blog-listing-header`** — page title using `.section-heading` style
**`.blog-card`** — card container: `bg-card` background, `border-color` border, padding, rounded corners, hover effect with subtle gold border
**`.blog-card-img`** — featured image thumbnail, max-width, border-radius
**`.blog-card-title`** — Cormorant Garamond heading, gold on hover
**`.blog-card-date`** — Montserrat, `--text-muted`, smaller font
**`.blog-card-excerpt`** — body text, clamped to 2-3 lines
**`.blog-tag`**, **`.blog-category`** — inline badge pills, gold border on transparent or subtle gold bg
**`.blog-post`** — single-post wrapper, max-width ~800px centered
**`.blog-post-title`** — Cormorant Garamond, large (clamp 2rem–3.5rem)
**`.blog-post-meta`** — Montserrat, `--text-muted`, date + author
**`.blog-post-featured-img`** — full-width image, border-radius
**`.blog-post-body`** — all rendered markdown content: headings in Cormorant Garamond, paragraphs/body in Montserrat, links in gold, blockquotes with gold left border, code/pre styled with dark bg, images responsive
**`.blog-404`** — centered error card matching onboard "link invalido" pattern

**Responsive:** Add `.blog-card` stacking to single-column at 768px, reduce `.blog-post` padding, shrink featured image.

---

## Ambiguities / Open Questions

1. **Nginx routing (`#22`):** The ticket is blocked by issue #22 for nginx routing. The `blog/index.php` front-controller assumes that `/blog` and `/blog/*` are routed to `blog/index.php` by the web server. This is an infra concern outside this plan's scope, but the PHP code must be ready for it.

2. **Header reuse:** The plan reuses `components/Header.php` from the main site. Verify the nav links still make sense in the blog context (they link to `#` anchors on the main page). May need a blog-specific header variant with a "← Voltar ao site" link — flagging for implementation time.

3. **Featured image paths:** The plan assumes `featured_image` frontmatter values are URL paths relative to the site root (e.g., `/img/blog/post-hero.jpg`). If images are stored elsewhere, this needs clarification.

4. **Tag/category pages:** The ticket doesn't mention tag or category archive pages. Only the listing by-date is in scope. Tag/category badges on the listing link nowhere for now — flag as future enhancement.

5. **RSS/Atom feed:** Not in scope, but common blog expectation. Flag as out-of-scope for this ticket.

6. **Test coverage:** The ticket references `tests/` directory (existing for lp package). No existing tests target blog functionality (it's new). New test files needed:
   - `tests/blog/parse-frontmatter.test.php` — test frontmatter parsing edge cases
   - `tests/blog/render-markdown.test.php` — test markdown rendering
   - `tests/blog/slugify.test.php` — test slug generation
   - `tests/blog/load-posts.test.php` — test post loading + validation
   - Write these before implementation per TDD mandate in CLAUDE.md.

---

## Files Changed Summary

| Action | File |
|--------|------|
| CREATE | `content/blog/sample-post.md` |
| CREATE | `content/blog/draft-post.md` |
| CREATE | `lib/blog/parse-frontmatter.php` |
| CREATE | `lib/blog/render-markdown.php` |
| CREATE | `lib/blog/slugify.php` |
| CREATE | `lib/blog/load-posts.php` |
| CREATE | `blog/index.php` |
| MODIFY | `style.css` (append blog styles) |
| CREATE | `tests/blog/parse-frontmatter.test.php` |
| CREATE | `tests/blog/render-markdown.test.php` |
| CREATE | `tests/blog/slugify.test.php` |
| CREATE | `tests/blog/load-posts.test.php` |
