# Observe Mode — Passive CRM Intake and Artist Performance

Type: spec
Status: Phases 1–5 implemented
Label: implementation
Blocked by: none

## Problem Statement

Beatriz can run a conversation end-to-end, and copilot (draft) mode already
captures what the artist actually replies. But the moment the **artist** handles
the chat, the CRM goes stale:

- The transcript is saved, but the Lead's project card, quote, pipeline stage and
  booking are not.
- `agendamentos` and closing rates are computed only from CRM writes, so any sale
  closed by hand is invisible.
- Artist messages are recorded as text, never interpreted. Discovery data,
  prices and agreed times stay buried in the chat.
- In `off` mode no turn runs at all, so even inbound messages are dropped.

The result is that VAIF can only measure and coach the artists who let Beatriz
sell. Artists who prefer to sell manually — the majority during onboarding —
produce no usable CRM data, no funnel metrics, and no basis for script coaching.

## Solution

Add a fifth behaviour to `artist_settings.reply_mode`: **`observe`**.

The agent stops answering, but keeps reading both sides of the WhatsApp
conversation, interprets what was said, and writes the CRM as if the artist had
filled it in. High-confidence facts are written directly; anything that changes
money or the calendar goes to a one-click review queue.

Observe mode is also the data source for two downstream capabilities:

1. **Performance metrics** — agendamentos, quotes, closes, closing rate,
   first-response time and ghosting, per artist and per period.
2. **Methodology audits** — a post-hoc, whole-conversation review of whether the
   artist followed the VAIF sales script.

### Modes

| Mode | Sends replies | Records transcript | Writes CRM |
|---|---|---|---|
| `off` | no | no | no |
| **`observe`** | **no** | **yes** | **yes (auto + review queue)** |
| `draft` | no | yes | no |
| `auto` | yes | yes | yes |

`draft` remains the copilot: it stores a suggestion and compares it. `observe`
stores no suggestion; it just records and interprets. A future option may run
both, but it is not in scope here.

`observe` is staff-controlled, in the same place as `off/draft/auto`
(`/calibration`). No artist-facing switch in v1.

## Domain Language

| Term | Meaning |
|---|---|
| **Observed turn** | One interpreted unit of conversation: an inbound lead burst or an outbound artist burst, with extracted facts and claims. Never produces a reply. |
| **Observation** | A single interpreted signal (field, quote, stage change, booking, deposit) with evidence and confidence. |
| **Review item** | An observation that needs a human decision before it is written. |
| **Conversation audit** | A staff-only, post-hoc assessment of a whole chat against the VAIF methodology. |

Avoid "shadow" for this feature: `grounding_mode=shadow` already means
"generate a reply but only log it". Observe must not be confused with it.

## Goals

- Every 1:1 WhatsApp conversation — answered by Beatriz or by the artist — ends
  up with an accurate Lead, Project, quote, stage and booking in the CRM.
- The artist does no manual CRM entry and confirms only the risky parts.
- Agendamentos and closing rates are correct for observe-mode artists.
- Staff can compare artists and coach methodology with real conversations.
- Existing history can be imported at onboarding so metrics are not empty on day
  one.

## Non-Goals

- Changing the `auto` or `draft` reply pipelines.
- Making the observer send anything, ever.
- Importing personal chats into the pipeline without a way to exclude them.
- Using Google Calendar events as a booking source (the sync is CRM → Google
  only).
- Multi-channel (Instagram DM, etc.).
- Artist-facing performance dashboards in v1; audits are staff-only at first.

## How It Works

### 1. Capture

The existing `WahaWebhookController` remains the entry point.

- **Inbound lead message** (`fromMe=false`): buffer and debounce as today
  (`ProcessWhatsAppTurn`). In `observe` mode the job runs
  `ObservedTurnProcessor` instead of `TurnProcessor` / `DraftProcessor`.
- **Outbound artist message** (`fromMe=true`, `source != api`): the webhook
  already calls `take_chat_control` (which creates the Lead via `upsert_lead`
  when needed) and `recordArtistMessage`. In `observe` mode it additionally
  buffers the burst and dispatches `InterpretArtistTurn` after the debounce.
  This covers artist-first conversations, where no inbound turn has ever run.
