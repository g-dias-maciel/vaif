# WhatsApp transport decision

Type: grilling
Status: resolved
Blocked by: 03

## Question

Decide the Transport for v1 and the direction for scale: stay on WAHA per-Artist sessions, move to Meta's official Cloud API, or hybrid (WAHA now, official as Artists scale). Weigh the "Research: WhatsApp transport options" findings against ban-risk tolerance, cost per Artist, and the 6-now/tens-in-6-months trajectory. The answer names the Transport and what would trigger a switch.

## Answer

3/3 decisions locked. Full document: [design/transport-decision.md](../design/transport-decision.md).

1. **V1: WAHA (GOWS engine)** — already running, $0, reply-only inbound minimizes ban surface. Cloud API onboarding overhead isn't justified at 6 artists with a solo builder.
2. **Number architecture: business numbers only** — no personal WhatsApp numbers get paired to WAHA. Artists without a dedicated business line must get a second number before going live (VAIF helps, doesn't pay).
3. **Switch trigger + Cloud API bridge** — switch fires at first account-level action (ban/suspension/CAPTCHA) on any artist number, **or 30 artists**, whichever first. Cloud API bridge starts at **10 artists** (Business verification, App Review, Embedded Signup v4) to absorb Meta's weeks-to-months process lead time. Migration path for existing artists: coexistence onboarding for Business-app numbers, standard Embedded Signup for new dedicated numbers.