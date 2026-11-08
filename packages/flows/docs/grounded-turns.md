# Grounded turns

The Beatriz agent is moving from a rules/regex pipeline to **grounded generation**:

```
lead message
  → Preflight Classify (AI)      intent + entities + flags
  → Load Turn Facts (SQL)        slots, price, deposit, conditions, booking, client
  → Agent (AI)                   composes the reply from the facts block
  → Extract Claims (AI)          structured claims about what the reply asserted
  → Validate (code)              every claim must match a fact
  → Repair once (AI) → re-validate
  → still invalid → canonical reply from facts, or handoff
  → Apply Turn (SQL)             quote, booking, reschedule, deposit, stage
```

The model never fetches or invents data. It phrases facts. AI handles meaning;
code handles truth.

## Rules

1. **No regex intent detection.** Intent, entities and flags come from the
   preflight classifier. Regex is allowed only for value normalization (ISO
   dates, cents, phone digits, JSON fences).
2. **Facts come from the database.** `build_turn_facts()` is the only source of
   price, slots, deposit, conditions, booking and studio data.
3. **Claims are structured.** The claims extractor returns `price`, `deposit`,
   `slot_offer`, `day_offer`, `no_availability`, `booking_confirmed`, `handoff`,
   `condition`, `promise`, `question`, `location`.
4. **Validation is exact.** A price must be in the allowed set; a slot must have
   an ID from the facts; a question must ask for a field that is actually
   missing.
5. **Backend acts.** Quote/booking/reschedule/deposit writes use facts, never
   model-supplied values. (Pass 4.)

## Contracts

`preflight-classify.md` → `{intent, entities, flags, confidence}`.

`extract-claims.md` → `{claims: [...]}`.

`build_turn_facts(artist, project, phone, intent, entities)` → `facts`:
`availability`, `pricing`, `conditions`, `booking`, `project`, `client`,
`studio`.

## Shadow mode (Pass 0 — implemented)

Per artist:

```sql
update artist_settings
   set grounding_mode = 'shadow'
 where artist_id = '<uuid>';
```

Modes: `off` (default), `shadow`, `enforce` (Pass 1: price only).

In shadow, the branch runs **after the reply is sent**:

`Stop Typing → Grounding Gate? → Build Shadow Preflight → Shadow Preflight →
Parse Shadow Preflight → Shadow Facts → Build Shadow Claims → Shadow Claims →
Parse Shadow Claims → Validate Shadow Reply → Log Shadow Turn`

Nothing about the reply changes. Rows land in `agent_turn_log`.

Inspect:

```sql
-- last turns with violations
select created_at, intent, reply, claims, violations
from agent_turn_log
where jsonb_array_length(violations) > 0
order by created_at desc
limit 20;

-- daily quality (per artist/mode)
select * from v_agent_turn_quality order by day desc;

-- most common violation
select v->>'rule' as rule, count(*)
from agent_turn_log, jsonb_array_elements(violations) v
group by 1 order by 2 desc;
```

Enforce only after `turns_with_violations` and `turns_without_claims` are near
zero for the target artist.

## Passes

| Pass | Scope | Status |
|---|---|---|
| 0 | Contracts, facts function, shadow logging, validator, tests | **done** |
| 1 | Price grounding + backend quote + canonical fallback | **done** (repair loop pending Pass 2) |
| 2 | Availability + booking from facts | **done** |
| 3 | Remove regex intent/state (preflight owns it) | **done for enforce** |
| 4 | Move all writes to `apply_agent_turn` | **done** (AI repair loop pending) |
| 5 | Conditions + promises + repair loop | **done** |
| 6 | Observability + eval gate | **done** (legacy deletion after live eval) |
| 7 | Returning clients, portfolio, video | **done** (consultation slots pending) |

## Tests

```bash
node packages/flows/definitions/code/test-validator.js
node packages/flows/definitions/code/test-availability-guard.js
node packages/flows/definitions/code/test-price-gate.js
python3 packages/flows/tests/test_system_prompt.py
```

