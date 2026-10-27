# Deploying the VAIF CRM on Coolify

The CRM (`packages/crm/app`) is a **Laravel 10** app. This folder contains a
`Dockerfile` plus `deploy/` assets for a single-container **Nginx + PHP-FPM**
deployment — the same pattern as `packages/lp`.

It is *stateless apart from `storage/`*: the database is the shared
**Supabase Postgres** that the SDR agent (Beatriz) already writes to.

---

## 1. Prerequisites

- The repo `g-dias-maciel/vaif` is already connected to your Coolify instance
  (the LP is deployed from it).
- A DNS record for the CRM, e.g. `crm.vaif.com.br` → your Coolify server.
- The Supabase project's **connection details** and an SSL mode decision
  (see §4).
- Nothing else: migrations for `users`, `notes` and `promotion_quotes` run
  automatically on boot (they are the only tables Laravel owns).

---

## 2. Create the Coolify application

**+ New → Application → Public/Private Repository → `g-dias-maciel/vaif`**, then:

| Setting | Value |
|---|---|
| Branch | `main` |
| Build Pack | **Dockerfile** |
| Base Directory | `/packages/crm/app` |
| Dockerfile Location | `/Dockerfile` (default) |
| Ports Exposes | `80` |
| Domain | `https://crm.vaif.com.br` |
| Healthcheck Path | `/api/up` |
| Watch Paths | `/packages/crm/app/**` *(optional, keeps LP deploys from rebuilding the CRM)* |

> Because this is a monorepo the **Base Directory is mandatory**. Building from
> the repo root would use the wrong context and the image would not boot.

Leave "Custom Docker Options" empty. Coolify handles TLS and HTTP→HTTPS
redirection through Traefik.

---

## 3. Environment variables

Coolify injects these at runtime; the image contains no `.env`. Mark every
secret as **Locked**.

### Application

| Variable | Value |
|---|---|
| `APP_NAME` | `VAIF` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | `base64:...` — generate once (below) |
| `APP_URL` | `https://crm.vaif.com.br` |
| `APP_LOCALE` | `pt_BR` |
| `APP_TIMEZONE` | `America/Sao_Paulo` |
| `LOG_CHANNEL` | `stderr` *(so logs land in Coolify)* |
| `LOG_LEVEL` | `warning` |

Generate the key on any machine with PHP:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

> **Keep this value stable.** Changing `APP_KEY` invalidates every encrypted
> cookie and logs all users out (it does not break stored passwords).

### Database (shared Supabase Postgres)

| Variable | Value |
|---|---|
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | Supabase host (see §4) |
| `DB_PORT` | `5432` (session pooler) or `6543` (transaction pooler) |
| `DB_DATABASE` | `postgres` |
| `DB_USERNAME` | `postgres` (direct) / `postgres.<project-ref>` (pooler) |
| `DB_PASSWORD` | Supabase database password |
| `DB_SSLMODE` | `require` |

The app connects with a **role that bypasses RLS** (service role / `postgres`),
by design — see `docs/adr/0001-auth-model.md`. RLS stays in place for the agent
path; do **not** remove those policies.

### Sessions and storage

| Variable | Value |
|---|---|
| `SESSION_DRIVER` | `file` (default) |
| `CACHE_DRIVER` | `file` (default) |
| `SESSION_SECURE_COOKIE` | `true` |
| `RUN_MIGRATIONS` | `true` (set `false` to run migrations manually) |

Sessions and cache live in `storage/`, so mount a persistent volume (see §5) to
keep artists logged in across redeploys.

### Integrations

| Variable | Value |
|---|---|
| `WAHA_API_URL` | `https://waha.vaif.com.br` (or `http://<internal>:3000`) |
| `WAHA_API_KEY` | WAHA API key |
| `WAHA_WEBHOOK_URL` | `https://n8n.vaif.com.br/webhook/waha-webhook` |
| `ONBOARD_WEBHOOK_TOKEN` | shared secret used by the n8n onboarding flow |
| `MEMBER_AREA_URL` | `https://membros.vaif.com.br/` (optional) |

`ONBOARD_WEBHOOK_TOKEN` is what n8n sends when provisioning a CRM login for a
new artist. It must match the value in the onboarding workflow.

---

## 4. Supabase connectivity gotchas

This is the step that usually breaks, because the app now lives on a different
host than your laptop and connects *outbound* to Supabase.

1. **Use the pooler, not the direct host, unless you have IPv6.** Supabase's
   direct connection (`db.<ref>.supabase.co:5432`) is IPv6-only. Coolify servers
   often have no IPv6 route → `SQLSTATE[08006] could not translate host name`.
   Use the **Session pooler** host from *Supabase → Project Settings → Database →
   Connection pooling*: `aws-0-<region>.pooler.supabase.com:5432`, user
   `postgres.<project-ref>`.
2. **Session pooler (5432), not transaction pooler (6543).** Laravel keeps a
   connection per request and uses prepared statements; the transaction pooler
   breaks both in subtle ways.
3. **Allow the Coolify server's IP.** If *Network Restrictions* is enabled, add
   the server's public IPv4 (or disable restrictions) — otherwise connections
   time out.
4. **`DB_SSLMODE=require`** — Supabase requires TLS. `prefer` also works but
   silently falls back to plaintext if TLS ever fails, so pin it.

