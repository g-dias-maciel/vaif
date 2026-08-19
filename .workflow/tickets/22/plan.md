# Implementation Plan — Ticket #22: Nginx routing for /blog and /artists

## 1. Summary

The site currently serves PHP files directly (e.g. `/index.php`, `/calculadora.php`, `/onboard/index.php`). This ticket adds URL rewriting so `/blog/*` and `/artists/*` route to their respective `index.php` front-controllers, enabling clean SEO-friendly URLs like `/blog/post-slug` and `/artists/artist-slug`.

There are two deployment contexts:
- **Production (Coolify):** Routing handled by Nginx config pasted into Coolify's custom Nginx config UI.
- **Local dev (`php -S`):** PHP's built-in server ignores Nginx. A `router.php` bootstrap script simulates the rewrites so tests and local dev work identically.

The `blog/` and `artists/` directories do not yet exist (their `index.php` front-controllers are built in tickets #23 and #24). This plan optionally creates minimal stubs so routing can be tested end-to-end before those tickets land.

## 2. Changes (in dependency order)

### 2.1 Create stub `blog/index.php` and `artists/index.php`

- **Files:** `packages/lp/blog/index.php`, `packages/lp/artists/index.php`
- **What:** Create minimal placeholder PHP files that return a recognizable HTTP 200 response with identifying text (e.g. `Blog front-controller reached` / `Artists front-controller reached`). These will be replaced by the real implementations in tickets #23 and #24.
- **Why:** Routing has no meaningful target without these files. Creating stubs lets us verify Nginx rewrites and acceptance tests immediately, without waiting for tickets #23/#24. The stubs also make clear at a glance where the front-controllers live.
- **Tests to update:** New acceptance tests will hit these endpoints.

### 2.2 Create `packages/lp/router.php` — bootstrap for `php -S` local dev

- **Files:** `packages/lp/router.php`
- **What:** A PHP router script that `php -S` uses via `-t` flag. Pattern: the script checks `$_SERVER['REQUEST_URI']`.
  - If the request path matches an existing static file on disk (`.php`, `.css`, `.js`, `.png`, etc.), return `false` so PHP serves it directly.
  - If the path starts with `/blog` or `/blog/`, include/forward to `/blog/index.php`.
  - If the path starts with `/artists/`, include/forward to `/artists/index.php`.
  - Otherwise, return `false` to let PHP's default behavior handle it (serves `index.php` for `/`, `calculadora.php` for `/calculadora/`, etc.).
- **Why:** `php -S` does not read `.htaccess` or Nginx config. Without a router, `/blog/some-post` would return 404 because there's no file at that path. This script mirrors the production Nginx rewrites so local dev and acceptance tests behave identically.
- **Tests to update:** `onboard_acceptance_test.php` and `acceptance_test.php` may need their `php -S` start commands updated to include the router script argument.

### 2.3 Document Nginx configuration for Coolify

- **Files:** `packages/lp/nginx-routing.conf` (reference/docs only, NOT a deployed file)
- **What:** A plain-text config snippet with inline comments explaining the location blocks to paste into Coolify's custom Nginx config UI.
  ```
  location /blog {
      try_files $uri $uri/ /blog/index.php?$args;
  }
  location /artists {
      try_files $uri $uri/ /artists/index.php?$args;
  }
  ```
  Plus a guard: ensure these location blocks are placed *before* the catch‑all `location ~ \.php$` block so they take priority. Explain in the file that this is **manual** — not source‑controlled on the server — and must be applied via Coolify UI.
- **Why:** The only artifact of the production routing is what's pasted into the Coolify UI. Having a repo copy serves as documentation, aids debugging, and provides a single source of truth.
- **Tests to update:** None (this is documentation).

### 2.4 Write acceptance tests for routing

- **Files:** `packages/lp/tests/routing_acceptance_test.php` (new)
- **What:** A PHP test script that spawns `php -S` with the router and asserts:
  - `GET /blog` returns 200 and contains the blog stub content.
  - `GET /blog/some-post` returns 200 and contains the blog stub content (not a 404).
  - `GET /artists/artist-slug` returns 200 and contains the artists stub content.
  - `GET /` still returns 200 and contains the main landing page (regression).
  - `GET /calculadora.php` still returns 200 and contains the calculator (regression).
  - `GET /onboard/test-token-123` still returns 200 and HTML (regression).
  - `GET /api/leads/submit.php` still resolves correctly (regression — test that the file is reachable, not that a POST succeeds).
  - `GET /nonexistent-page` returns 404 (verifies router doesn't match unknown paths).
  - Static assets (`/style.css`, `/js/main.js`) are served directly with correct content-type (not routed through PHP).
- **Why:** TDD — the tests define the contract before the implementation is considered done. Covers the 4 acceptance criteria plus edge cases.
- **Tests to update:** This is a new file.

## 3. Risks / Ambiguities

### 3.1 Stub files vs. real implementations (tickets #23, #24)

The `blog/index.php` and `artists/index.php` files are built in tickets #23 and #24. Should this ticket:
- **A)** Create minimal stubs so routing is testable now, merge independently, and let #23/#24 overwrite them? *(Plan assumes this approach.)*
- **B)** Be blocked on #23/#24, only adding routing after both front-controllers exist?