SQL contract: `packages/crm/db/tests/v2_contract.sql` (section 17 covers
`build_turn_facts` and `log_agent_turn`).

## Pass 1 — price enforcement

`enforce` mode adds:

- `Grounding Facts?` after `Load Pricing` loads `build_turn_facts` before the
  prompt is built. `Build System Prompt` appends a `FATOS DESTE TURNO` block
  with the exact table price, deposit and PIX availability.
- `Write Quote` is no longer an agent tool. Quotes come from `Ensure Quote`
  (deterministic) and `Ensure Enforce Quote` (validated price).
- After `Patch Chat State`, `Grounding Enforce?` runs the claims AI and
  `Validate Enforce Reply`. A price outside the facts is replaced by the exact
  canonical price (`Perfeito! Fica R$ X à vista.`). A cover-up or a missing
  price row keeps the legacy handling.
- Every enforce turn is written to `agent_turn_log` with mode `enforce`.

The AI repair loop is intentionally not in Pass 1: the canonical price is
deterministic and safe. It arrives with the full grounded reply in Pass 2.

## Pass 2 — availability and booking

`enforce` mode now interprets the lead **before** loading slots:

1. `Build Enforce Preflight` → `Enforce Preflight` (AI) → `Parse Enforce Preflight`.
2. `Book Enforce Slot` — if the lead chose one of the previously offered
   slots (`selected_slot_start`), the backend calls `book_slot` (new) or
   `reschedule_booking` (existing booking). No model tool.
3. `Load Enforce Facts` — slots for the requested day/window, plus the booking
   that may have just been created.
4. The prompt facts block lists the exact slots, labels and "booked now" state.

After the agent:

- `Validate Enforce Reply` checks `slot_offer`, `day_offer`,
  `no_availability` and `booking_confirmed` against the facts.
- A false "no availability" becomes the canonical list of real slots.
- An invented slot becomes the canonical list of real slots.
- `not_ready` becomes the missing-field question (`local`, `tamanho`, `tipo`).
- A confirmation without a booking becomes the canonical slot offer.
- `Store Enforce Slots` writes `offered_slots` into `chat_state`, so
  "pode ser a segunda opção" resolves on the next turn.

`Check Availability`, `Book Slot` and `Reschedule Slot` were removed as agent
tools. The backend owns scheduling. `Request Deposit` remains a tool until
Pass 4.

> Because the tools are gone, `off` and `shadow` are conversation-only after
> Pass 2: scheduling requires `enforce`. All artists are suspended today, so
> there is no live impact; reactivate an artist only in `enforce`.

## Pass 3 — the enforce path bypasses the regex pipeline

After the AI Agent, `Enforce Agent Path?` splits the workflow:

- **enforce** → claims → validation → `apply_grounded_turn` → quote → slots →
  memory → send. `Parse Classification`, `Build Project Patch`, `Patch Project`,
  `Ensure Quote`, `Patch Chat State` and `Silence Agent on Handoff` do not run.
- **off/shadow** → the legacy `Load Booking` path still runs for now.

`apply_grounded_turn(artist, lead, project, chat, turn, intent, flags, entities,
facts, claims, reply, resolution, offered_slots, lead_text, confidence)` applies:

| Input | Output |
|---|---|
| preflight `entities` | project placement, zone, style, first tattoo, meaning, type; stated lead name |
| `claims` `handoff` / flags / cover-up / aftercare | `aguardando_artista`, handoff lifecycle, agent blocked |
| `claims` `price` | `orcamento_enviado` when the project was new/qualifying |
| `facts.booking.created_this_turn` | keeps the `aguardando_deposito` set by `book_slot` |
| `claims` `question` | `chat_state.pending_question` |
| validated `offered_slots` | `chat_state.offered_slots` |

There is no text parsing in that function. The preflight is the only interpreter.

The legacy regex code is still present for `off`/`shadow`; Pass 4 removes it
together with the remaining model tools.

## Pass 4 — idempotent, backend-only writes

- `agent_turn_actions` + `apply_grounded_turn_once()` make a turn idempotent:
  a repeated `turn_id` returns the first result and writes nothing again.
