# Observe mode

`reply_mode = 'observe'` turns Beatriz into a silent operator: she reads both
sides of the artist's WhatsApp, records the conversation, and fills the CRM —
but never composes or sends a reply.

Use it for artists who prefer to sell manually. The CRM stops being a
second job: the Lead, the tattoo brief and a conversation summary are written
from what was said, and quotes/bookings can be applied with one click. Metrics
and methodology audits are later phases (see the PRD at
`.scratch/whatsapp-sdr/issues/13-observe-mode-prd.md`).

## Enabling

Staff → **Calibração** → select **observe** (`/calibration`). The mode is
staff-controlled, like `off/draft/auto`. The migration is
`packages/crm/db/migrations/0042_observe_mode.sql` (Laravel mirror:
`2026_11_12_000000_add_observe_mode.php`).

## What happens on a message

| Direction | What runs | What is written |
|---|---|---|
| Lead inbound | existing debounce job → `ObservedTurnProcessor::observeInbound` | transcript, explicit discovery fields, Lead name, `observed_turns`, summary |
| Artist outbound (`fromMe`, not `source=api`) | webhook records the message → `ObserveArtistMessage` job | transcript (already written), `observed_turns`, summary |

The inbound path still runs the preflight classifier, so only fields the Lead
actually said are written. Inferences stay in `assumptions` and never touch the
Project.

The artist path runs `ArtistSignalExtractor` (`resources/prompts/observe-artist.md`).
It extracts offered slots, an explicit booking confirmation, a reschedule, the
quoted price, the deposit request and promises. The offered slots are stored in
`chat_sessions.agent_state.offered_slots`, so the next Lead message can resolve
"pode ser sábado" to a concrete start time.

## Money and calendar

Nothing that changes money or the calendar is inferred silently. The rules:

| Signal | Action |
|---|---|
| Artist offers slots | stored in `agent_state.offered_slots` |
| Lead accepts one of the offered slots | `book_slot` runs automatically (two-sided) |
| Lead accepts with an active booking | a `reschedule` review item is queued |
| Artist quotes a price | `write_quote` runs automatically (`artist_observed`) |
| Artist quotes before placement/zone exist | `quote` review item |
| Artist asks for a deposit and a booking exists | `request_deposit` runs automatically |
| Artist asks for a deposit without a booking | `deposit_request` review item |
| Artist explicitly confirms a date/time | `booking` review item |
| Artist reschedules | `reschedule` review item |
| Lead says the PIX was sent | `deposit_confirm` review item only |

Review items live in `observed_writes` and are shown as **Para confirmar** on
the Lead page (artist) and **Fila de revisão** at `/calibration` (staff).
Approving runs the same RPC the agent would have called
(`book_slot`, `reschedule_booking`, `write_quote`, `request_deposit`,
`confirm_deposit`, `set_project_stage`). Rejecting changes nothing.

Auto-applied writes are stored with `status = auto_applied` for audit. A
pending item that is superseded by a two-sided signal is flipped to
`auto_applied` instead of being left stale.

No reply, typing indicator, handoff, contact-gate decision or PIX request is
produced. `ObservedTurnProcessor` has no `MessageTransport` dependency on
purpose.

## Summary

After a quiet window (`AGENT_OBSERVE_SUMMARY_DELAY`, default 90s), the newest
`SummarizeConversation` job reads the last messages, calls the summary model and
upserts `lead_summaries`. Older jobs see a newer message and exit, so a burst
costs one summary. The Lead page shows **Resumo da conversa** with the
generated text.

Prompt: `resources/prompts/summarize-conversation.md`.

## Metrics

The same funnel is shown on the Artist's **Análises** page and on the staff
**Performance** page (`/metrics`). Definitions are fixed in
`App\Services\Metrics\ConversationFunnel`:

- **New leads** — Leads whose `first_seen_at` (`OR created_at`) is in the period.
- **Responded** — a new lead has an outbound message after the first inbound.
- **1ª resposta** — median minutes from first inbound to first outbound.
- **Qualified** — the project reached `qualificando` or beyond (events, with the
  current stage as fallback).
- **Quoted** — a quote was created in the period.
- **Booked** — a new lead has an active booking.
- **Closed / Lost** — the project reached `fechado` / `perdido` in the period.
- **Ghosted** — the studio spoke last, the lead did not book/close/lose, and the
  last activity is 7+ days old.
