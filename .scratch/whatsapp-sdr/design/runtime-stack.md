# Agent Runtime Stack

Resolved from grilling ticket 06, 2026-07-28. The foundation for [Prototype: SDR persona on the Telegram testbed](../issues/10-prototype-sdr-telegram.md).

## 1. Agent host: n8n end-to-end

Beatriz runs entirely inside n8n workflows — no custom code service. The conversation contract's 7 phases (greeting → discovery → value → process → doubts → pricing → close/handoff) map to n8n webhook triggers, LLM nodes, branching (Switch/Merge), database operations, and webhook callbacks to WAHA.

Rationale:
- Telegram testbed already runs in n8n — same runtime, no new deployment surface.
- n8n provides native nodes for everything Beatriz needs: HTTP request (WAHA), Postgres/Supabase (CRM), Postgres Chat Memory, OpenRouter HTTP, webhook I/O.
- Solo-builder overhead of a separate code service (deploy, monitor, dev loop) isn't justified at VAIF's scale (6→tens of artists).
- If n8n becomes a bottleneck later (scaling or complex branching beyond what workflow JSON comfortably captures), extracting the agent loop into a service behind an HTTP node is a natural incremental step.

Workflow topology:

```
WAHA webhook (incoming message)
  → Load session/Lead context (Postgres)
  → LLM node (GPT-4o-mini via OpenRouter)
    - system prompt: conversation contract + current pipeline phase + Lead profile
    - context: Postgres Chat Memory window
    - function calls: lookup price, check availability, write lead card
  → Branch on pipeline state
  → Execute side effects (CRM write, booking, handoff notification)
  → HTTP request to WAHA (send reply)
```

n8n workflows live in `packages/flows/` as exported JSON files.

## 2. LLM: GPT-4o-mini via OpenRouter

- **Model**: GPT-4o-mini (OpenAI) via OpenRouter API.
- **Gateway**: OpenRouter — already configured on the n8n instance. Single API key, provider-agnostic. Fallback to Claude Haiku or Gemini Flash if OpenAI is unreachable (configured in OpenRouter's model routing).
- **pt-BR**: GPT-4o-mini handles Brazilian Portuguese natively. System prompt specifies the language and tone (warm, natural, confident — per the conversation contract).
- **Function calling**: OpenAI's tool-use format. Beatriz calls: `lookup_price(placement, body_zone)` → returns table price + floor, `check_availability(date_range, duration)` → returns available slots, `write_lead_card(fields)` → persists to CRM.
- **Latency**: GPT-4o-mini is fast enough for sub-60-second replies at typical conversation throughput. OpenRouter routes to the lowest-latency provider for the model.

## 3. Memory: Postgres Chat Memory (n8n native)

n8n's built-in `Postgres Chat Memory` node persists conversation sessions per Lead. Each WhatsApp session maps to one memory session keyed by `lead_id` (phone number or CRM record UUID).

| Layer | Content | Storage | Duration |
|---|---|---|---|
| Session (workflow) | Current pipeline phase, partial qualification fields | n8n workflow data (in-memory, per execution) | One message cycle |
| Conversation (chat history) | Full transcript, what was asked/answered, image references | Postgres Chat Memory node | Days to weeks (the conversation lifecycle) |
| Long-term (Lead profile) | Name, phone, placement, body-zone, style, first-tattoo, booked slot, price, deposit status, pipeline state | CRM (Postgres — schema from ticket 08) | Indefinite |

The Postgres Chat Memory node auto-manages context window trimming — the LLM receives the last N messages + a summary of earlier conversation facts. This keeps token costs flat regardless of conversation length.

## 4. Versioning and testing

### Versioning

- Workflows exported as JSON from n8n to `packages/flows/<name>.json`.
- Committed to git after each change.
- Manual export for now — n8n's built-in Git sync is deferred. At the current scale (handful of workflows, solo builder), the manual step is negligible.

### Testing

Two layers, per the conversation contract and the Telegram testbed:

- **Telegram testbed** (human-feel QA): run fake-Lead conversations on the Telegram bot. Inspect Beatriz's responses for tone, adherence to the checklist, and natural Portuguese. Already exists. Primary test surface for the prototype phase (ticket 10).

- **Eval set** (automated regression): a curated folder of 10–15 conversation transcript fixtures + assertion rules. Each fixture is a JSON file: `{ transcript: [...messages], expected_state: { pipeline: "fechado", placement: "braço", price: 1200 } }`. Run the workflow's LLM nodes against the eval set with mocked WAHA/CRM outputs. Assertions check: pipeline progression, field extraction, handoff triggers, forbidden questions (never re-ask answered questions, never price before doubt-clearing). Lives in `packages/flows/test/`.

### Conversation contract enforcement (prompt-level)

The system prompt embeds a checklist — a compressed version of the conversation contract — as a "backstop safety net": a fixed paragraph at the end of every prompt, never overridden by the conversation. It's the final authority on:
- Never present price before doubt-clearing is done.
- Never ask a question already answered.
- Never say "talvez", "depende", "pode ser", "quem sabe", "tanto faz".
- Stop talking after "Como fica para você?" — wait for response.
- Instagram-post contrapartida when negotiating.

## 5. Cost ceilings

| Scope | Estimate | Cap |
|---|---|---|
| Per artist/month (8 msgs/day, 40-msg conversations) | ~$0.84 | — |
| 30 artists | ~$25/month | — |
| 100 artists | ~$84/month | — |
| **OpenRouter hard limit (airbag)** | — | **$100/month** |

The $100/month hard cap on the OpenRouter API key is an airbag, not a budget limit. At current scale (6 artists), actual spend is under $6/month. If the cap triggers, it's catching a runaway loop or spam flood, not normal usage. Per-artist budgets are a v2 feature (configurable per artist in the CRM).

OpenRouter's built-in usage limits (daily/monthly caps, hard or soft) configure this without external monitoring.
