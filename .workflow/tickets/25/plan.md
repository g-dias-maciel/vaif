# Implementation Plan: n8n artist creation endpoint (#25)

## Prerequisites (must be done first)

### P-1: Persistent Docker volume for `artists/`
**What:** Add a persistent Docker volume mounted at `artists/` in the Coolify service configuration.
**Why:** Artist configs and media are written to disk at runtime. Without a persistent volume, all artist data is lost on every container redeploy. The `artists/config/` and `artists/{slug}/media/` directories must survive redeploys.
**Owner:** Infrastructure (Coolify dashboard).

### P-2: Ensure `artists/` directory exists and is writable
**What:** The PHP process must be able to write to `artists/` (owned by `www-data` or appropriate user inside the container).
**Why:** The endpoint creates `artists/config/` and `artists/{slug}/media/` as children of this directory.

---

## Changes (in dependency order)

### 1. Create `api/artists/` directory
**File:** `packages/lp/api/artists/` (new directory)
**What:** Create the directory that will hold the endpoint file.
**Why:** Doesn't exist yet. Mirrors `api/leads/` sibling pattern.

### 2. Create `api/artists/create.php`
**File:** `packages/lp/api/artists/create.php` (new file)
**What:** The POST endpoint implementing the full create flow described below.
**Why:** This is the deliverable.

#### 2a. Auth header validation
- Read `$_SERVER['HTTP_X_API_KEY']`
- Compare against `getenv('API_CREATE_KEY')`
- Missing header → `HTTP 401`, `{ success: false, error: "API key obrigatória." }`
- Invalid key → `HTTP 401`, `{ success: false, error: "API key inválida." }`
- Log all 401 attempts via `error_log()`

#### 2b. Input validation
- Read JSON body from `php://input` via `file_get_contents()` + `json_decode()`
- Invalid JSON → `HTTP 422`, `{ success: false, error: "Corpo da requisição inválido." }`
- Required fields check: `slug`, `display_name`, `whatsapp_number`
- Any missing → `HTTP 422`, `{ success: false, error: "Campos obrigatórios: slug, display_name, whatsapp_number" }`
- Slug format validation: regex `/^[a-z0-9]+(-[a-z0-9]+)*$/` (lowercase, alphanumeric, hyphen-separated)
- Invalid slug → `HTTP 422`, `{ success: false, error: "Slug inválido. Use apenas letras minúsculas, números e hifens." }`
- Log all 422 failures via `error_log()`