- `apply_grounded_turn()` now writes the **quote** and the **deposit** from the
  facts:
  - quote only when a price claim survived validation, the price row exists,
    there is no open quote and no booking;
  - deposit only when a deposit claim survived, the amount matches the facts
    and a booking exists.
- The validator returns `effective_claims`, i.e. the claims that match the reply
  actually sent. A canonical replacement drops the claims it replaced, so the
  backend never writes a quote/deposit the lead was not told about.
- `Request Deposit` and `Write Quote` are gone; the AI Agent now has **no data
  tools at all**.
- Deposit claims are validated: amount must equal `deposit_cents` and a booking
  must exist. A wrong deposit is replaced by the canonical facts amount.

Still pending: the AI repair-once loop. Invalid replies currently go straight to
the canonical facts text. That loop lands in Pass 5 together with conditions and
promises.

## Pass 5 — conditions, promises and repair

New artist settings (migration 0028): `reschedule_policy_hours`,
`cancellation_policy_hours`, `payment_methods`, `allow_hold_slot`,
`guarantee_price`.

`build_turn_facts().conditions` carries them. The prompt facts block only lists
configured values; the validator rejects any other policy/promise:

- `reschedule` / `cancellation`: the stated hours must equal the configured hours;
- `payment`: the method must be configured (PIX also follows `pix_available`);
- `deposit`: the amount must match and a booking must exist;
- `hold_slot` / `guarantee_price`: only when the setting allows it;
- `discount`: only when an approved lower offer exists.

Invalid conditions/promises are replaced by a safe reply (no hold, no guarantee,
ask the artist) and produce no backend writes.

### Repair once

`Validate Enforce Reply → Repair Needed?`

- true → `Build Repair → Repair Reply → Parse Repair → Validate Repaired Reply → Resolve Final Reply`
- false → `Resolve Final Reply`

The repair prompt receives the facts, the violations and the original reply and
returns `{reply, claims}`. The same validator runs on the repaired text. If it is
valid it is sent (`resolution = repaired`); if it still violates, the canonical
fallback is used. An empty/failed repair always falls back to the original
validation. The original violations stay in the audit result.

## Pass 6 — observability and the rollout gate

The legacy branch stays connected as the rollback until the grounded path has
live evidence. The deletion is gated on the numbers below.

New:

- `agent_turn_log.repaired` and `original_violations` — repairs are visible even
  when the sent reply was fixed.
- `v_agent_grounding_quality` — per day/artist/mode: turns, turns with
  violations, repaired, canonical, kept legacy, no-claims, average confidence.
- `v_agent_violation_rules` — the rules that fire most.
- `crm:grounding-report --days=7` — prints both views.

Turn ids are now deterministic per chat + lead text + a 10-minute bucket, and a
repeated turn is marked `duplicate`. `Duplicate?` stops it before any second
reply is sent.

### Rollout

1. Set one test artist to `shadow`.
2. Let real traffic run for 24h.
3. Run `php artisan crm:grounding-report --days=7`.
4. Enforce only when:
   - `turns_with_violations` is near zero,
   - `turns_without_claims` is near zero,
   - `canonical_*` is rare,
   - the top violation rules are understood.
5. Keep the legacy branch as rollback for at least a week of enforce, then delete
   it in the cleanup pass.

## Pass 7 — returning clients, portfolio link, video

- `backfill_returning_customers()` turns past bookings into
  `returning_customers` rows. `build_turn_facts().client` carries `returning`,
  `has_past_booking` and `last_booking_at`; the prompt tells the agent not to
  treat that client as new. Run it with
  `php artisan crm:backfill-returning-customers`.
- Portfolio is a **link**, not an image upload: `artist_settings.portfolio_url`
  (falling back to `https://instagram.com/<handle>`). The prompt facts block
  gives the agent the exact link to share. There is no WAHA media branch.
- Videos are handled by the model in natural language. The prompt says the
  agent cannot open videos and should ask for a photo or a text summary; there
  is no canonical override, so the reply sounds like a person.

Consultation slots are still pending: a dedicated `consultation` calendar kind
and a booking flow.
