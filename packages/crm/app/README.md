# CRM — Artist-facing app (Laravel)

The Artist-facing CRM for VAIF. A Laravel 10 app that reads/writes the **same
Supabase Postgres** the SDR agent (Beatriz) writes to — one source of truth.

- **Artists** log in, see their own **Leads pipeline** and **upcoming bookings**,
  and can **mark a lead lost**, **reschedule a booking**, and **add notes**.
- **VAIF staff** log in and browse every Artist's CRM **read-only**.

## Layout

```
app/
├── app/
│   ├── Http/Controllers/{Dashboard,Lead,Booking,Artist}Controller.php
│   ├── Http/Controllers/Auth/LoginController.php
│   ├── Http/Middleware/EnsureRole.php
│   ├── Models/{User,Artist,Lead,CalendarSlot,Event,Note}.php
│   ├── Policies/{LeadPolicy,BookingPolicy}.php
│   └── Services/BookingService.php
├── config/crm.php                 # pipeline_status → PT label map
├── database/migrations/           # users (role, artist_id), notes, promotion_quotes
├── resources/views/               # Blade UI (pt-BR)
└── routes/web.php
```

## Architecture

- **Auth**: Laravel native (session). `users.role` is `artist` or `staff`;
  `users.artist_id` links an Artist user to the `artists` table (nullable for staff).
- **Authorization**: Laravel policies + a `role:` middleware. The app connects to
  Postgres through a service-role connection, so the existing RLS policies (which the
  agent path relies on) are **bypassed, not removed**. See
  `docs/adr/0001-auth-model.md`.
- **Existing tables** (`artists`, `leads`, `calendar`, `events`, promotions) are
  created by `packages/crm/supabase`, not by Laravel. Laravel only migrates
  `users`, `notes` and `promotion_quotes`.
- **`leads` is the pipeline entity.** A Lead row holds both the contact and the
  tattoo project (placement, style, prices, deposit, `pipeline_status`,
  `booked_date`, …). There is no `tattoos` table; `/tattoos/{id}` permanently
  redirects to `/leads/{id}`.

## Setup

1. Configure the connection to the shared Postgres in `.env`:

   ```env
   DB_CONNECTION=pgsql
   DB_HOST=<supabase-host>
   DB_PORT=5432
   DB_DATABASE=postgres
   DB_USERNAME=postgres          # a role that bypasses RLS (service role)
   DB_PASSWORD=<...>
   ```

2. Install dependencies and run Laravel's migrations (only `users` + `notes`):

   ```bash
   composer install
   php artisan migrate
   php artisan db:seed
   ```

3. Serve:

   ```bash
   php artisan serve
   ```

## Seeded local users

| E-mail             | Role   | Artist |
|--------------------|--------|--------|
| `admin@vaif.com.br`| staff  | —      |
| `bruno@vaif.com.br`| artist | Bruno  |

Password is `password` for both (local dev only).

## Creating a staff login

`crm:make-staff` is the only supported way to create a staff (admin) login —
locally and in production. It never uses a default password:

```bash
# interactive: prompts for the password twice, hidden
php artisan crm:make-staff you@vaif.com.br --name="Your Name"

# non-interactive
php artisan crm:make-staff you@vaif.com.br --name="Your Name" --password='…'
```

- Re-running is safe: an existing login keeps its password. Use
  `--reset-password` (hidden prompt) or `--password` to change it.
- `--force` promotes an existing *artist* login to staff and unlinks its artist.
- Do **not** use `php artisan db:seed` to bootstrap production — it creates the
  demo users above with the password `password`.

## Notes on shared data

- `leads.pipeline_status` values are written by the agent; the CRM only maps them to
  Portuguese labels (`config/crm.php`) and never invents new ones.
- Reschedule reuses the agent's `check_availability` Postgres function, so the app
  can't double-book or book outside the Artist's working hours.
- Mark-lost / reschedule / note append to the `events` log (and `notes`), preserving
  the audit trail the rest of the system relies on.
- Notion sync on these app-side edits is **out of MVP scope** (see the plan).

## Deployment

Production runs as a single Nginx + PHP-FPM container on Coolify. See
[`DEPLOY-COOLIFY.md`](DEPLOY-COOLIFY.md) for the Coolify application settings,
the full environment-variable list, Supabase connectivity caveats and a
troubleshooting table.

Quick health check once deployed: `GET /api/up` → `{"status":"ok"}`.
