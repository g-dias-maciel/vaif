# SDR conversation design

Type: grilling
Status: resolved
Blocked by: none

## Question

Define what the SDR agent does end-to-end, in the Artist's voice, in Brazilian Portuguese: qualification criteria (style, placement, size, budget, availability), how it handles price questions, what "Closed" means mechanically (booked slot? deposit paid? Artist confirms manually?), when and how it hands off to the human Artist, and the fallback when it's unsure. Output: the conversation contract that "Agent↔CRM write contract" and "Prototype: SDR persona on the Telegram testbed" build on.

## Answer

Full conversation design contract (12 decisions locked): [Conversation design contract](../design/conversation-contract.md).

Gist: Beatriz, a named assistant persona, follows the artist's closing checklist mapped to 5 phases (discovery → value-building → process → doubt-clearing → pricing) and 3 bookend phases (greeting → close → handoff). She qualifies across placement (14 zones + per-artist "não faço" list), body-zone (4 buckets, inferred — no cm), style, reference pictures, and availability. Pricing uses a placement × body-zone × session-duration lookup table with dynamic price creep. Booking is autonomous (calendar slot proposed + confirmed). Deposit requested via PIX. Negotiation floor = per-artist % with Instagram-post contrapartida; below-floor → immediate handoff. Eight handoff triggers defined (cover-up, below-floor, returning-client, artist-requested, audio-retry, unclear-after-2, off-hours reply, abuse-farewell). Fallback: best-guess restatement with explicit "é isso mesmo?" confirmation. v1 uses text-context only for image inference; multimodal deferred. Full lead-card schema (20 fields + pipeline states) bridges to "Agent↔CRM write contract" (08) — now unblocked.
