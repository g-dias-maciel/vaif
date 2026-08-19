# 18 — Artist onboarding

Labels: implementation

## What to build

Partner-triggered stub creation via n8n Form (token-protected). Collects artist name, WhatsApp number, and optional pre-fill fields. On submit: creates artist row (status='stub'), provisions WAHA session, generates onboarding token, returns link. When artist scans QR and sends first message (status='onboarding'), Beatriz enters setup mode — dynamically fills NULL fields, walks through pricing table, collects calendar. On completion, transitions to 'live'.

## Acceptance criteria

- [ ] n8n Form trigger accessible at protected URL (token-based access)
- [ ] Form collects: nome, whatsapp_number, specialties, nao_faco, floor_pct, deposit_type, deposit_value, pix_key, instagram_handle
- [ ] On submit: artist row INSERT with status='stub', wa_session_slug auto-derived
- [ ] WAHA session created via WAHA API with assigned slug
- [ ] Onboarding token generated (one-use, 24h expiry), stored in artists.onboarding_token
- [ ] Form response displays onboarding link: vaif.com.br/onboard/<token>
- [ ] When artist scans QR and sends first message, Beatriz detects status='onboarding' → setup mode
- [ ] Setup: Beatriz asks for each NULL field, walks placement-by-placement for pricing
- [ ] Setup: Beatriz collects working hours (days, shift blocks)
- [ ] Setup: on completion, Beatriz summarizes and asks for corrections
- [ ] Final confirmation: status → 'live', token consumed
- [ ] After live, next inbound non-artist message triggers normal Beatriz behavior

## Blocked by

- #2 — Beatriz on Telegram with Postgres