- **Media**: inbound audio/photo handling is unchanged (`AgentMedia`). Artist
  voice notes are recorded as `[ÁUDIO ENVIADO]`; the in-flight
  `TranscribeArtistAudio` job appends the transcript via
  `append_artist_audio_transcript`, so interpretation sees the spoken text.
- **Gates**: groups, newsletters, status broadcasts, blocked numbers and
  suspended artists are ignored exactly as today. Observe mode does **not**
  bypass suspension.
- **Contact gate**: not applied. It exists to stop the agent answering a saved
  contact; the observer never answers.

Transcript writes go to `agent_memory.chat_memory` (already writable by the CRM)
and keep the original message timestamps. Each observed turn is idempotent by
`(artist_id, chat_id, wa_message_id)`.

### 2. Interpret

| Direction | Extractor | Output |
|---|---|---|
| Lead | `PreflightClassifier` (existing) | intent, entities, flags, assumptions, confidence |
| Artist | New `ArtistSignalExtractor` (same shape as `ClaimExtractor`) | discovery values, price, deposit request, offered slots, booking confirmation, conditions, handoff, promises |

Rules borrowed from the existing pipeline:

- Only what the person actually said becomes a fact. Assumptions stay
  assumptions and are never written (`EntityEvidence` guard).
- The artist is the authority on price, style and negotiation. An artist price
  is recorded even when it differs from the price book; the mismatch is flagged
  for staff.
- The extractor sees recent history (last ~12 messages), so short replies
  ("pode ser", "fechado") resolve against what was offered.
- The artist's own extractor must not treat the lead's question as an artist
  statement, and vice versa.

An observed turn stores `facts`, `observations`, `confidence`, model and
transcript message ids in `observed_turns` for audit and replay.

### 3. Write

Writes reuse the existing primitives (`patch_project`, `set_project_stage`,
`book_slot`, quote/payment inserts) through a new `apply_observed_turn` RPC that
takes an `origin` (`artist_observed` / `agent_observed` / `backfill`). We do not
duplicate stage logic in PHP.

The write contract:

| Signal | Written | Confidence policy |
|---|---|---|
| Lead name explicitly stated | `leads.name` | auto |
| Placement, body zone, style, type, first tattoo, meaning | `projects.*` | auto when present in the speaker's own words |
| Tattoo type inference ("na mão" → new) | not written | assumption only |
| Artist states a price | `quotes` row | auto; off-table flagged for staff |
| Lead asks a price | nothing (it is a question) | — |
| Discovery progression | `stage_key = qualificando` | auto |
| Price sent by artist | `stage_key = orcamento_enviado` | auto |
| Slot offered by artist | `agent_state.offered_slots` | auto |
| Lead accepts an offered slot | `calendar_entries` booking | auto when both sides are explicit; otherwise review item |
| Artist asks for deposit/PIX | `payments` requested | auto |
| Lead claims "pix enviado" | review item only | human confirms |
| Artist says "recebi o PIX" | review item (pre-filled) | human confirms |
| Artist confirms/books with an explicit date | review item unless lead also confirmed | review |
| Explicit decline / "não vou fazer" | `stage_key = perdido` | review |
| Cover-up, aftercare, complaint, "quero falar com o artista" | handoff state does not apply in observe mode; a staff/artist note is created | auto note |
| Conversation summary | `lead_summaries` (updated in place) | auto |

No notification, handoff job, PIX request, typing indicator or send is permitted
in observe mode. `ObservedTurnProcessor` must not depend on `MessageTransport`
at all — that is the structural guarantee.

### 4. Review queue

New `observed_writes` table. One row per observation that needs confirmation:

- `kind`: `booking` | `deposit` | `quote` | `stage` | `field` | `note`
- `status`: `pending` | `approved` | `rejected` | `superseded` | `auto_applied`
- `payload`: the exact write to perform (target table, field, value)
- `evidence`: message ids, short excerpt, timestamps, confidence
- `decided_by`, `decided_at`

