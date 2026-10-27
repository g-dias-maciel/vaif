# Sinal — automatic deposit confirmation platform (idea)

**Status:** idea · **Captured:** 2026-10-27 · **Not scoped, not scheduled**

## Problem

Today the Artist asks for a deposit ("sinal") over WhatsApp, sends a Pix key, and
then manually checks the bank app to see whether the money arrived. That creates
three problems:

1. **Delay.** The Lead waits while the Artist is tattooing; some disappear.
2. **No proof in the CRM.** Deposit status is whatever someone typed, so the
   pipeline and the bank disagree.
3. **No revenue for VAIF.** The payment rail is the Artist's own Pix key.

## Concept

A VAIF-operated deposit link. The agent (Beatriz) or the Artist generates a link
from the CRM; the Lead pays by Pix; the provider confirms it via webhook; the CRM
marks the deposit as confirmed automatically and the booking proceeds.

```
Lead ── WhatsApp ──▶ Beatriz ── "here is your deposit link" ──▶ checkout page
                                                                   │ Pix
                                                                   ▼
 CRM ◀── webhook (paid / expired / refunded) ◀── PSP (Asaas, Pagar.me, Stripe…)
   │
   └─ payments.status = confirmed, booking unlocked, Artist notified
```

The CRM already has the seam: `payments.method`, `payments.external_ref`,
`payments.status` and `payments.confirmed_at`. v2 ships with manual confirmation;
this idea replaces only the confirmation step, not the data model.

## Why it could be a product, not just an integration

- **Fee per confirmed deposit.** VAIF takes a cut of each deposit it confirms
  (e.g. 1–2%, or a fixed fee), charged to the Artist or deducted from the
  settlement. This is revenue on money that already moves.
- **Lower no-show rate.** Instant confirmation means the slot is locked while
  the Lead is still in the conversation.
- **Sticky.** Once deposits, receipts and refunds live in the CRM, switching
  costs the Artist their payment history.
- **Upsell path.** The same rail can later handle balances, packages and
  "Leve 2" promotions, not just deposits.

## Two ways to build it

| Option | How it works | Trade-off |
|---|---|---|
| **A. PSP link + webhook** | VAIF creates a Pix charge through a PSP (Asaas/Pagar.me/Stripe) using the Artist's account or a VAIF subaccount; PSP sends the webhook. | Fastest. Funds can settle directly to the Artist. VAIF fee needs a split/subaccount arrangement. |
| **B. VAIF as intermediary** | Money lands in a VAIF account, VAIF settles to the Artist minus the fee. | Better fee capture, much heavier: reconciliation, regulation, custody risk. Not a first step. |

Start with **A**. Use the PSP's marketplace/subaccount feature so VAIF can take
a fee without holding customer funds. Confirm the regulatory position with the
PSP before signing anything; Pix is regulated by the BCB and "who is the merchant
of record" is a contract question, not a schema question.

## MVP (if/when it is picked up)

1. `create_deposit_link(p_artist_id, p_project_id)` RPC → PSP charge + `payments`
   row with `status='requested'`, `external_ref`, `method='pix'`.
2. Public checkout page (no login) with amount, Artist name, expiry, and the
   project reference.
3. Webhook endpoint: verify signature, idempotent by `external_ref`, map
   `paid` → `confirmed`, `expired` → `failed`, `refunded` → `refunded`.
4. CRM shows the link status live; Artist can still confirm manually as a
   fallback.
5. Reconciliation job: daily PSP report vs `payments`; alert on drift.
6. Fee ledger: `fee_cents` per payment, payable to VAIF; report monthly.

## Risks and open questions

- **Merchant of record and fee bearer:** does the Artist pay the fee, the Lead,
  or is it deducted from the deposit? Affects pricing and the checkout copy.
- **Settlement:** does the PSP pay the Artist directly, or VAIF first? Decides
  whether this is a product or a payment institution.
- **Refunds and disputes:** who initiates, who bears the fee, how the CRM records
  a partial refund.
- **Reconciliation:** Pix is instant but not infallible; webhook loss must not
  silently confirm a booking.
- **Multi-PSP:** one provider is fine for the MVP; abstract `external_ref` and a
  provider column now if a second is likely.
- **LGPD:** checkout pages hold payer data; keep it at the PSP wherever possible.
- **Offline Artists:** some will keep using their own bank app. Manual
  confirmation must remain available permanently.

## Relationship to the v2 schema

Nothing in `docs/design/crm-data-model-v2.md` needs to change to enable this.
`payments` is deliberately provider-agnostic; the only likely additions are a
`provider` column, `fee_cents`, and a `payment_webhook_events` table for
idempotency and audit. Those are additive migrations.

This idea is **not** part of the v2 cutover. Manual deposit confirmation ships
first; the platform is evaluated after there are live Artists and a measured
no-show rate to justify it.
