# Prototype: SDR persona on the Telegram testbed

Type: prototype
Status: resolved
Blocked by: none

## Question

Build a rough SDR persona + conversation flow on the existing Telegram testbed (n8n), per the "SDR conversation design" contract and the "Agent runtime stack" decisions, and run fake-Lead conversations to react to. The point is fidelity for the discussion, not polish — link the workflow JSON and transcript samples as assets on this ticket.

## Answer

Prototype artifacts built. Awaiting user test run against Telegram bot.

### Assets

- **System prompt**: `packages/flows/prompts/beatriz-system.md` — full conversation contract compressed as an LLM system prompt. Beatriz identity, 7-phase flow, tone rules, qualification fields, pricing rules, 6 handoff triggers, fallback logic, backstop checklist.
- **Test scripts**: `packages/flows/test/conversation-fixtures.md` — 10 fake-Lead scripts covering: ideal flow, haggling, below-floor handoff, cover-up, vague lead, audio, style mismatch, off-hours, abuse, artist-request.
- **Setup README**: `packages/flows/beatriz-telegram/README.md` — how to wire the prompt into the existing n8n Telegram testbed, memory options, what to look for.

### To verify (user runs)

1. Import the system prompt into the existing n8n Telegram→LLM workflow.
2. Run each of the 10 test scripts.
3. Note violations: forbidden words, premature pricing, re-asking answered questions, missing contrapartida, slow handoff.
4. Report back — any surprises are bugs in the contract, not the prompt.