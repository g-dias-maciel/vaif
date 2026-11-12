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

## P1 fixes landed

1. **Short replies** — the prompt now caps a reply at two short sentences and one question, and forbids the process essay outside phase 4.
2. **Cover-up/reform** — re-asking type is blocked; the canonical reply asks for the current-tattoo photo, and the prompt forbids local/size/style discovery.
3. **Promotions** — the preflight flags `mentions_promotion`; without an active promotion the reply must say it will confirm, and the validator replaces one that ignores the offer. The facts block also says the promotion is missing.
4. **Spam/personal** — the prompt closes the thread without qualification; the validator blocks `spam_qualification` and returns a one-line close.
5. **Artist media** — a staff-only field on each media-only draft lets the team describe what the artist sent. The description enters the judge comparison, the media is no longer counted as uncomparable, and the lead timeline shows the note under the artist bubble.
6. **Context assumptions** — the preflight now returns `assumptions` with value, confidence and reason. When the next missing field has a confident assumption (e.g. “na mão” → nova, 86%), the briefing gives a closed confirmation (“Essa tatuagem seria uma tatuagem nova, certo?”) instead of the open question. Low-confidence assumptions keep the open question. Assumptions are never written to the project until the lead confirms.

## Biggest misses that remain

1. **Discovery still stops early.** Local/tamanho/primeira tatuagem are the most frequent gaps. The briefing now asks for the next missing field, but the model still needs the lead's answer to advance.
2. **Context retention.** The re-judge surfaced drafts that restart qualification ("primeira tatuagem?") after the lead already answered. The briefing + history should reduce it; watch the next pass.
3. **Answer first.** Some drafts still qualify instead of answering the lead's last question. Prompt rule added; monitor with `covered_question`.
4. **Artist media.** Adriano answers half the turns with images. Until media is transcribed or staff-tagged, the comparable sample stays small.

## Review of 2026-11-11 (last 24h)

Traffic: 189 live drafts, 126 judged pairs, **77 media-only** and 49 text-comparable. Verdicts: 31 `draft_better`, 11 `artist_better`, 7 `tie`. Zero unsafe drafts, 6 missed handoffs, zero pairable pending, no queue errors.

Breaking points found and fixed:

1. **Assumptions bypassed** — the classifier wrote inferred values into `entities` (e.g. “na mão” → `tattoo_type=nova`), so the project treated them as facts and never asked a confirmation. `EntityEvidence` now demotes unsupported entities to assumptions using only the lead's words (negation-aware, assistant questions excluded).
2. **False cover-up flag** — “não quero cobrir” was read as a cover-up. The deterministic, negation-aware check now overrides the model.
3. **Manual booking contradiction** — the artist said “Fica agendado para sexta às 10h” in chat, but the CRM had no booking, so the SDR denied the reservation. `booking_in_chat` now forces “vou confirmar com o artista”, never a denial.
4. **Aftercare without handoff** — infection/post-tattoo cases advised a doctor but did not hand off. `aftercare_without_handoff` canonical now adds the handoff.
5. **Cover-up handoff missed** — added a deterministic `mentions_coverup` hint and allowed asking the current tattoo's local/size (not the new tattoo).
6. **Name re-asked** — added `nome_confiavel`, emoji cleaning and a business-name blocklist.

Still open:

- **77 media-only pairs**: tagging is the biggest lever for the calibration sample. One-by-one is too slow; needs a bulk view or automatic media summary.
- **Manual bookings**: the CRM must reconcile “fica agendado” messages into a booking (or a pending-booking queue).
- **Price book**: three artist quotes still have no row.
- **Policy vs artist**: Adriano sends PIX before a CRM booking; the SDR follows the stricter gate and is scored `artist_better` for it. Decide which behaviour is the target.

## 2026-11-12 — bulk media description changed the scoreboard

`agent:describe-media` now asks WAHA for media URLs (`downloadMedia=true`), transcribes the artist's voice notes, describes images with the vision model, and re-runs the judge. The hourly schedule runs it on the `mock` queue.

First run: **54 media turns described** (voice notes and images), 28 remain media-only because WAHA has no URL/history for them.

The honest numbers after including the artist's audio:

| Metric | Before media | After media |
|---|---|---|
| Pares comparados | 12 | **113** |
| Aceitáveis | 83% | **51%** |
| draft_better | 7 | **43** |
| artist_better | 3 | **58** |
| Handoffs perdidos | 1 | **32** |
| Preço do artista fora da tabela | 3 | **13** |

The previous 83% was inflated by excluding every media turn. Adriano's voice notes contain the qualification, price, session plan and objection handling that the SDR was being credited for not needing. The real gaps are now visible:

- cover-up and after-care cases still miss the handoff;
- the SDR repeats known questions (type, size) and ignores the lead's specific question;
- current-tattoo size/local is often not asked;
- price/condition handling diverges from the artist (13 artist quotes outside the price book).

## PIX policy update (2026-11-12)

Adriano confirmed the correct order: **slot is pre-reserved, PIX is requested, appointment is confirmed only after payment.** The prompt, facts block, validator and judge now enforce:

- after the lead chooses a slot: "pré-reservado", ask for the sinal/PIX in the same message;
- never say "confirmado/agendado/fechado" before the deposit lands;
- `booking_confirmed_before_deposit` canonical reply if the model confirms early;
- the artist's own reply is validated with the same rule, so a manual "fica agendado" before PIX is flagged.

## Recommendation

Keep Adriano on `draft`. Auto is still blocked: 12/40 comparable pairs, one historical unsafe draft, one missed handoff, acceptance 83% and response 30%. Next target: ~40 text-comparable turns with ≥90% acceptance, zero unsafe, zero missed handoff. Reconcile the price book before enabling `auto` with `full_selling=true`.
