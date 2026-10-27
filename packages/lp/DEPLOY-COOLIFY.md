# Deploying VAIF LP on Coolify

The landing page (`packages/lp`) is a plain **PHP 8.2 + Nginx** app (no framework).
This folder contains a `Dockerfile` and `deploy/` assets for a single-container
deployment. Configure the app in Coolify as a **Dockerfile** build.

---

## 1. Coolify Application Settings

| Setting | Value |
|---|---|
| Build Type | **Dockerfile** |
| Base Directory | `/packages/lp` |
| Ports Exposes | `80` |
| Build Pack | Dockerfile (leave Dockerfile path default: `Dockerfile`) |

> Because this is a monorepo, the **Base Directory must be `/packages/lp`**.
> The Dockerfile copies everything from that directory into the image.

Connect the app to the repo `g-dias-maciel/vaif` (branch `main`).

---

## 2. Environment Variables

Set these in the Coolify **Environment Variables** tab. Secrets are read at
runtime via `getenv()` in the PHP API files.

| Variable | Description | Required |
|---|---|---|
| `DB_HOST` | MySQL host (Coolify DB internal hostname) | ✅ |
| `DB_NAME` | Database name | ✅ |
| `DB_USER` | Database user | ✅ |
| `DB_PASSWORD` | Database password | ✅ |
| `N8N_LEAD_WEBHOOK_URL` | n8n webhook for new leads | optional |
| `N8N_CALENDAR_WEBHOOK_URL` | n8n webhook for calendar bookings | optional |
| `N8N_AGENDA_WEBHOOK_URL` | n8n webhook for artist agenda availability + block/unblock (webhook path `calendar`) | optional |
| `N8N_ONBOARD_WEBHOOK_URL` | n8n webhook for artist onboarding — validate/status/consume QR connect (webhook path `onboard-api`). Set to `https://n8n.vaif.com.br/webhook/onboard-api`. Without it `/onboard/*` shows "Em manutenção" | required for `/onboard/*` |
| `N8N_DIAGNOSTICO_WEBHOOK_URL` | n8n webhook for the free diagnostic form (`/api/leads/diagnostico.php`) — leads are sent to Telegram approval then emailed. Set to `https://n8n.vaif.com.br/webhook/diagnostico` | optional |
| `ARTISTS_ADMIN_TOKEN` | Secret token that unlocks the artist review panel at `/artistas-admin/<token>`. Falls back to `API_CREATE_KEY` if unset. Generate something long and random. | required for the review panel |

Use Coolify's **`@` secret/database linking** if you attach a Coolify-managed
MySQL database so credentials are injected automatically.

---

## 3. Routing

Routing for `/blog`, `/artists`, `/onboard`, `/agenda`, `/novo-artista` and
`/artistas-admin` is handled by the bundled `deploy/nginx.conf` **inside the
container** — no Custom Nginx Config is needed for the Docker deployment.

> The legacy `nginx-routing.conf` file is only for the old manual
> (non-Docker) Coolify setup. It is not used by this Docker build.

---

## 4. Database

The app expects a `leads` table. If you haven't already, create it on your MySQL
database:

```sql
CREATE TABLE IF NOT EXISTS leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255),
    whatsapp VARCHAR(20),
    instagram VARCHAR(255),
    email VARCHAR(255),
    studio VARCHAR(255),
    origem VARCHAR(50),
    faturamento DECIMAL(12,2),
    ticket DECIMAL(12,2),
    sessoes INT,
    horas_admin DECIMAL(6,2),
    valor_hora DECIMAL(12,2),
    horas_secretario DECIMAL(8,2),
    prejuizo_mensal DECIMAL(12,2),
    potencial_lucro DECIMAL(12,2),
    data_agendamento DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

If the `leads` table already exists (it does for the calculadora), run this
migration instead of recreating it — the diagnostic form
(`api/leads/diagnostico.php`) now inserts into the same table and needs these
three extra columns:

```sql
ALTER TABLE leads ADD COLUMN email  VARCHAR(255) NULL AFTER instagram;
ALTER TABLE leads ADD COLUMN studio VARCHAR(255) NULL AFTER email;
ALTER TABLE leads ADD COLUMN origem VARCHAR(50)  NULL AFTER studio;
```

The artist intake workflow uses a separate `artists` table, which the app
auto-creates on first use (`CREATE TABLE IF NOT EXISTS` in
`lib/artists/store.php`). A copy of the schema lives in
`migrations/001_artists.sql` if you prefer to run it manually.

Artist photo uploads need a raised request-size limit; `deploy/nginx.conf`
sets `client_max_body_size 25M`. Also ensure the PHP-FPM pool allows it —
`upload_max_filesize = 20M` and `post_max_size = 25M` (or higher) in `php.ini`.


---

## 5. Local Verification

Build and run the image locally before deploying:

```bash
cd packages/lp
docker build -t vaif-lp .
docker run --rm -p 8080:80 -e DB_HOST=... -e DB_NAME=... -e DB_USER=... -e DB_PASSWORD=... vaif-lp
```

Then check:
- `curl -I http://localhost:8080/` → 200
- `curl -I http://localhost:8080/blog` → 200
- `curl -I http://localhost:8080/artists/adriano-santos` → 200
- `curl -I http://localhost:8080/css/style.css` → 200