Where it appears:

- **Lead page** — "Sugestões da Beatriz" panel, reusing the draft panel
  pattern. The artist sees only their own leads.
- **Staff calibration page** — all pending items, filterable by artist/kind.

Approving calls the same RPC the agent would have called (with the correct
`created_by`); rejecting marks it and teaches nothing (no training loop in v1).
Items older than 14 days are marked `superseded`.

### 5. Booking detection

The hardest interpretation, so it gets explicit rules:

1. Artist offers a time ("tenho sábado 14h ou domingo 14h"). Both are stored as
   offered slots, exactly like `agent_state.offered_slots` from the agent path.
2. Lead accepts ("pode ser sábado"). If it maps unambiguously to one offered
   slot and the project has `tattoo_type`, `placement` and `body_zone`, create
   the booking via `book_slot` with `created_by='artist_observed'`.
3. If the project is not qualified enough for `book_slot`, the booking is still
   meaningful evidence; create a review item with the proposed datetime.
4. If the agreed time does not match an offered slot (e.g. the artist says
   "fechado dia 12 às 14h" without offering it), create a review item.
5. The booking path sets the stage the same way `book_slot` does
   (`aguardando_deposito`). It never marks `agendado`/`fechado` on its own.
6. Reschedules detected in chat create a review item; they are not applied
   silently.

Historical backfill uses the same rules but marks bookings `created_by='backfill'`
and does not enqueue Google sync for past dates.

### 6. Metrics

New `ConversationFunnel` service, shared by the artist analytics page and a
staff comparison view. Definitions are fixed here so numbers are comparable:

- **New leads**: distinct Leads with `first_seen_at` in the period.
- **Responded**: new lead has at least one outbound message after their first
  inbound.
- **First response**: minutes from first inbound to first outbound; report the
  median, not the mean.
- **Qualified**: project reached `qualificando` or beyond (event log).
- **Quoted**: a quote created in the period (any origin).
- **Booked (agendamentos)**: active `calendar_entries` of kind `booking` created
  in the period. Also report upcoming bookings by `start_at` separately.
- **Closed**: project reached `fechado` in the period.
- **Lost**: project reached `perdido` in the period.
- **Ghosted**: no lead inbound after the last outbound, no booking/closed, and
  ≥ 7 days of silence.
- **Closing rate**: booked leads ÷ new leads. Secondary: quote→booking,
  booking→closed.
- **Response and ghosting** are shown for context, not folded into the closing
  rate.

Current `AnalyticsController` "won" logic (`agendado` + `fechado`) stays as the
legacy revenue view; the new funnel is additive and labelled.

### 7. Methodology audit

Staff-only, post-hoc, one audit per conversation after it goes quiet (24h
without messages, no audit since the last message). Nightly job plus an
on-demand "auditar agora" action.

Rubric (each step: `pass` / `fail` / `na` + evidence quote):

1. Introduced the studio/artist in the first reply.
2. Qualified before quoting: placement, body zone/size, style, tattoo type,
   first tattoo, reference photo.
3. Explained the price with process/quality, not just the number.
4. Cover-up/rework never quoted by the agent or closed without artist review.
5. Offered concrete times and framed them as **pre-reserved**, not confirmed.
6. Asked for the deposit/PIX after the slot choice and before confirming.
7. No promise of holding a slot, guaranteed price or unauthorized discount.
8. Handoff triggers respected: complaint, aftercare, explicit artist request,
   sensitive case.
9. Confirmed the booking only after the deposit was confirmed.
10. Followed up on lead silence within the studio's response policy.

Output in `conversation_audits`: score (passed ÷ applicable steps), findings with
evidence, transcript hash, model, `generated_at`. The existing
`CalibrationReport`/Copiloto weekly report aggregates these per artist (most
missed step, worst chats, average score). Audits are never shown to the Lead and
not to the artist in v1.

### 8. Backfill

Onboarding action, staff-triggered:

```
php artisan agent:observe-backfill {artist} --days=90 --dry-run
```

- Enumerate chats through a new `WahaService::chats()` (`GET
  /api/{session}/chats`).
