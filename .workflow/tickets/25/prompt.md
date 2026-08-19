## Task: Create an implementation plan for ticket #25

Read the ticket below, explore the codebase to understand current state,
and write a concrete implementation plan.

### Ticket
# n8n artist creation endpoint

Labels: implementation

## Parent

[Build Spec: Blog + Artist Landing Pages for vaif-lp](https://github.com/g-dias-maciel/vaif/issues/20)

## What to build

A secure PHP API endpoint (`POST /api/artists/create.php`) that n8n calls when a contract closes, creating an artist config file and media directory on the server.

## Acceptance criteria

- [ ] Auth: `X-Api-Key` header checked against `API_CREATE_KEY` env var. Missing/invalid key returns 401
- [ ] Accepts JSON body with artist config fields matching the [artist config schema](https://github.com/g-dias-maciel/vaif/issues/13)
- [ ] Validates required fields: `slug`, `display_name`, `whatsapp_number`. Missing → 422 with error message
- [ ] Validates `slug` format (lowercase, hyphens, alphanumeric) and rejects invalid slugs
- [ ] Creates `artists/config/{slug}.php` with `<?php return [...];` content
- [ ] Creates `artists/{slug}/media/` directory
- [ ] Atomic write: writes to temp file first, then renames (so partial files never exist)
- [ ] Auto-generates `hero_headline` and `hero_subheadline` from identity fields if n8n omits them
- [ ] Returns `{ success: true, url: "/artists/{slug}" }` on success
- [ ] Returns `{ success: false, error: "..." }` with appropriate HTTP status on failure
- [ ] Errors logged via `error_log()`
- [ ] Follows `api/leads/submit.php` pattern: `getenv()`, JSON response, PDO if needed, graceful degradation

### Prerequisite
- [ ] Persistent Docker volume mounted at `artists/` in Coolify so configs survive redeploys

## Blocked by

- [Artist landing pages](https://github.com/g-dias-maciel/vaif/issues/24)

### Rules
- Output ONLY the plan — no implementation code
- For each change: what file(s), what change, why
- List changes in dependency order
- Flag any ambiguity or missing information
- Note which existing tests will need updating

### Output
Write the plan to `.workflow/tickets/25/plan.md`
