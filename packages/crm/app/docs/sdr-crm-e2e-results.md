# SDR ↔ CRM — Combined End-to-End Test Results

**Date:** 2026-10-27
**Result:** ✅ **50 SDR checks + 30 CRM tests passed, 0 failed**

> **Correction (same day, after the schema fix).** The *SDR* layer below did run
> against the live stack. The *CRM* layer did **not**: `php artisan test` runs on
> SQLite against a local fixture, so it never touched the shared Postgres. That
> fixture invented a `tattoos` table which no Postgres migration creates, which
> is why the first real deploy 500'd on `relation "tattoos" does not exist`. The
> app has since been refactored so `leads` is the single pipeline entity (no
> `tattoos` table, `events`/`calendar` use `lead_id`), and the fixture now mirrors
> the Postgres schema. The SDR results and field list below still stand.


Two complementary suites were run against the **live stack**:

| Layer | Suite | Result |
|---|---|---|
| **SDR (real)** | `full-workflow.js` — drives the deployed Beatriz WhatsApp Agent + real Postgres | ✅ 50 passed, 0 failed (4 scenarios) |
| **CRM (real)** | `php artisan test` — drives the Laravel CRM through HTTP | ✅ 30 tests, 94 assertions |

---

## 1. What this proves

The **SDR agent (Beatriz)** and the **CRM** share one Postgres. The SDR writes
`leads` / `tattoos` / `calendar` / `events`; the CRM reads those exact tables and
acts on them. Both directions were exercised:

- **SDR → DB** (real): Beatriz ran a real conversation, booked an appointment,
  requested the sinal, and wrote correct rows to the production DB.
- **CRM → DB / UI** (real): the CRM renders and mutates those same tables and
  fields through its full HTTP surface.

The fields Beatriz writes (`pipeline_status`, `deposit_status`, `booked_date`,
`placement`, `table_price`, `negotiated_price`, `tipo_tatuagem`, `significado`,
`reference_pics`) are *exactly* the fields the CRM's dashboard, agenda, analytics
and config consume.

---

## 2. Layer 1 — Real SDR (Beatriz) end-to-end

Driven through the live WAHA webhook (`/webhook/waha-webhook`) → workflow
`SFfHtbGLkscEk47n`, with a reserved fake lead (`5511900000195`). Real LLM, real
tools, real Postgres, real Telegram alerts.

> **Isolation:** used the dedicated `sdr-test` artist whose `wa_session_slug` has
> no connected WhatsApp session, so outbound WhatsApp never reached a real person.
> Telegram alerts still fired (separate channel) and were verified.

### Scenario 1 — `deposit-signal` (15 passed)
_Seed near booking → accept → choose slot → Beatriz books and requests the signal._

- **Lead:** "fechado, pode ser"
- **Beatriz:** *"Posso te atender na terça, dia 28, ou na quarta, dia 29…"*
- **Lead:** "terça"
- **Beatriz:** *"Fechado! … terça, dia 28, às 12 horas … sinal no valor de R$ 450, que é descontado do valor total…"* — sent Telegram **💰 Sinal solicitado** with `R$ 450,00`.

Assertions (all ✅):
- Slot offered; deposit alert fired; reply requests the signal.
- Alert text "Sinal solicitado"; amount **R$ 450,00** correct (30% of R$ 1.500).
- `deposit:confirm:` callback present; phone + WhatsApp link shown; Telegram node succeeded.
- **DB:** `deposit_status = aguardando_confirmacao`, booked date stored, in the future,
  inside working hours, and matching a `check_availability` slot.

### Scenario 2 — `deposit-signal-objection` (4 passed)
_Lead questions the sinal; Beatriz explains it reserves the slot and is discounted._

- **Beatriz** (on "por que preciso pagar sinal?"): *"O sinal garante a reserva do seu horário … e é descontado do valor total, então você não paga nada a mais…"*

### Scenario 3 — `coverup-handoff` (5 passed)
_Cover-up request → Beatriz hands off to the artist._

- **Beatriz:** *"Cobertura é um pouco específica. Vou te passar pro SDR Test…"* — sent Telegram **🔔 Handoff** with reason **Cover-up** + WhatsApp link.

### Scenario 4 — `promotion-bogo` (26 passed)
_Activate a real "Leve 2" promotion → quote → repeat ask → pause → fresh lead._

The promotion stack had never been deployed, so this scenario was the first time
the offer path ran in production. It preflights the schema and the workflow and
refuses to send anything if either is missing.

| Turn | Lead | Beatriz |
|---|---|---|
| 1 | "quero fechar, é no braço externo fechamento" | **R$ 3.000,00** (2× the R$ 1.500 table) + *"com a promoção 'Leve 2 E2E', nesse mesmo valor você leva uma segunda tatuagem…"* |
| 2 | "e quanto fica mesmo?" | **R$ 3.000,00** — same price, no second multiplication |
| 3 | (promotion paused) "e quanto fica agora?" | **R$ 3.000,00** — the open quote's 7-day price protection is honoured |
| 4 | brand-new lead, promotion paused | **R$ 1.500,00** — regular price, no offer |