- Keep 1:1 chats only; skip groups, status, newsletters, broadcasts and blocked
  numbers. Require at least one inbound message.
- Import messages with original timestamps and WAHA ids; dedupe against
  `agent_memory` and against previous runs.
- Interpret per conversation (not per message) to control cost.
- Mark imported Leads with `source` from the earliest known context (default
  `whatsapp_backfill`); set `first_seen_at` to the earliest inbound message.
- Historical bookings: recorded with `created_by='backfill'`; past bookings do
  not enqueue Google sync and do not create notifications. Stage:
  `fechado` if the booking is in the past, `agendado` if upcoming, unless the
  conversation explicitly says otherwise.
- Generate summaries for chats with at least 6 messages.
- `--dry-run` reports chats/messages/observations and writes nothing.
- A staff can archive/delete a misclassified Lead; the existing `clients.destroy`
  path already handles calendar cleanup.

## Data Model

| Change | Detail |
|---|---|
| `artist_settings.reply_mode` | Extend check constraint to `off, observe, draft, auto`. Update `CalibrationController` validation and `/calibration` UI. |
| `observed_turns` | `id`, `artist_id`, `lead_id`, `project_id`, `chat_id`, `direction` (`inbound`/`outbound`), `wa_message_id`, `occurred_at`, `text_hash`, `intent`, `facts`, `observations`, `model`, `status`, `created_at`; unique `(artist_id, chat_id, wa_message_id)`. |
| `observed_writes` | Review queue as described above. |
| `conversation_audits` | `id`, `artist_id`, `lead_id`, `chat_id`, `window_start`, `window_end`, `transcript_hash`, `status`, `score`, `findings`, `model`, `generated_at`; unique `(chat_id, transcript_hash)`. |
| `lead_summaries` | `lead_id` PK, `body`, `message_count`, `generated_at`, `model`; updated in place. |
| `calendar_entries.created_by` | Add values `artist_observed`, `agent_observed`, `backfill` (free text today). |
| `quotes.created_by` | Add values `artist_observed` / `backfill`. |
| `agent_turn_log` | Unchanged; observer turns live in `observed_turns`. |

## Safety and Privacy

- **Never send.** The observe processor has no transport dependency, and the
  transport is not reachable from the write path.
- **No side effects:** no handoff jobs, no contact-gate notifications, no PIX
  requests, no Google sync for backfilled past bookings.
- **Idempotent:** turn-level dedupe; approving a review item runs once.
- **Fail soft:** transcript storage happens first; interpretation is a retried
  queue job. An LLM failure must never lose messages.
- **Human confirmation** for bookings, deposit receipts, lost stages and
  reschedules.
- **LGPD:** observing all 1:1 chats is personal-data processing beyond the agent
  path. The artist contract/onboarding must disclose it; audits are staff-only;
  access follows the existing Artist/Staff roles. Retention follows the existing
  data policy (not specified in this PRD).
- **Cost:** roughly two LLM calls per exchange (one per direction) plus media.
  Backfill and audits run on separate queues.

## User Stories

### Artist

1. As an artist, I can choose "só registrar" so Beatriz stops answering but my
   CRM still updates itself.
2. As an artist, I never have to type placement, size, style or price into the
   CRM again after a WhatsApp conversation.
3. As an artist, I see the appointment I agreed in chat appear on my CRM
   calendar after one confirmation.
4. As an artist, I only confirm bookings, deposits and losses — not discovery
   data.
5. As an artist, I get a short summary of each conversation on the Lead page.
6. As an artist, I can keep my Google Calendar sync for appointments agreed in
   chat.

### VAIF staff

7. As staff, I can switch an artist to observe without touching n8n.
8. As staff, I see every pending interpretation across artists in one queue.
9. As staff, I see agendamentos, quotes, closes, closing rate and ghosting per
   artist and period.
10. As staff, I see which artists follow the qualification order and which quote
    before qualifying.
11. As staff, I can audit a specific conversation on demand.
12. As staff, I can backfill an artist's WhatsApp history at onboarding.
13. As staff, I can exclude a misclassified personal chat.

