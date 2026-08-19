## Task: Create an implementation plan for ticket #8

Read the ticket below, explore the codebase to understand current state,
and write a concrete implementation plan.

### Ticket
# 20 — Self-serve onboarding portal

Labels: implementation

## What to build

A thin PHP page on the landing site at vaif.com.br/onboard/<token>. Validates the token (single-use, 24h expiry), calls an n8n webhook to fetch the WAHA session QR code, displays it with instructions, auto-refreshes before expiry, and shows success when the session connects. Replaces the partner's copy-paste link delivery with a direct artist experience.

## Acceptance criteria

- [ ] Page at vaif.com.br/onboard/<token> accessible, served from packages/lp
- [ ] Token validated: exists in artists.onboarding_token, not consumed, within 24h
- [ ] Invalid/expired token shows a friendly error page
- [ ] QR code fetched from n8n webhook (WAHA session QR endpoint), displayed on page
- [ ] Instructions shown in Brazilian Portuguese below the QR
- [ ] QR auto-refreshes before WAHA's QR expiry window
- [ ] Page polls WAHA session status — on "WORKING", shows success message
- [ ] Success redirects to "conversa iniciada — abra seu WhatsApp Business" page
- [ ] Token consumed after successful connection, cannot be reused
- [ ] Page is mobile-friendly (artist scans QR from their phone)

## Blocked by

- #6 — Artist onboarding

### Rules
- Output ONLY the plan — no implementation code
- For each change: what file(s), what change, why
- List changes in dependency order
- Flag any ambiguity or missing information
- Note which existing tests will need updating

### Output
Write the plan to `.workflow/tickets/8/plan.md`