Verify from the Coolify server before deploying:

```bash
psql "host=$DB_HOST port=$DB_PORT user=$DB_USERNAME dbname=postgres sslmode=require" -c 'select 1'
```

---

## 5. Persistent storage (recommended)

Add **Storages → Add → Volume Mount**:

| Name | Mount Path |
|---|---|
| `crm-storage` | `/app/storage` |

The entrypoint recreates `storage/app/public`, `storage/framework/{cache,sessions,views}`
and `storage/logs` inside the volume on every boot, so a fresh (empty) volume is
safe. Without the volume the app still works; artists are simply logged out on
each redeploy and logs vanish with the container.

---

## 6. First deploy

1. Click **Deploy**. The first build compiles PHP extensions and runs
   `composer install`, so allow 3–6 minutes.
2. Watch the deploy log for:
   ```
   [entrypoint] running migrations
   [entrypoint] starting php-fpm + nginx
   ```
3. Create the first **staff** login from *Coolify → Terminal*:
   ```bash
   php artisan tinker --execute="
   App\Models\User::updateOrCreate(
       ['email' => 'you@vaif.com.br'],
       ['name' => 'Your Name', 'password' => bcrypt('CHANGE-ME'), 'role' => 'staff']
   );"
   ```
   > Do **not** run `php artisan db:seed` in production: the seeder creates
   > `admin@vaif.com.br` / `bruno@vaif.com.br` with the password `password`.
4. Log in at `https://crm.vaif.com.br/login`. Staff users land on `/artists`
   and can pick an artist to view; artist users go straight to their dashboard.

Artist users are normally created by the n8n onboarding flow through
`POST /api/onboard/login` (guarded by `ONBOARD_WEBHOOK_TOKEN`), so you rarely
create them by hand.

---

## 7. Verification checklist

```bash
curl -sf  https://crm.vaif.com.br/api/up            # {"status":"ok"}
curl -sI  https://crm.vaif.com.br/login | head -1   # HTTP/2 200
curl -sI  https://crm.vaif.com.br/ | head -1        # 302 to /login
curl -s   https://crm.vaif.com.br/.env              # must be 403/404, never contents
```

Then in the browser:

- [ ] Login works and the URL stays `https://` (no mixed-content warnings).
- [ ] Dashboard shows leads/bookings for the artist (proves DB connectivity).
- [ ] `/config` shows the WhatsApp connection state (proves WAHA reachability).
- [ ] Logging out and back in works after a redeploy (proves the volume, if mounted).

---

## 8. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `No application encryption key has been specified` | `APP_KEY` missing, or PHP-FPM is dropping env vars. Confirm `clear_env = no` is in the image (`deploy/php-fpm.conf`) and that `APP_KEY` is set as a *runtime* variable, not only a build variable. |
| 500 on every page, log says `could not translate host name` | Supabase direct host is IPv6-only → use the session pooler (§4). |
| 500 with `SQLSTATE[08006] timeout expired` | Supabase *Network Restrictions* is blocking the server IP. |
| Login form posts, then bounces back to `/login` | Cookie/proxy issue: `APP_URL` must be the `https://` URL, `SESSION_SECURE_COOKIE=true`, and `TRUSTED_PROXIES` must cover the proxy (`*` by default). |
| Mixed-content warnings / assets over `http://` | Same as above — `APP_URL` or the proxy trust setting. |
| `419 Page Expired` on login | `SESSION_DRIVER` misconfigured, or the volume's `storage/framework/sessions` is not writable. Check `chown` in the entrypoint log. |
| Migration fails: `relation "artists" does not exist` | The Supabase schema in `packages/crm/supabase/migrations` was never applied to this project. Apply it first (`supabase db push` or the SQL in `000_coolify_full_migration.sql`). |
| Migrations run on every boot | Expected: `migrate` is idempotent (Laravel's `migrations` table). Set `RUN_MIGRATIONS=false` to skip. |
| Deploy log: `composer install` fails on memory | Raise the build memory in Coolify, or set `COMPOSER_MEMORY_LIMIT=-1`. |
| `502 Bad Gateway` | PHP-FPM died. Check the container log for a fatal in the entrypoint (usually a bad `DB_*` value or unwritable `storage/`). |

---

## 9. Local verification before deploying

```bash
cd packages/crm/app
docker build -t vaif-crm .
docker run --rm -p 8080:80 \
  -e APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')" \
  -e APP_ENV=production -e APP_DEBUG=true -e APP_URL=http://localhost:8080 \
  -e DB_CONNECTION=pgsql -e DB_HOST=... -e DB_PORT=5432 \
  -e DB_DATABASE=postgres -e DB_USERNAME=... -e DB_PASSWORD=... -e DB_SSLMODE=require \
  vaif-crm

curl -s http://localhost:8080/api/up   # {"status":"ok"}
```

Use a read-only/staging database for this — `RUN_MIGRATIONS` defaults to `true`
and the app writes to the shared database.

---

## 10. Redeploying / updating

Push to `main` (touched paths under `packages/crm/app/**`) and hit **Deploy**.
The entrypoint clears and rebuilds config/view caches, so any environment
variable you change in Coolify takes effect on the next restart — no rebuild
needed for env-only changes.
