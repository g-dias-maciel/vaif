# Staff Copilot (Phase 1)

Internal, staff-only reports that combine deterministic CRM facts with a short
LLM-written diagnosis. Phase 1 is **read-only**: it never changes modes,
webhooks, prompts, handoffs or messages. Suggested actions are text; approval
and execution are a later phase.

## Reports

| Report | Window | Schedule | Contents |
|---|---|---|---|
| **Ops diário** | last 24h | daily 08:00 (`America/Sao_Paulo`) | queue, failed jobs, WAHA/webhook drift, open handoffs, stuck drafts, ingest drops, calibration gate summary |
| **Calibração semanal** | last 7 days (gates 30 days) | Monday 08:10 | per-artist gates, draft/artist comparison quality, top violation rules, missing questions, worst intents, sample failures |

Both are stored in `copilot_reports` and e-mailed to staff. The e-mail contains
the summary and findings; the CRM page contains the full tables and samples.

## UI

Staff nav → **Copiloto** (`/copilot`).

- **Gerar agora** queues a report on the `agent` queue.
- The card shows `queued`, `ready` or `failed`.
- The report page shows the narrative, findings (severity, evidence, suggested
  action) and the deterministic tables behind them.

Only staff can access it. Artists get 403.

## Commands

```bash
php artisan copilot:report ops
php artisan copilot:report calibration
php artisan copilot:report ops --no-mail
```

The same commands are scheduled in `App\Console\Kernel` when
`COPILOT_ENABLED=true`.

## Configuration

```env
COPILOT_ENABLED=true
COPILOT_MODEL=                      # defaults to AGENT_MODEL
COPILOT_TIMEZONE=America/Sao_Paulo
COPILOT_DAILY_TIME=08:00
COPILOT_WEEKLY_DAY=1                # 1 = Monday
COPILOT_WEEKLY_TIME=08:10
COPILOT_MAIL=true
COPILOT_RECIPIENTS=                 # comma-separated; empty = staff users
```

Use a stronger model for the weekly analysis by setting `COPILOT_MODEL`; the
WhatsApp agent keeps using `AGENT_MODEL`.

## Safety

- No tools, no arbitrary SQL/HTTP, no secrets in the prompt.
- Facts come from `OpsFacts` / `CalibrationFacts`; the model only writes prose
  and suggested actions over that JSON.
- The prompt forbids inventing numbers and forbids promoting to `auto` when the
  gates fail.
- Message content that reaches the model is limited to a few short excerpts in
  calibration samples. Ops facts do not include message bodies.
- A report failure never affects the WhatsApp agent, queue or webhook.

## Next phases

- **Phase 2:** staff chat over read-only tools with citations.
- **Phase 3:** action proposals with approve/reject (reapply webhook, retry job,
  create eval case, prompt diff).
- **Phase 4:** richer digests/alerts and per-artist calibration pages.
