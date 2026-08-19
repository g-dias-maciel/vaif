# 21 — Testing harness + eval set

Labels: implementation

## What to build

Automated regression testing for Beatriz's behavior. A test harness that injects synthetic message sequences from the 10 conversation fixtures at the n8n webhook entry point, captures Beatriz's reply text, and asserts correct behavior against the conversation contract. Tests verify: phase progression, pipeline state transitions in Postgres, handoff trigger behavior, tone rule compliance, and database state after each conversation.

## Acceptance criteria

- [ ] Test harness sends fixture message sequences to the n8n webhook endpoint
- [ ] Test 1: Ideal flow — all 7 phases in order, lead closes
- [ ] Test 2: Negotiation — Beatriz counters with contrapartida within floor
- [ ] Test 3: Below-floor — immediate handoff, no negotiation loop
- [ ] Test 4: Cover-up — handoff on keyword, no qualification or pricing
- [ ] Test 5: Vague lead — two attempts, then handoff
- [ ] Test 6: Audio — first gets text request, second triggers handoff
- [ ] Test 7: Style mismatch — graceful decline with conversion, no handoff
- [ ] Test 8: Off-hours — closed-studio message
- [ ] Test 9: Abuse/troll — single reply then silence
- [ ] Test 10: Artist direct request — handoff with explanation
- [ ] Assertions check Postgres state after each test: pipeline_status, qualification fields, handoff_reason
- [ ] Assertions check forbidden words never appear in replies
- [ ] Assertions check price is never presented before doubt-clearing
- [ ] Assertions check Beatriz stops talking after "Como fica para você?"
- [ ] All 10 tests pass against the same workflow used in production

## Blocked by

- #4 — Pricing + booking engine
- #5 — Handoff + negotiation
