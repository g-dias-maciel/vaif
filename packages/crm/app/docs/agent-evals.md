# Agent fake cases (simulate + eval)

The grounded WhatsApp agent runs inside the CRM (`App\Services\Agent`). Its
quality gate is a curated set of fake cases that exercise the **real** prompt,
preflight, claim extractor, validator, repair loop and send path. Only the CRM
fixtures and the model answers are fake.

## Two commands

| Command | Model | Use it for |
|---|---|---|
| `php artisan agent:simulate` | Scripted answers from the scenario file | Fast, deterministic regression after changing a prompt, validator rule or the pipeline. Runs offline. |
| `php artisan agent:eval` | Real OpenRouter model | Before deploying a prompt/model change, and as a periodic regression against model drift. Writes a JSON report. |

Both accept `--scenario=path/to/case.json` or `--dir=path/to/directory`.
`agent:eval` also accepts `--model=` and `--output=`.

```bash
php artisan agent:simulate
php artisan agent:simulate --scenario=tests/Agent/Scenarios/02-cover-up-never-quotes.json
php artisan agent:eval --model=anthropic/claude-haiku-5.5
```

The eval report lands in `storage/app/agent-evals/<timestamp>.json` with the
final reply, resolution, validator rules, repair flag, latency and the expected
failures. `AGENT_EVAL_ENABLED` and `AGENT_EVAL_MODEL` are available for CI.

## Scenario format

Scenarios live in `tests/Agent/Scenarios/*.json`.

```json
{
  "name": "cover-up-never-quotes",
  "message": "Quero cobrir uma tatuagem antiga",
  "turn": { "is_new": true, "tattoo_type": "cover_up", "agent_state": {} },
  "facts": {
    "pricing": { "status": "ready", "base_cents": 90000, "deposit_cents": 27000 },
    "project": { "tattoo_type": "cover_up", "placement": "costas" },
    "availability": { "status": "not_ready", "slots": [] },
    "conditions": { "pix_available": true, "reschedule_hours": 48 },
    "booking": { "active": false },
    "studio": { "city": "São Paulo", "timezone": "America/Sao_Paulo" }
  },
  "script": {
    "preflight": { "intent": "ask_price", "entities": {}, "flags": {}, "confidence": 0.95 },
    "agent": "Fica R$ 900,00 à vista!",
    "claims": { "claims": [{ "type": "price", "cents": 90000 }] },
    "repair": { "reply": "", "claims": [] }
  },
  "expect": {
    "resolution": "canonical_cover_up",
    "reply_contains": ["avaliar"],
    "reply_not_contains": ["R$"],
    "violations": ["coverup_never_quotes"]
  }
}
```

- `script` is used only by `agent:simulate`. `agent:eval` ignores it and calls
  the real model; the `facts` still describe the CRM at that turn.
- `facts_by_intent` can override facts per preflight intent (e.g. availability
  only for `ask_availability`).
- `expect` supports `ok`, `resolution`, `intent`, `repaired`, `sent`,
  `max_slots`, `violations` (final), `original_violations` (pre-repair),
  `reply_contains`, `reply_not_contains` and `max_latency_ms`.

## What the cases cover today

1. Price before the size is known is rebuilt into a discovery question.
2. Cover-up price is never sent (photo/evaluation request instead).
3. No price row in the CRM defers to the artist.
4. A grounded price passes untouched.
5. An under-quoted price is replaced by the table/quote price.
6. A day outside availability is replaced by the real slots (max 2).
7. A slot offered before availability is replaced by a qualification question.
8. A deposit requested before a booking asks for the slot first.
9. An invented reschedule window is blocked.
10. A booking confirmation without a backend booking is replaced.
11. A genuinely created booking + deposit confirmation passes.
12. The portfolio link is sent exactly as configured.

Add a case whenever a production conversation exposes a new failure mode: copy
a state/facts shape, write the scripted bad answer, assert the safe final reply,
then run `agent:eval` to see what the real model does with the same facts.

## Media

Audio and reference images travel through the debounce buffer as
`[[REF_AUDIO:url]]` / `[[REF_FOTO:url]]`. `App\Services\Agent\AgentMedia`
transcribes audio, stores the image on the public disk, analyzes it with the
vision model and persists `projects.reference_analysis`. The analysis is
appended to the system prompt as supporting context; it is never a price
source.
