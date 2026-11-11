# Adriano — calibration report (updated 2026-11-11)

Window: last 30 days of drafts (all traffic falls on Nov 8–11).
Mode: `draft` (no send). WAHA session `adriano-studio-madri`.

## Scoreboard (after the P0 fixes and re-judge)

| Gate | Actual | Target | Pass |
|---|---|---|---|
| Pares comparados (texto) | 12 | ≥ 40 | no |
| Rascunhos sem fato inventado | 1 unsafe (histórico) | = 0 | no |
| Handoffs perdidos | 1 | = 0 | no |
| Rascunhos aceitáveis | 83% | ≥ 90% | no |
| Drafts respondidos | 30% | ≥ 90% | no |

Totals: 279 drafts · 73 eligible · 22 answered · 22 compared by the judge · **10 media-only excluded** · 12 comparable · 51 pending · 206 superseded · 1 unsafe · 4 artist violations.

Judge (12 comparable): **7 draft_better · 2 tie · 3 artist_better** · acceptance 83% · question covered 75% · tone 3.83/5.

Warnings: 10 media-only replies outside the comparison · 3 artist prices outside the table · low coverage of `ask_availability`, `reschedule`, `aftercare`, `coverup`.

## P0 fixes landed

1. **Timestamps** — Postgres now runs in `America/Sao_Paulo`; Eloquent stops writing `created_at` three hours behind. Migration `0039` repaired the historical drafts (`created_at`, `artist_replied_at`, `response_seconds`).
2. **Pairing** — `PairArtistReply` retries when the lead is mid-burst instead of dropping the artist reply; `latestUnansweredForChat` pairs replies older than 24h. `agent:repair-pairings` repairs historical misses.
3. **Judge context** — the comparison now receives the conversation before the turn. Memory is no longer scored as invention; re-asking a known field is penalised.
4. **Media-only** — replies that are only `[MÍDIA ENVIADA]`/emoji are marked `comparable=false` and kept out of the acceptance, factual and handoff denominators. Historical rows are re-flagged by `agent:recompare`.
5. **Briefing + known fields** — the prompt receives the known fields, the single next allowed question and an explicit no-repeat rule. The validator turns `question_known_field` into `canonical_missing` instead of keeping the unsafe text.
6. **Hold/price/hours guardrails** — the validator scans for `deixei salvo`, `reservei`, `mantenho o valor`, etc., and for times outside the studio's working windows. Unknown violations now defer to the artist (`canonical_defer`) instead of sending the original text.
7. **Price book** — still a data decision. The artist quoted R$ 250 (quadril 13 cm), R$ 350 and R$ 1.500–1.800 (braço) without a matching row. The table has no `quadril`/`perna` rows and `guarantee_price=false`.

## Biggest misses that remain

1. **Discovery still stops early.** Local/tamanho/primeira tatuagem are the most frequent gaps. The briefing now asks for the next missing field, but the model still needs the lead's answer to advance.
2. **Context retention.** The re-judge surfaced drafts that restart qualification ("primeira tatuagem?") after the lead already answered. The briefing + history should reduce it; watch the next pass.
3. **Answer first.** Some drafts still qualify instead of answering the lead's last question. Prompt rule added; monitor with `covered_question`.
4. **Artist media.** Adriano answers half the turns with images. Until media is transcribed or staff-tagged, the comparable sample stays small.

## Recommendation

Keep Adriano on `draft`. Auto is still blocked: 12/40 comparable pairs, one historical unsafe draft, one missed handoff, acceptance 83% and response 30%. Next target: ~40 text-comparable turns with ≥90% acceptance, zero unsafe, zero missed handoff. Reconcile the price book before enabling `auto` with `full_selling=true`.
