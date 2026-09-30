# Integration tests (real stack, opt-in)

These hit the **real** n8n + Postgres (and optionally the deployed admin page).
They mutate the **test artist** (`wa_session_slug = 'default'`, i.e. Bruno) and
clean up after themselves — including on failure. Do not point them at a real
artist.

Nothing here runs in CI; run it manually before releases or after infra changes.

## `calendar-agenda.js`

Exercises the `Artist Calendar Webhook` (path `calendar`) — the seam behind
`/agenda/<token>` — and, if configured, the `/agenda/<token>` PHP page:

1. Seeds the test artist with two clients + two booked tattoos (future dates).
2. `list` → succeeds, resolves the artist, returns the booked tattoos with
   client names and placement.
3. **Calendar correctness:** booked tattoos are excluded from `available`;
   blocking a free slot removes it from `available`, unblocking restores it.
4. `block` / `unblock` round-trip, `suspend` / `resume`, `list` reflects status.
5. Error paths: invalid token → HTTP 401, unknown action → HTTP 400.
6. Optional: fetches `/agenda/<token>` and checks the artist name and a seeded
   client are rendered.
7. Teardown: deletes the seeded leads + calendar rows, restores `status='live'`.

### Run

```bash
# webhook + calendar correctness
node packages/flows/tests/integration/calendar-agenda.js

# also check the deployed admin page
AGENDA_PAGE_URL=https://dev.vaif.com.br \
  node packages/flows/tests/integration/calendar-agenda.js
```

### Env

| Var | Default | Notes |
|---|---|---|
| `N8N_API_KEY` | `~/.n8n-api-key` | needed to create the temporary SQL workflows |
| `N8N_BASE` | `https://n8n.vaif.com.br` | |
| `CALENDAR_WEBHOOK_URL` | `${N8N_BASE}/webhook/calendar` | |
| `ARTIST_SLUG` | `default` | test artist to seed |
| `AGENDA_PAGE_URL` | *(unset)* | set to include the `/agenda/<token>` page check |

### How it seeds

It creates short-lived n8n workflows with a Webhook + Postgres node (using the
existing `main-db` credential) whose SQL is baked into the node, calls them, and
deletes them. No arbitrary SQL endpoint is exposed.

## `full-workflow.js`

The end-to-end test: drives the **deployed Beatriz workflow** through the WAHA
webhook with a reserved fake lead number — real model, real tools, real DB,
real Telegram notifications.

Scenarios:

1. `deposit-signal` — seed the lead at the booking stage, accept, choose a slot;
   asserts Beatriz books and requests the signal, that the Telegram
   **"Sinal solicitado"** alert fires with the **correct amount** (30% of the
   table price) and a `deposit:confirm:` callback, and that the booked date is
   in the future and **matches a slot returned by Check Availability**.
2. `deposit-signal-objection` — same, then the lead questions the signal;
   Beatriz must explain it reserves the slot and is discounted from the total.
3. `coverup-handoff` — a cover-up request; asserts the handoff reply and the
   Telegram **handoff alert** with the reason.

Optionally `--full` adds a best-effort from-scratch conversation.

Turns are adaptive (the model sometimes offers day+time together), and each
turn can be retried (`TURN_RETRIES`). Results are written to
`tests/integration/results/<timestamp>.md` and `.json` for later review.
Telegram test messages are intentionally left in the group.

### Isolation (important)

By default this runs against a **dedicated test artist** (`ARTIST_SLUG=sdr-test`)
whose `wa_session_slug` has **no connected WAHA session**. The workflow's
outbound WhatsApp nodes therefore fail harmlessly (`onError: continue`) — the
test **never messages a real person**, and it does not touch the production
`default` (Bruno) session. Telegram alerts still work (separate channel), so the
signal/handoff notifications are still verified.

The test artist + pricing are created idempotently (fixed UUID
`a0000000-0000-4000-8000-000000000001`) and left in place for reuse; only the
per-run leads/calendar are deleted.

To point it at a different artist set `ARTIST_SLUG` (e.g. `default`) — but if
that session is connected to WhatsApp, replies **will be delivered**, so only do
that with a number you control.

```bash
node packages/flows/tests/integration/full-workflow.js
node packages/flows/tests/integration/full-workflow.js --full
ARTIST_SLUG=default node packages/flows/tests/integration/full-workflow.js   # not recommended
```

Env: `N8N_API_KEY`, `N8N_BASE`, `WAHA_WEBHOOK_URL`, `BEATRIZ_WORKFLOW_ID`
(default `SFfHtbGLkscEk47n`), `ARTIST_SLUG` (default `sdr-test`),
`TEST_TELEGRAM_GROUP` (default the VAIF ops group), `TURN_RETRIES` (default 2),
`TURN_TIMEOUT_MS` (default 90000).

> This test caught real bugs: a `Book Slot` crash when the model omitted numeric
> args, the model computing the signal as R$45 instead of R$450 (now computed by
> the workflow), and the model sending the wrong timezone/year when booking
> (now reconciled by `book_slot_checked`).

## Not covered here

- **Workflow-level booking (agent → Check Availability / Book Slot):** the
  agent's tool calls are mocked in the model evaluation
  (`tests/conversation/run.js --live`). The DB functions themselves are covered
  by `tests/test_harness.py` (`availability`, `book_slot`, `booking_conflicts`).
- **Telegram delivery of the signal/handoff alert:** the message *content* is
  covered offline (`tests/conversation` → notifications). Actual delivery is
  verified manually (the notify node now tolerates a bad group without erroring).
- **Real inbound WhatsApp traffic** — needs a paired session; out of scope.
