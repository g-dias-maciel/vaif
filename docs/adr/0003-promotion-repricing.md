# ADR 0003 — Promotions are a repricing lever, not a free second tattoo

- Status: accepted
- Date: 2026-11-04

## Context

The "Leve 2 pelo valor de 1" promotion is a **marketing hook used to reposition
the artist's price**, not a real two-for-one discount:

- the quoted price is `table price × multiplier` (usually `2`);
- the customer is told they get a second tattoo **of the same size, free**;
- the second tattoo has **no price** and is agreed directly with the artist;
- the agent never schedules, prices or conditions the second tattoo.

This was misread twice: as a bug ("the price doubled") and as a config error
("the multiplier should be 1"). Both times the correction was to *not* change
the engine.

## Decision

1. The multiplier is a **price multiplier**. `2` doubles the table price. It is
   not the number of tattoos and it never discounts.
2. The promotion's second tattoo is **same size, free**, and exists only in the
   sales copy. No price, no cap, no scheduling by the agent.
3. `quotes.second_cap_cents` is left `null` for promotion quotes (0015). The
   agent's context and the prompt carry no value for the second tattoo.
4. An existing open quote always wins (price protection): the agent never raises
   a price already sent, even if the promotion changed.

## Consequences

- The agent can say "fazendo uma, você ganha outra do mesmo tamanho, grátis" and
  must hand every second-tattoo detail to the artist.
- Tests assert the copy and the absence of any second-tattoo price, cap or
  schedule.
- The promotions form explains the multiplier, so future promotions are not
  "fixed" into a discount.
