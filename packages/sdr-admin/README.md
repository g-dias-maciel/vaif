# sdr-admin — standalone beta bundle for the SDR agent

Self-contained admin surfaces for the beta SDR agent, served separately from
the production landing page (which isn't ready yet). It contains just the two
pages the artist needs:

- `/onboard/<token>` — scan the WAHA QR to connect the artist's WhatsApp Business
- `/agenda/<token>` — see derived availability and block/unblock days off

## Layout

```
sdr-admin/
├── index.php          # front controller (/onboard/* and /agenda/*)
├── onboard/index.php  # QR connect page (calls n8n /onboard-api)
├── agenda/index.php   # agenda admin page (calls n8n /calendar)
├── img/favicon.ico
├── nginx.conf         # nginx + PHP-FPM server block
└── Dockerfile         # php:8.3-fpm-alpine
```

## Environment variables (Coolify / staging)

| Variable | Description |
|---|---|
| `N8N_ONBOARD_WEBHOOK_URL` | `https://n8n.vaif.com.br/webhook/onboard-api` — validate/status/consume QR connect |
| `N8N_AGENDA_WEBHOOK_URL` | `https://n8n.vaif.com.br/webhook/calendar` — availability list + block/unblock |

## Backing n8n workflows (already deployed on staging)

- `Artist Onboard Webhook` (path `onboard-api`) — token validation, WAHA QR
  fetch (base64), scan-state polling, token consume.
- `Artist Calendar Webhook` (path `calendar`) — availability list, block, unblock.

Both resolve the artist from the same per-artist `onboarding_token`; the token
is kept after connect so one link serves both `/onboard` and `/agenda`.

## The link to send the artist

```
https://dev.vaif.com.br/onboard/<token>
```

After they scan and connect they land on `/onboard/sucesso`, and can open
`https://dev.vaif.com.br/agenda/<token>` to manage availability.

The token is generated automatically by the **Artist Onboarding Form**
(`https://n8n.vaif.com.br/form/onboard`) — the form's success page prints the
full link after the artist is created.

## Local dev

```bash
php -S 0.0.0.0:8000 index.php
N8N_ONBOARD_WEBHOOK_URL=https://n8n.vaif.com.br/webhook/onboard-api \
N8N_AGENDA_WEBHOOK_URL=https://n8n.vaif.com.br/webhook/calendar \
php -S 0.0.0.0:8000 index.php
```

## Deploy (Coolify)

1. New app → Dockerfile (`packages/sdr-admin/Dockerfile`), nginx from
   `packages/sdr-admin/nginx.conf`.
2. Set the two env vars above.
3. Redeploy; the pages are reachable at `/onboard/<token>` and `/agenda/<token>`.

> The `onboard/` and `agenda/` pages are copies of `packages/lp/{onboard,agenda}`.
> Keep them in sync when those change.