### Lead

14. As a lead, nothing changes: I talk to the artist exactly as before, and the
    assistant never interjects.

## Phases and Acceptance Criteria

### Phase 1 — Mode, transcript, safe writes

- `observe` is accepted by the mode form and persisted; `off/draft/auto`
  unchanged.
- In observe mode, inbound and artist outbound messages both appear in the
  Lead's conversation with correct sender labels and timestamps.
- A Lead is created for artist-first conversations.
- Name and discovery fields are written from explicit statements only; no
  assumptions are written.
- A `lead_summaries` row exists and updates after each quiet window.
- No WhatsApp send, typing event, notification or handoff occurs (test asserts
  transport is never called).
- Suspended artists, groups and blocked numbers still produce no observation.

### Phase 2 — Review queue and bookings

- A two-sided explicit slot agreement creates a booking and moves the stage to
  `aguardando_deposito`.
- A one-sided or ambiguous agreement creates a pending review item and no
  calendar row.
- Approving a booking item creates the calendar entry; rejecting writes nothing.
- "Pix enviado" creates a review item; it never confirms the payment.
- Review items are visible on the Lead page (artist) and `/calibration` (staff).
- Duplicate webhooks/backfill cannot create duplicate bookings or reviews.

### Phase 3 — Metrics

- `ConversationFunnel` returns the definitions above for a given artist/period.
- `/analytics` shows new leads, responded, median first response, quotes,
  bookings, closes, lost, ghosted, closing rate, quote→booking, booking→closed.
- A staff comparison page lists the same per artist.
- An observe-mode artist with 20 leads, 12 quotes and 6 bookings reports a
  closing rate of 30%.
- Numbers are correct for backfilled history.

### Phase 4 — Methodology audit

- A quiet conversation is audited once; a new message produces a new audit.
- Each rubric step has pass/fail/na and at least one evidence excerpt when it
  fails.
- Audit runs from a nightly job and on demand; failures don't block ingestion.
- The weekly Copiloto report includes per-artist audit score and top missed
  steps.
- Audits are not visible to the artist.

### Phase 5 — Backfill

- `--dry-run` writes nothing and reports counts.
- Imported chats keep original timestamps and don't duplicate existing messages.
- Personal chats can be excluded; excluded chats do not affect metrics.
- Historical bookings appear in the funnel but are not pushed to Google Calendar.

## Defaults Chosen

- Review queue visible to the artist for their own leads and to staff for all.
- Audit after 24h of silence, nightly, minimum 6 messages.
- Backfill window 90 days.
- Ghosted after 7 days without a lead reply.
- Do **not** auto-mark `perdido`; ghosting is reported separately.
- Summary note updated in place, not appended.
- Historical bookings within the Google sync window still sync; older ones do
  not.

## Open Questions

- Retention: how long do observed transcripts and audits live? (No policy today.)
- Should observe mode create an artist-facing toggle later, or stay staff-only?
- Do we want a per-turn silent draft comparison on top of the conversation
  audit? Deferred; cost decision.
- Who receives the audit report — staff only, or does the artist see a
  simplified scorecard?

## Out of Scope

- Auto-reply behavior and prompt changes.
- Notion sync.
- Instagram/other channels.
- Google Calendar import as a booking source.
- Pricing or promotion logic.
- A training loop from artist corrections (review decisions are feedback for
  staff, not model training).

## Related

- [PRD — WhatsApp SDR Closer](12-sdr-closer-prd.md)
- `packages/crm/app/docs/agent-calibration.md` (modes, draft comparison, gates)
- `packages/crm/app/docs/copilot.md` (staff reports)
- Migrations `0025_grounded_turns.sql`, `0026_apply_grounded_turn.sql`,
  `0033_agent_calibration.sql`
- `WahaWebhookController`, `ProcessWhatsAppTurn`, `DraftProcessor`,
  `TurnProcessor`, `DraftComparator`, `CalibrationReport`, `WahaService`
- In-flight artist audio transcription (`TranscribeArtistAudio`,
  `0041_artist_audio_transcript.sql`)
