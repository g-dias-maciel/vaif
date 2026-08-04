# Agent runtime stack

Type: grilling
Status: resolved
Blocked by: none

## Question

Lock the runtime: confirm n8n as the agent host (vs a code service), which LLM/provider and why, the memory strategy per Lead (session vs long-term), how workflows get versioned and tested (exported JSON in the monorepo, the Telegram testbed pattern, an eval set of conversations), and cost ceilings. Output: the runtime decision the prototype and the spec build on.

## Answer

5/5 decisions locked. Full document: [design/runtime-stack.md](../design/runtime-stack.md).

1. **n8n end-to-end** — Beatriz runs in n8n workflows only. WAHA webhooks in, LLM nodes, branching, database ops, WAHA HTTP out. Workflows exported as JSON to `packages/flows/`.
2. **GPT-4o-mini via OpenRouter** — already configured on the n8n instance. Handles pt-BR natively. Function calling (`lookup_price`, `check_availability`, `write_lead_card`). Fallback to Claude Haiku/Gemini Flash via OpenRouter routing.
3. **Postgres Chat Memory** — n8n native node. Per-Lead session keyed by `lead_id`. Auto-manages window trimming. Long-term profile in CRM (Postgres).
4. **Versioning + testing** — Manual JSON export to `packages/flows/` + git commit. Telegram testbed for human-feel QA. Eval set: 10–15 conversation fixture JSONs + pipeline/field assertions, lives in `packages/flows/test/`. System prompt checklist as backstop safety net.
5. **Cost ceilings** — ~$0.84/artist/month at GPT-4o-mini. ~$25/month at 30 artists. $100/month hard cap on OpenRouter key as airbag. No per-artist budgets yet (v2).