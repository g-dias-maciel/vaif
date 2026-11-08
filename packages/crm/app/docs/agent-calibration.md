# Agent calibration (copilot / draft mode)

Phase 1 lets the agent read live WhatsApp messages and produce a reply, but
**never send it**. The artist answers normally; the CRM pairs the draft with the
artist's real reply and scores it against the sales methodology. This is the
"learning phase" we sell per artist.

## Modes

`artist_settings.reply_mode`:

| Mode | Behaviour |
|---|---|
| `off` | No drafting, no replies. |
| `draft` | Full grounded pipeline runs, the draft is stored, nothing is sent and no booking/quote/deposit is written. |
| `auto` | Validated reply is applied and sent (the production enforce path). |

New rows default to `draft`. Promotion to `auto` is gated by the calibration
report.

## Running a pilot

1. Make sure the artist has prices, policies, portfolio, hours and handoff
   triggers configured.
2. Set the mode explicitly:

   ```bash
   php artisan agent:promote adriano-studio-madri --to=draft
   ```

   `draft` is allowed even while the artist is `suspended`: the webhook reads
   and drafts, but never sends. Use `reply_mode=off` if drafting should stop
   too.

3. Point only that artist's WAHA session at the CRM:

   ```bash
   php artisan agent:webhook adriano-studio-madri
   ```

   Do **not** add the CRM as a second webhook while n8n is still active; n8n
   would auto-reply and the artist would not be the one answering.

4. The artist keeps answering from the same WhatsApp. Every `fromMe` message is
   captured by the webhook and paired with the newest open draft.
5. The lead page shows a **Sugestões da Beatriz** panel with the draft, the
   artist's reply and the comparison verdict.
6. Check the scoreboard:

   ```bash
   php artisan agent:calibration-report adriano-studio-madri --days=30
   php artisan agent:calibration-report --days=30 --json
   ```

## What the draft path does

- `start_turn` creates/loads the lead, project and chat session.
- Audio is transcribed and reference photos are analyzed (same as auto mode).
- Preflight → facts → prompt → agent → claims → validator → one repair attempt.
- A selected slot is **simulated** in the facts (so the draft can say "agendado")
  without writing a calendar entry.
- The draft is stored in `agent_drafts`.
- The lead message is stored in `agent_memory` with its arrival time, so the
  conversation transcript stays complete.
- The draft itself is **not** written to `agent_memory`, so the live conversation
  never contains something the lead did not receive.
- No `apply_grounded_turn`, no send, no quote/booking/deposit, no handoff state.

## Pairing and comparison

When the artist replies:

1. The webhook records the artist message (as before).
2. It finds the newest open draft for the chat.
3. If the lead already sent a newer message, the draft is skipped — the newer
   draft will consume that answer.
4. The artist's text is attached; multiple bubbles within five minutes are
   concatenated.
5. `CompareDraft` waits for a quiet window, then:
   - extracts claims from the artist reply and runs the same validator against
     the draft facts;
   - asks the judge model to compare draft vs artist using the methodology
     rubric;
   - stores `artist_claims`, `artist_validation` and `comparison`.

The artist is the authority on style and negotiation. The methodology is the
bar. The report distinguishes **agent missed** from **artist deviated**.

## Gates

Hard gates (block `auto` unless `--force`):

| Gate | Target |
|---|---|
| Compared pairs | `agent.calibration.min_compared` (default 40) |
| Drafts with invented facts | 0 |
| Missing handoffs | 0 |
| Acceptable drafts | ≥ 90% |
| Drafts answered by the artist | ≥ 90% |

Soft warning: core intent coverage (`ask_price`, `ask_availability`, `qualify`,
`objection`, `close`, `reschedule`, `aftercare`, `coverup`; default 5 each).

Configuration: `config/agent.php` → `calibration`, or
`AGENT_CALIBRATION_MIN_*` env vars.

Promote only after the gates pass and the artist signs off:

```bash
php artisan agent:promote adriano-studio-madri --to=auto
```

`--force` exists for an explicit human override; do not use it silently.

## What to inspect

```sql
select status, count(*) from agent_drafts group by status;
select intent, count(*) from agent_drafts group by intent order by 2 desc;

select draft_reply, artist_reply,
       comparison->'judge'->>'verdict' as verdict,
       comparison->'judge'->>'rationale' as rationale
from agent_drafts
where status = 'compared'
order by created_at desc
limit 20;
```

## Limitations

- The artist must reply from the connected WhatsApp; an answer sent from another
  device/number cannot be paired.
- Low volume delays calibration: the sample gate is compared pairs, not days.
- Drafts do not advance pipeline stages or write quotes/bookings. The artist's
  manual handling is outside the CRM pipeline during the pilot.
- The judge is an LLM; treat its verdict as a signal alongside the artist's
  explicit feedback.
- If WAHA restarts, re-apply the session webhook (`agent:webhook`) — WAHA Core
  does not persist session config across restarts.