- **Closing rate** — booked leads ÷ new leads. Quote→booking and
  booking→closed are reported separately.

Message metrics come from `agent_memory.chat_memory` on Postgres. On SQLite
(local dev/tests) `TranscriptMetrics` reports unavailable and those fields stay
zero rather than guessing.

## Backfill (onboarding)

```
php artisan agent:observe-backfill {artist-slug-or-uuid} --days=90 --dry-run
php artisan agent:observe-backfill {artist-slug-or-uuid} --days=90 --limit=100
php artisan agent:observe-backfill {artist-slug-or-uuid} --exclude=5511999999999
```

- Enumerates the WAHA chat store (`GET /api/{session}/chats`) and keeps 1:1
  chats only. Groups, broadcasts, newsletters and `status@broadcast` are
  skipped.
- A chat needs at least one inbound message. Blocked numbers and `--exclude`
  numbers are skipped.
- `--dry-run` fetches history and reports counts without writing or calling the
  model.
- Messages keep their original WAHA timestamps and ids; re-running is
  idempotent. The Lead's `first_seen_at` / `last_message_at` are restored from
  the history.
- One interpretation pass per chat: explicit discovery fields, a price quote,
  historical bookings (`created_by = backfill`) and the conversation summary.
- Historical bookings are staged at the historical timestamp
  (`backfill_project_stage`) and past ones are **not** pushed to Google
  Calendar. Upcoming ones sync normally.
- A misclassified personal chat can be excluded up front or archived/deleted
  later from **Clientes** (the existing delete path also removes its calendar
  entries).

## Methodology audit (staff-only)

Observe mode stores the conversation; the audit scores how it was handled.

- **Nightly:** `agent:audit-conversations` finds observe-mode chats quiet for
  `AGENT_AUDIT_QUIET_HOURS` (default 24h) and queues an audit.
- **On demand:** staff see **Auditoria da metodologia** on the Lead page and can
  queue a re-audit.
- **Idempotent:** one row per `(chat_id, transcript_hash)`. A new message creates
  a new hash; an unchanged transcript is never sent to the model twice.
- **Rubric:** 10 steps (`introduction`, `qualification_before_price`,
  `price_explained`, `coverup_handoff`, `offered_slots`, `deposit_requested`,
  `no_unauthorized_promise`, `handoff_triggers`, `confirmation_after_deposit`,
  `follow_up`). Each step is `pass`, `fail` or `na`, with a literal evidence
  excerpt when it fails.
- **Score:** `pass ÷ (pass + fail) × 100`; `na` steps are excluded.
- **Visibility:** staff only. The Artist and the Lead never see audits.
- **Copiloto:** the weekly calibration report includes each artist's audit
  count, average score and top missed steps.

Prompt: `resources/prompts/audit-conversation.md`.
Table: `conversation_audits` (`0044_conversation_audits.sql`).

## Configuration

```env
AGENT_OBSERVE_ARTIST_DEBOUNCE_MS=8000
AGENT_OBSERVE_SUMMARY_DELAY=90
AGENT_OBSERVE_SUMMARY_MESSAGES=40
AGENT_SUMMARY_MODEL=               # defaults to AGENT_MODEL
AGENT_ARTIST_SIGNALS_MODEL=        # defaults to AGENT_MODEL
AGENT_AUDIT_MODEL=                 # defaults to AGENT_MODEL
AGENT_AUDIT_ENABLED=true
AGENT_AUDIT_QUIET_HOURS=24
AGENT_AUDIT_MIN_MESSAGES=6
AGENT_AUDIT_MAX_MESSAGES=80
AGENT_AUDIT_TIME=03:30
```

Both observed-turn and summary jobs run on the normal `agent` queue.

## Invariants

- Suspended artists, groups, blocked numbers and CRM-sent messages are ignored,
  exactly as in the other modes.
- The transcript is written before interpretation; an LLM or RPC failure must
  never lose messages.
- `observed_turns` is deduplicated by WAHA message id, or by
  `(artist, chat, direction, occurred_at)` when the id is absent.
- Observed fields are attributed to `artist_observed` / `observed` in the
  audit log.
