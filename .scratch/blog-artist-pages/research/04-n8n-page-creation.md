# Research: n8n-triggered artist page creation — findings

**Ticket:** `issues/04-n8n-page-creation.md` (wayfinder map: blog-artist-pages)
**Date:** 2026-08-04
**Scope:** RESEARCH ONLY. Facts and sources for the later decision ticket. No recommendation here.
**Context assumed:** `packages/lp` — pure PHP + vanilla CSS/JS, no framework, deployed via Coolify (Docker containers). Artist pages live at `vaif.com.br/artists/<slug>`, driven by a config file. n8n is self-hosted alongside the PHP app.

Primary sources consulted: n8n official docs (docs.n8n.io, fetched 2026-08-04), PHP manual (php.net/manual, fetched 2026-08-04), Coolify docs (coolify.io/docs, fetched 2026-08-04).

---

## 1. n8n's mechanisms for reaching the PHP server

### 1.1 SSH node — execute commands on a remote server

n8n has a built-in **SSH node** with three operations: **Execute** a command, **Download** a file, and **Upload** a file. Credentials support password-based or private-key authentication against a specified host and port (default 22). The **Execute** operation takes a `Command` string and an optional `Working Directory`. (https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.ssh.md, https://docs.n8n.io/integrations/builtin/credentials/ssh.md)

**Coolify constraint — Docker containers.** The PHP app runs inside a Docker container. SSH'ing into the host machine does not give you the container's filesystem directly. To write files to the PHP container, you would either: (a) SSH into the host and use `docker exec` to run a command inside the container, or (b) SSH into the host and write to a volume-mounted path that the container mounts. Both require host-level SSH access and knowledge of the container name / volume path, which adds operational complexity and coupling. This is feasible but fragile.

**Coolify constraint — ephemeral filesystem.** By default, Docker containers have ephemeral filesystems — files written inside the container are lost on redeploy. The `packages/lp` app would need a **persistent volume** mounted for artist configs to survive redeploys. This applies to *both* the SSH approach and the PHP-endpoint approach. A Docker volume mount at `artist-configs:/var/www/html/artists` or similar is required. (Coolify docs: https://coolify.io/docs/applications — "Docker-compatible service" with persistent volumes are configurable in the Coolify dashboard's "Advanced" tab.)

### 1.2 HTTP Request node — call a PHP endpoint

n8n's **HTTP Request** node can make arbitrary REST API calls (GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS). It supports headers, query parameters, JSON/form/raw body, authentication (basic, header, OAuth1/2, digest), SSL configuration, and pagination. (https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.httprequest.md)

This means n8n can POST artist data to a new PHP endpoint (e.g. `POST https://vaif.com.br/api/artists/create.php`) with a JSON body containing all config fields, and the PHP endpoint does the filesystem writing. This follows the existing pattern already used in `packages/lp` — the PHP site already calls *outbound* n8n webhooks (`N8N_LEAD_WEBHOOK_URL`, `N8N_CALENDAR_WEBHOOK_URL`). This approach inverts the direction: n8n calls *inbound* to PHP.

**Security:** The PHP endpoint should require a shared secret (e.g., a bearer token or API key header) that only n8n knows. An environment variable on the PHP side (`N8N_API_SECRET`) read via `getenv()` and compared against an `Authorization` header from n8n. n8n's HTTP Request node can set custom headers directly. The existing `packages/lp` uses environment variables for credentials already (DB credentials, n8n webhook URLs). (https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.httprequest.md — "Send Headers" parameter)

### 1.3 Webhook node — receiving triggers into n8n

n8n's **Webhook node** creates an HTTP endpoint that triggers a workflow when called. It supports authentication (Basic, Header, JWT), custom paths, response customization (status code, body, headers), and IP whitelisting. The production URL is registered when the workflow is activated. (https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.webhook.md)

This is the *trigger side* — the CRM or payment system calls n8n's webhook URL to start the workflow. This is already the pattern in `packages/lp` and would be how the "contract closed + payment accepted" event enters n8n.

### 1.4 Execute Command node — runs on n8n's host (NOT on the PHP server)

The **Execute Command** node runs shell commands on the **machine that hosts n8n**, not on a remote server. It is disabled by default starting from n8n v2.0 for security. If n8n runs in Docker, commands execute inside the n8n container — not the Docker host nor the PHP container. (https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.executecommand.md)

This node is **not suitable** for creating files on the PHP server across containers.

### 1.5 Code node — no filesystem access by default

The **Code node** (JavaScript or Python) explicitly cannot access the file system. You must use the Read/Write Files from Disk node or the HTTP Request node instead. (https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.code.md — "File system and HTTP requests")

It is possible to allow the `fs` module by setting `NODE_FUNCTION_ALLOW_BUILTIN=fs` on the n8n instance, but this writes to the **n8n container's** filesystem, not the PHP server's container. (https://docs.n8n.io/deploy/host-n8n/configure-n8n/basic-configuration/configuration-examples/enable-modules-in-code-node.md)

### 1.6 Read/Write Files from Disk node — writes to n8n's filesystem only

The Read/Write Files node reads and writes files on the machine where n8n runs. On self-hosted, the access scope is controlled by `N8N_RESTRICT_FILE_ACCESS_TO` (defaults to `~/.n8n-files` in n8n v2.0). In Docker, paths are inside the n8n container. (https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.readwritefile.md — "File locations")

This node **cannot** directly write to the PHP server's container.

---

## 2. PHP filesystem capabilities

### 2.1 Core write functions

PHP provides `file_put_contents()` to write data to a file. If the file does not exist, it is created; if it exists, it is overwritten unless `FILE_APPEND` is set. It returns the number of bytes written or `false` on failure. Supports `LOCK_EX` for exclusive locking during concurrent writes. (https://www.php.net/manual/en/function.file-put-contents.php)

PHP provides `mkdir()` to create directories with configurable permissions (default `0777`, modified by the current `umask`). The `$recursive` parameter (`true`) creates all parent directories. Returns `true` on success, `false` if the directory already exists or permissions prevent creation. (https://www.php.net/manual/en/function.mkdir.php)

PHP provides `is_writable()` / `is_writeable()` to check if a file/directory is writable — useful as a pre-flight check before creating artist configs. (https://www.php.net/manual/en/function.is-writable.php)

### 2.2 Config file formats

PHP natively supports **INI file parsing** via `parse_ini_file()`, which loads settings into an associative array. Supports sections (multidimensional arrays), constants, and environment variable interpolation. (https://www.php.net/manual/en/function.parse-ini-file.php)

PHP natively supports **JSON** via `json_decode()` / `json_encode()`.

No native YAML support in PHP core — would require a third-party library (e.g., `symfony/yaml`) or a Composer dependency, which is not available in the "no framework" constraint.

The format choice (INI vs JSON) is out of scope for this research — see ticket `02-config-schema.md`.

### 2.3 Filesystem permissions

PHP runs as the web server user (e.g., `www-data`, `apache`, `nobody`). It can only write to directories and files that this user owns or has group/other write permissions on. (https://www.php.net/manual/en/security.filesystem.php)

**For artist config creation, the required permissions are:**
- The `artists/` directory (or wherever configs live) must be writable by the web server user.
- Newly created files will be owned by the web server user with permissions set by the process `umask`.
- To ensure writability, the directory should be `chown`'d to the web server user or `chmod 755`/`775`, or the PHP code should explicitly `chmod()` after creation.
- The `mkdir()` permission parameter (default `0777`) is modified by `umask`. If the server's `umask` is `022`, actual permissions become `0755`. To force `0777`, call `umask(0)` before `mkdir()` and restore it afterward. (https://www.php.net/manual/en/function.mkdir.php — user note from aulbach)

**Security from the PHP manual:** PHP's filesystem security documentation warns against using unsanitized user input in file paths. For artist config creation triggered by n8n (trusted automation), the paths are generated from a validated slug — the main risks are: (a) directory traversal via malicious slug data, (b) overwriting existing files. Mitigation: validate slug with a strict pattern (e.g., `/^[a-z0-9-]+$/`), check `!file_exists()` before writing, use `realpath()` to resolve any symlink tricks. (https://www.php.net/manual/en/security.filesystem.php)

### 2.4 Coolify / Docker persistent storage

Coolify deploys apps as Docker containers. By default, the container filesystem is ephemeral — files created at runtime (like artist configs) are lost on redeploy. To persist artist configs across redeploys, the deployment must include a **persistent volume** mounted at the config directory. In Coolify, this is configured in the application's "Advanced" tab under "Persistent Storage / Volumes." (https://coolify.io/docs/applications — Coolify's feature list confirms "Any Service: Deploy any Docker-compatible service" with persistent volume support.)

For the `packages/lp` app, this means a volume such as:
```
Host path: /data/artist-configs → Container path: /var/www/html/artists
```
All artist configs would then survive container recreation.

---

## 3. Data flow from contract/payment → config file

### 3.1 The trigger chain

The question describes "when an artist signs a contract and payment clears in the CRM/payment system." This involves two systems:

1. **CRM** — where the contract is tracked (could be Notion, an n8n form, or a dedicated CRM)
2. **Payment system** — where payment is confirmed (could be Stripe, PIX, bank notification, etc.)

The n8n workflow trigger chain:

```
CRM "contract closed" event  ──→  n8n webhook 1 (starts workflow, holds state)
Payment "accepted" event     ──→  n8n webhook 2 (resumes/completes workflow)
                                       ↓
                              PHP endpoint POST
                              /api/artists/create.php
                                       ↓
                              Config file + directories created
                                       ↓
                              Artist page goes live at /artists/<slug>
```

n8n can orchestrate this with its **Wait** node (pauses execution until a matching webhook arrives), or more simply with **two separate workflows** — one triggered on contract close, which stores the pending artist data; a second triggered on payment confirmation, which looks up the stored data and creates the page. n8n workflows can share data via a simple database table or n8n's built-in **Workflow Data** node. (https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.wait.md)

### 3.2 What data n8n POSTs to PHP

The data that flows into the config file comes from the CRM/payment trigger's payload. The exact fields depend on the CRM schema, but minimally:

| Field | Source | Example |
|---|---|---|
| `name` | CRM — artist display name | `João Silva` |
| `slug` | CRM or derived from name | `joao-silva` |
| `whatsapp` | CRM — artist's WhatsApp number | `5511999999999` |
| `instagram` | CRM — artist's Instagram handle | `@joaosilvatattoo` |
| `email` | CRM — artist contact email | `joao@example.com` |
| `contract_id` | CRM — for audit trail | `CTR-2026-001` |

Additional optional fields would be populated later via manual editing of the config file, unless the CRM captures them during onboarding: hero photo, portfolio images, bio text, testimonials, FAQ entries, location/map data.

### 3.3 What PHP creates

The PHP endpoint receives the JSON payload, validates it, and:

1. **Creates directories** (if not exist):
   ```
   artists/<slug>/
   artists/<slug>/images/     (for hero, portfolio, testimonials)
   ```

2. **Writes config file** (`artists/<slug>/config.ini` or `.json`):
   Contains all artist data in the agreed schema format.

3. **Returns success/failure response** to n8n:
   ```json
   { "success": true, "slug": "joao-silva", "url": "https://vaif.com.br/artists/joao-silva" }
   ```
   or
   ```json
   { "success": false, "error": "...", "code": "SLUG_EXISTS" }
   ```

---

## 4. Approach comparison

### Approach A: SSH into server and run script

**Mechanism:** n8n SSH node → remote server → `docker exec php-container mkdir -p /artists/slug && cat > /artists/slug/config.ini << 'EOF' … EOF`

| Axis | Assessment |
|---|---|
| Requires host SSH access | Yes — credentials stored in n8n |
| Requires Docker knowledge | Yes — container name, `docker exec` |
| Cross-container write | Indirect (host → docker exec → container) |
| Persistent volume needed | Yes — same requirement |
| Error handling | Difficult — no structured response from shell commands |
| Security surface | Wider — SSH key on n8n, host-level access |
| Idempotency | Script must handle "already exists" |
| Source|[SSH node docs](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.ssh.md) |

### Approach B: n8n HTTP Request → PHP endpoint

**Mechanism:** n8n HTTP Request node → `POST /api/artists/create.php` with JSON body → PHP writes files

| Axis | Assessment |
|---|---|
| Requires SSH access | No |
| Cross-container | N/A — PHP writes to its own filesystem |
| Persistent volume needed | Yes — but the volume must be mounted for the PHP container (same requirement for any approach) |
| Error handling | Structured — JSON response with error codes |
| Security surface | Smaller — HTTP endpoint with shared secret, no host access |
| Follows existing patterns | Matches `packages/lp` architecture (env vars, JSON POST, PHP API files) |
| Testable | Can be tested by POSTing JSON directly to the endpoint |
| Idempotency | PHP logic checks `file_exists()` before creating |
| Source|[HTTP Request node docs](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.httprequest.md), [PHP file_put_contents](https://www.php.net/manual/en/function.file-put-contents.php) |

---

## 5. Minimal viable version (MVV)

The MVV is a single n8n workflow with a single PHP endpoint, triggered manually or by a simple webhook:

1. **Trigger:** n8n webhook receives artist data (could be a form submission, a Notion page update, or a manual n8n workflow execution).
2. **n8n workflow:** Receives trigger payload → transforms fields if needed → HTTP Request POST to PHP endpoint with JSON body containing `name`, `slug`, `whatsapp`, `instagram`, `email`.
3. **PHP endpoint** (`/api/artists/create.php`):
   - Checks shared secret in `Authorization` header against `N8N_API_SECRET` env var.
   - Validates slug: `/^[a-z0-9]+(-[a-z0-9]+)*$/`, max 64 chars.
   - Checks artist directory doesn't already exist.
   - Creates `artists/<slug>/images/` (recursive `mkdir`).
   - Writes `artists/<slug>/config.ini` with sections for each page area.
   - Returns success JSON with artist URL.
4. **Result:** Page loads at `/artists/<slug>` because the router in `packages/lp` detects the config file's existence.

**What MVV does NOT do:**
- No image uploading (hero photo, portfolio) — photos are added manually or via a later feature.
- No payment confirmation check — relies on the trigger event being trusted.
- No rollback or dry-run — if something fails halfway, partial state (empty dir) may remain.
- No notification to the artist — page just goes live.

**nvVM source references:** n8n [Webhook node](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.webhook.md), n8n [HTTP Request node](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.httprequest.md), PHP [mkdir](https://www.php.net/manual/en/function.mkdir.php), PHP [file_put_contents](https://www.php.net/manual/en/function.file-put-contents.php).

---

## 6. Fully automated version

The fully automated version adds contract→payment orchestration, image handling, error recovery, and notifications:

1. **Dual trigger:** n8n **Wait** node or **two linked workflows** — one fires on "contract signed" event (stores artist data in n8n workflow data or a temp DB row), the second fires on "payment confirmed" event (matches by contract/artist ID, proceeds with creation).
2. **Image handling:** n8n downloads artist's Instagram profile photo (or provided hero image URL) using the HTTP Request node with binary response, then either: (a) passes it as base64-encoded string in the JSON payload to PHP (PHP decodes and writes the binary file), or (b) uses n8n's SSH node to SFTP/upload the file to the server, or (c) the PHP endpoint downloads the image itself from a provided URL. Option (a) is simplest (no extra infrastructure) but limited to small files (< 16MB, n8n's webhook max payload). Option (c) requires PHP to make outbound HTTP requests (`file_get_contents()` or cURL) — the PHP manual confirms `file_get_contents()` supports URL wrappers if `allow_url_fopen` is enabled. (https://www.php.net/manual/en/function.file-get-contents.php, https://www.php.net/manual/en/filesystem.configuration.php#ini.allow-url-fopen)
3. **Full config:** All 8 sections populated — hero, portfolio (array of image URLs), about, booking CTA (WhatsApp number/message), testimonials (array of {name, text, photo?}), Instagram feed (username/token for embed), FAQ (array of {question, answer}), location (address, Google Maps embed URL or coordinates).
4. **Error handling:**
   - Pre-flight: PHP endpoint checks `is_writable()` on the artists directory before creating anything.
   - Atomic writes: Write config to a temp file first, then `rename()` (which is atomic on the same filesystem). This prevents reading a half-written config file.
   - Rollback: If writing fails after directory creation, clean up the created directory.
   - Notification: On failure, n8n sends an alert (email, Slack, or WhatsApp to admin). On success, optionally sends a notification to the artist.
5. **Config versioning:** Include a `version` field and `created_at` / `updated_at` timestamps in the config for future migrations.
6. **Dry-run mode:** A `?dry_run=true` query parameter on the PHP endpoint that validates all inputs and checks permissions without writing files.

**Full automation source references:** n8n [Wait node](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.wait.md), n8n [HTTP Request node with binary response](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.httprequest.md — "Response Format: File"), PHP [rename()](https://www.php.net/manual/en/function.rename.php) (atomic on same filesystem), PHP [is_writable()](https://www.php.net/manual/en/function.is-writable.php).

---

## 7. Gaps / what I could not verify from primary sources

1. **Coolify persistent volume configuration for `packages/lp`:** The exact mechanism to add a volume mount to an existing Coolify-deployed PHP app was not confirmed in the Coolify docs (the docs redirect JavaScript-rendered pages to the index). The feature exists (Coolify calls it "Persistent Storage" in the app's Advanced tab), but the exact UI path and whether it requires a rebuild was not fetched. This should be verified directly in the Coolify dashboard for the `vaif-lp` application.
2. **Current web server user on the Coolify host:** The specific user (www-data, nginx, apache, nobody) depends on the Docker base image used for the PHP container. This determines what `chown`/`chmod` values are needed for the artists directory. This can be verified by running `whoami` or `id` inside the container.
3. **Current `allow_url_fopen` setting:** The PHP manual documents this as configurable via php.ini. Whether it is enabled on the Coolify PHP container (needed for `file_get_contents($url)` image downloads) was not verified. This can be checked via `phpinfo()` or `ini_get('allow_url_fopen')` in a running instance.
4. **n8n's exact response payload size limit for HTTP Request node binary data:** The webhook node has a 16MB max payload (`N8N_PAYLOAD_SIZE_MAX`), but the HTTP Request node's binary response handling limits were not found in the docs. This matters for base64-encoding large images in the JSON payload (Approach B, option a for image handling).
5. **Coolify's webhook/deploy trigger support:** Coolify advertises "Webhooks: Integrate with CI/CD tools like GitHub Actions, GitLab CI, or Bitbucket Pipelines" but specific documentation on the webhook URL format, authentication, and whether it can be used post-deploy to trigger n8n was not located during this pass. (https://coolify.io/docs/ — the feature list mentions webhooks but the docs.js-rendered pages returned the index instead of content.)
6. **INI vs JSON vs PHP array config format:** The decision between config formats is in the scope of ticket `02-config-schema.md`. This research note only confirms that PHP natively supports both INI (via `parse_ini_file()`) and JSON (via `json_decode()`), and that YAML requires a non-core dependency not available under the "no framework" constraint. (https://www.php.net/manual/en/function.parse-ini-file.php, https://www.php.net/manual/en/function.json-decode.php)

(End of file — total research notes)