Assertions (all ✅): activation snapshot at 2× (300000 first / 150000 second cap);
`Load Offer` names the promotion; classifier flags `offer_quoted`;
`Write Promotion Quote` writes exactly one `open` quote bound to
`braco_externo/fechamento` with a ~7-day window; pausing keeps it `open`; a fresh
lead sees neither the promotion nor a quote and gets no quote row.

**Two production bugs were found and fixed during this run:**

1. **`Write Promotion Quote` never executed.** The builder connected it as a
   phantom *second output* of `Update Lead` (a Postgres node has only one), so
   no `promotion_quotes` row was ever written while the agent still quoted the
   promo price. Now runs as a parallel branch next to `Log Event`.
2. **The multiplier was applied twice.** After the first quote, the workflow
   stores the offered price as the lead's `negotiated_price`; a repeat "quanto
   fica?" then fed that price back through `deterministicQuote()`, which
   multiplied it again — quoting **R$ 6.000,00**. The multiplier is now only
   derived when there is no already-agreed price; the promotion is still
   mentioned on repeats.

**Deployed to make this live:** `20261022000000_offers_and_chat_control.sql`
(tables + functions) and Beatriz workflow `SFfHtbGLkscEk47n` (now 49 nodes;
also brings the chat-ownership nodes online).

---

## 3. Layer 2 — CRM feature tests (30 tests, 94 assertions)

`packages/crm/app` — full HTTP surface with auth + `role:` middleware + policies,
running on in-memory SQLite (same schema shape), replaying the SDR's write shapes.

| Area | Coverage |
|---|---|
| Dashboard kanban | SDR tattoos render in correct columns; multi-tattoo lead shows "Tatuagens (2)" |
| Agenda + calendar | SDR booking appears with deposit badge; block/unblock; confirm-sinal; cancel |
| Faturamento | Realizado / Agendado / Esperado computed from `pipeline_status` + `deposit_status` |
| Analytics | lead-level funnel (people, not tattoos) + per-tattoo revenue + MoM |
| Actions | mark-lost, note, reschedule, confirm-deposit, cancel, block — all write `events` |
| Staff | list artists, view read-only, toggle agent (`live ↔ suspended`) |
| Config | price-table tabs + WAHA section render |
| WAHA client (unit) | status / qr / create-session / restart against a fake transport, incl. graceful failure |
| Auth | guest → `/login`; `/` → redirect |

All 30 green (see `php artisan test`).

---

## 4. SDR → CRM field mapping (the integration contract)

| SDR writes (Postgres) | CRM consumes / acts |
|---|---|
| `tattoos.pipeline_status` | kanban column, analytics funnel, faturamento split |
| `tattoos.deposit_status` | "Sinal pago / Aguardando sinal / Sem sinal" badge; "Confirmar sinal" action |
| `calendar.booked` + `tattoos.booked_date` | agenda list, week calendar, reschedule/cancel |
| `tattoos.placement / body_zone / style / tipo_tatuagem / significado / reference_pics` | project detail ("Detalhes do projeto") |
| `tattoos.table_price / negotiated_price` | price on card, ticket médio, faturamento |
| `leads.nome / telefone` | client name, WhatsApp link |
| `artists.status` | agent on/off (pause/resume), staff toggle |

The `deposit-signal` scenario produced a lead with `deposit_status =
aguardando_confirmacao` and a future `booked_date` — exactly what the CRM's agenda
would show as "Aguardando sinal" with a "Confirmar sinal" button, and what
"Faturamento → Agendado" would count.

---

## 5. Remaining gaps (flagged, not failures)

- **CRM reading the *live* DB.** The Laravel app can't reach the production
  Postgres from this dev box (no `pdo_pgsql`), so the CRM layer was tested on
  SQLite with identical schema, while the SDR layer was tested against the real
  DB. A box with the service-role Postgres connection would close this.
- **Live WAHA QR/status in the CRM.** The WAHA client is unit-tested (fake
  transport); the config page's live status wasn't exercised (needs the WAHA
  `X-Api-Key`, held in n8n's credential store, not in the repo).
- **Browser E2E** (drag/drop, JS tabs, charts) — not covered.
- **Promotion quotes in the CRM.** The agent writes `promotion_quotes`, but the
  Artist-facing CRM only displays promotion status (Dashboard pill + Config
  list); per-lead quotes are not surfaced yet.

---

## 6. How to run

```bash
# Real SDR E2E (live Beatriz + Postgres + Telegram)
cd packages/flows
export N8N_API_KEY=$(grep -oP 'N8N_API_KEY=\K.*' ../../.env)
node tests/integration/full-workflow.js
node tests/integration/full-workflow.js --only=promotion-bogo   # just the offer path

# CRM feature + unit tests
cd ../crm/app
php artisan test
```
