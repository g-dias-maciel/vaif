# Beatriz conversation tests

Automated tests for the WhatsApp SDR agent (Beatriz). Two layers:

| Layer | Network | What it verifies |
|---|---|---|
| **Deterministic** (default) | none | The deployed workflow's inline JS: price gate, doubt-loop enforcement, negotiation, handoff, debounce/typing, humanized delay, AI window. |
| **Live model eval** (opt-in) | real model API | Whether the model itself follows the conversation contract, using the real prompts and mocked tools. |

Both exercise the **actual generated workflow** (`definitions/beatriz-whatsapp-agent.json`),
not a re-implementation, so a regression in the shipped logic fails the tests.

## Run

```bash
# deterministic suite (offline) — units + multi-turn scenarios
node packages/flows/tests/conversation/run.js

# list scenarios
node packages/flows/tests/conversation/run.js --list

# run one scenario (substring match)
node packages/flows/tests/conversation/run.js --scenario negotiation

# live model evaluation (needs an API key)
BEATRIZ_EVAL_API_KEY=sk-... node packages/flows/tests/conversation/run.js --live
BEATRIZ_EVAL_API_KEY=sk-... node packages/flows/tests/conversation/run.js --live --runs 3

# show the live config without making calls
node packages/flows/tests/conversation/run.js --dry
```

Exit code is non-zero on failure, so it works in CI.

## Layout

```
tests/conversation/
  run.js            # CLI runner
  scenarios.json    # multi-turn conversation scenarios
  lib/
    workflow.js     # loads the workflow, evaluates its Code nodes with mocked $ / $input
    engine.js       # multi-turn simulator + scenario expectations + discount floor
    invariants.js   # global safety rules checked on every turn
    units.js        # debounce / humanize / AI-window unit checks
    notifications.js# handoff + signal (deposit) Telegram notifications
    live-model.js   # real-model driver with mocked tools (opt-in)
```

## What gets checked offline

- **Workflow units:** typing-aware debounce decisions, humanized delay bounds,
  AI-window/own-message filtering.
- **Notifications:** the handoff alert and the **signal (deposit) request** text
  and routing to the artist's Telegram group, the fallback detection, and the
  skip when the artist has no group configured.
- **Conversation scenarios:** intro, price gate, doubt loop, negotiation,
  generic placement, cover-up/below-floor handoff, premature deposit, booking
  flow, discount floor, signal objections.
- **Discount floor:** a scenario with `floor_pct` fails if a persisted
  negotiated price drops below the floor (e.g. 80% of the table price).

## Global invariants

Checked on **every** turn of **every** scenario:

1. **No price before the gate is open.**
2. **Doubt loop stays open pre-price:** while the process is explained and the
   price gate is closed, the final reply must contain “Mais alguma dúvida?”.
3. **Never re-ask the doubts question after the gate opens.**
4. **Rewritten replies drop the blocked price content.**

Scenario files add case-specific expectations (`finalReplyIncludes`,
`finalReplyExcludes`, `finalReplyMatches`, `gateViolation`, `handoff`, `state`).

## Scenario schema

```jsonc
{
  "name": "negotiation-no-reask",
  "description": "…",
  "artist": { "nome": "Bruno" },
  "initial": { "pipeline_status": "orcamento_enviado", "processo_explicado": true, "preco_liberado": true },
  "turns": [
    {
      "lead": "achei caro",
      "model": "assistant text (mock mode only)",
      "classify": { "pipeline": "orcamento_enviado", "pricing": { "nego_cents": 120000 } },
      "expect": {
        "finalReplyIncludes": ["R$ 1.200"],
        "finalReplyExcludes": ["Mais alguma dúvida"],
        "state": { "preco_liberado": true }
      }
    }
  ]
}
```

State is folded forward each turn (mirroring what `Update Lead` persists), so
later turns see `pipeline_status`, `processo_explicado`, `preco_liberado`,
prices, deposit and booking fields.

## Live model evaluation

Env vars (OpenAI-compatible endpoint):

| Var | Default |
|---|---|
| `BEATRIZ_EVAL_API_KEY` (or `OPENROUTER_API_KEY` / `OPENAI_API_KEY`) | — |
| `BEATRIZ_EVAL_BASE_URL` | `https://openrouter.ai/api/v1` |
| `BEATRIZ_EVAL_MODEL` | `openai/gpt-4o-mini` |
| `BEATRIZ_EVAL_RUNS` | `1` |

The live driver renders the real system prompt (`prompts/beatriz-system.md`)
and classifier prompt (`prompts/classify-conversation.md`), runs the agent with
**mocked tools** (`check_availability`, `book_slot`, `write_quote`,
`request_deposit`), feeds the reply through the real classifier and the real
price-gate, then checks the invariants and expectations.

**Model parity:** unless `BEATRIZ_EVAL_MODEL` is set, the eval uses the exact
model configured in the deployed workflow (`OpenRouter Chat Model` node), so it
can't silently diverge from production. `--dry` prints the parity line.

Because model output varies, treat a single run as a signal, not a guarantee.
For critical paths use `--runs N` and read the pass rate. This layer costs
tokens; run it nightly or before releases, not on every commit.

## Adding a case

1. Add a scenario to `scenarios.json` (mock reply + classifier + expectations),
   or extend an existing one.
2. Add unit checks to `lib/units.js` for deterministic node behavior.
3. Run `node packages/flows/tests/conversation/run.js`.

## Non-goals / safety

- Deterministic tests never touch the network, DB, n8n or WAHA.
- Live tests use mocked tools — no real booking, lead, or message is created.
- These tests do not prove every possible conversation correct; they cover the
  documented scenarios and enforce the safety invariants.

## Relationship to other tests

- `tests/test_system_prompt.py` — prompt rendering + workflow wiring (structural).
- `definitions/code/test-price-gate.js` — focused single-turn gate truth table.
- `tests/test_harness.py` — database CRUD functions (requires the DB).
- `tests/conversation/` — this suite: multi-turn behavior + model evals.
- `tests/integration/` — real n8n + DB + `/agenda` page (opt-in, mutates the
  test artist and cleans up).