#### 2c. Auto-generate hero headline / subheadline
- If `hero_headline` is absent/empty: auto-generate from identity fields
- If `hero_subheadline` is absent/empty: auto-generate from identity fields
- **Identity fields** are: `display_name`, `category`, `style` (aller provided by n8n)
- Auto-generation rules (to be confirmed):
  - `hero_headline` default: `"{display_name}"` (the artist's name alone)
  - `hero_subheadline` default: `"Tatuador {category}"` or `"Tatuador {style}"` if category is present, otherwise `"Tatuador profissional"`
- **Ambiguity:** The exact identity fields n8n will send are not specified in the ticket. The endpoint should be defensive — attempt generation from whichever identity fields are present, fall back to sensible string defaults. Confirm with the n8n workflow author which fields will be in the JSON body.

#### 2d. Directory creation
- Build path: `artists/{slug}/media/` (relative to `packages/lp/`)
- Create with `mkdir($path, 0755, true)` — recursive, so `artists/{slug}/` is also created if absent
- Check `artists/config/` exists; create it if not (`mkdir('artists/config/', 0755, true)`)
- Directory creation failure → `HTTP 500`, `{ success: false, error: "Erro ao criar diretório do artista." }`

#### 2e. Atomic config file write
- Build config array from the validated/normalized fields (including the resolved hero fields)
- Example structure:
  ```php
  <?php
  return [
      'slug' => '...',
      'display_name' => '...',
      'whatsapp_number' => '...',
      'hero_headline' => '...',
      'hero_subheadline' => '...',
      // ... all other fields passed through from n8n
  ];
  ```
- Write PHP content to a temporary file in the same directory: `artists/config/{slug}.php.tmp`
- Atomic rename: `rename($tmpPath, $finalPath)` — prevents partial reads by the front-controller
- Write/rename failure → `HTTP 500`, `{ success: false, error: "Erro ao salvar configuração do artista." }`
- Delete temp file on failure (no stale `.tmp` files)

#### 2f. JSON response
- Success → `HTTP 200`, `{ success: true, url: "/artists/{slug}" }`
- All error responses follow `{ success: false, error: "..." }` with appropriate HTTP status code
- All responses have `Content-Type: application/json` header

#### 2g. Error handling and logging
- All errors logged via `error_log()` with context (slug, error type)
- No PHP warnings/notices exposed to the client — `error_reporting(0)` or equivalent
- Generic 500 catch-all for unexpected exceptions: `{ success: false, error: "Erro interno do servidor." }`

#### 2h. Block idempotency on existing slug
- Check if `artists/config/{slug}.php` already exists before writing
- Existing slug → `HTTP 409`, `{ success: false, error: "Artista com este slug já existe." }`
- This prevents accidental overwrites from n8n retries
- **Ambiguity:** Should the endpoint support overwrites (PUT semantics) for updating artist data? If yes, this behavior changes. Ticket says "creates" only — confirm with n8n workflow author.

---

## Pattern alignment with `api/leads/submit.php`

| Concern | submit.php | create.php |
|---|---|---|
| Content-Type header | `header('Content-Type: application/json')` | Same |
| HTTP method header | `header('Access-Control-Allow-Methods: POST')` | Same |
| Input reading | `file_get_contents('php://input')` | Same |
| JSON decode | `json_decode($json, true)` | Same |
| Env vars | `getenv(...)` | Same (`getenv('API_CREATE_KEY')`) |
| Error logging | `error_log(...)` | Same |
| Response format | `json_encode(['success' => ..., ...])` | Same |
| Graceful degradation | Falls back gracefully on DB errors | Falls back gracefully on filesystem errors |

---

## Files summary

| File | Action | Purpose |
|---|---|---|
| `packages/lp/api/artists/` | Create dir | Hold endpoint |
| `packages/lp/api/artists/create.php` | Create file | POST endpoint (lines ~100-120) |
| `packages/lp/artists/config/` | Created at runtime | Store artist PHP configs |
| `packages/lp/artists/{slug}/media/` | Created at runtime per artist | Artist image uploads |

---

## Existing tests

No existing tests in the repo target the `api/artists/` path (it doesn't exist yet). The `packages/lp/tests/` directory exists but has no artist-related test files.

**Test plan (for implementation phase):**
- Write a PHP integration test or shell-based smoke test that:
  1. Sends a valid POST with `X-Api-Key` → expects `200` + `{ success: true, url: "/artists/test-artist" }`
  2. Sends a POST with missing key → expects `401`
  3. Sends a POST with bad key → expects `401`
  4. Sends a POST missing `slug` → expects `422`
  5. Sends a POST with invalid slug (`"Bad Slug!"`) → expects `422`
  6. Sends a duplicate POST → expects `409`
  7. Verifies `artists/config/test-artist.php` was written on disk
  8. Verifies `artists/test-artist/media/` directory was created
  9. Cleans up test artist files after the test run

---

## Ambiguities to resolve before implementation

1. **Identity fields for auto-generation:** Which fields does n8n send that should feed into `hero_headline` / `hero_subheadline` auto-generation? The ticket says "identity fields" but doesn't name them. Candidates: `display_name`, `category`, `style`, `tagline`. Confirm with the n8n workflow builder.

2. **Overwrite vs create-only:** Should the endpoint support updating an existing artist (PUT semantics) or strictly reject duplicates (409 Conflict)? The ticket says "creates" — confirm whether n8n retries could hit the same slug, and whether an update endpoint is needed separately.

3. **Full field passthrough:** Should the endpoint accept *all* fields from the artist config schema (portfolio, testimonials, bio, FAQ, location, instagram_feed, etc.) and pass them through to the config file unchanged, or only the required subset? Per the AC ("matching the artist config schema"), it should accept all fields. Confirm that the config schema (#13) is finalized.
