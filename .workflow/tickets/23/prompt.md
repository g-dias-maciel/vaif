## Task: Create an implementation plan for ticket #23

Read the ticket below, explore the codebase to understand current state,
and write a concrete implementation plan.

### Ticket
# Blog system — markdown parser, post page, listing

Labels: implementation

## Parent

[Build Spec: Blog + Artist Landing Pages for vaif-lp](https://github.com/g-dias-maciel/vaif/issues/20)

## What to build

A PHP front-controller at `/blog/index.php` that renders blog posts from markdown files in `content/blog/`. Includes a minimal YAML frontmatter parser and a listing/archive page.

## Acceptance criteria

### Markdown parser
- [ ] Reads `.md` files from `content/blog/` and extracts YAML frontmatter
- [ ] Required fields `title` and `date` (YYYY-MM-DD) are validated; missing → log warning, skip post
- [ ] Optional fields supported: `slug`, `description`, `author`, `featured_image`, `tags`, `category`, `draft`
- [ ] `slug` auto-derived from title if missing (lowercase, hyphens, ASCII)
- [ ] `description` defaults to first ~160 chars of body if missing
- [ ] Post body (below frontmatter) passed to markdown-to-HTML renderer
- [ ] No external YAML library — ~20-line flat key:value parser

### Individual post page
- [ ] `/blog/<slug>` renders a full HTML page with post title, date, author, featured image, body content, tags/categories
- [ ] Post is styled using the vaif-lp design system (CSS custom properties, Cormorant Garamond + Montserrat, dark theme, gold accents)
- [ ] `draft: true` posts return 404 or redirect to listing (not reachable at their URL)
- [ ] Missing slug returns 404
- [ ] BlogPosting JSON-LD meta tag in `<head>` (derived from frontmatter)
- [ ] Open Graph + Twitter Card meta tags in `<head>`

### Listing/archive page
- [ ] `/blog` renders a list of published, non-draft posts sorted by date (newest first)
- [ ] Each entry shows: title (linked), date, description excerpt, tags/category badges, featured image thumbnail
- [ ] Draft posts are excluded from listing
- [ ] No pagination for initial release — simple list

### General
- [ ] Front-controller uses the `onboard/index.php` pattern: `parse_url()` + manual routing
- [ ] `declare(strict_types=1)`
- [ ] Facebook Pixel + Matomo tracking snippets included
- [ ] Mobile-first responsive

## Blocked by

- [Nginx routing for /blog and /artists](https://github.com/g-dias-maciel/vaif/issues/22)

### Rules
- Output ONLY the plan — no implementation code
- For each change: what file(s), what change, why
- List changes in dependency order
- Flag any ambiguity or missing information
- Note which existing tests will need updating

### Output
Write the plan to `.workflow/tickets/23/plan.md`