**Recommendation:** Option A. Stub files are trivial and harmless.

### 3.2 `/blog` (no trailing slash) vs `/blog/`

`try_files $uri $uri/ /blog/index.php?$args;` handles both. A request to `/blog` tries the literal file `blog`, then the directory index `blog/`, then falls through to the front-controller. Need to confirm this works correctly with PHP-FPM (Coolify's default) — the directory try may trigger an internal redirect to `/blog/index.php` which could bypass our custom location block.

### 3.3 Coolify's Nginx config management

Unknown: does Coolify's custom config UI **append** to the generated config or **replace** it? If it appends, our `location` blocks need higher priority than any auto-generated catch‑all `location /` block. If it replaces, we must reproduce the default PHP-FPM config alongside our custom blocks. This needs verification before pasting.

### 3.4 Existing `/calculadora/` path

The acceptance criteria say "Existing pages (`/`, `/calculadora/`, `/onboard/*`, `/api/*`) continue to work unchanged." Note that `/calculadora/` currently works because the server treats it as a directory and serves `calculadora.php` via the index directive. With the Nginx rewrites in place, we must ensure the new `location /blog` and `location /artists` blocks do not intercept requests to `/calculadora/`, `/onboard/`, or `/api/`. This happens naturally because these location blocks are prefix‑matched (`/blog`, `/artists`) and don't match those paths — but the acceptance tests should explicitly verify it.

### 3.5 404 behavior for unknown sub-paths

When a request hits `/blog/nonexistent-post` or `/artists/nonexistent-artist`, the Nginx rewrite sends it to the front-controller. The front-controller (tickets #23/#24) is responsible for returning a 404 response. The routing layer does not care whether the slug is valid — it always forwards. This is the correct design (the front-controller owns slug validation), but it means `/blog/wp-admin` would also hit the PHP front-controller unless explicitly excluded.

### 3.6 Test script startup command change

Existing test scripts (`onboard_acceptance_test.php`, `acceptance_test.php`) start `php -S` with `-t <docroot>` only. After adding the router, the start command should be `php -S localhost:<port> -t <docroot> router.php`. However, if existing tests don't depend on blog/artists routing, they can continue using the simpler command. This plan adds a *new* test file for routing and does not modify the existing test scripts.

## 4. Verification

1. Run `php tests/routing_acceptance_test.php` — all assertions pass.
2. Run existing `php tests/onboard_acceptance_test.php` and `php tests/acceptance_test.php` — no regressions.
3. Paste the Nginx config block into Coolify's custom Nginx config UI, deploy, and manually curl the blog/artists URLs from staging/production.
