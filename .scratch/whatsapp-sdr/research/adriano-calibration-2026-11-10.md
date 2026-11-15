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

## Real-failure regression suite (2026-11-12)

Before this, the only test chats were 13 synthetic scenarios (`tests/Agent/Scenarios`) and the scripted/real-model evaluators. There was no suite built from Adriano's real failures.

Added:

- `php artisan agent:export-regression adriano-studio-madri --days=7 --limit=10`
  Writes replayable scenarios from real failed drafts (fairness: `acceptable=false`, `artist_better`, or `missing_handoff`). Files go to `storage/app/agent-regressions` by default because they contain customer text.
- `php artisan agent:eval --dir=storage/app/agent-regressions`
  Runs each scenario through the real preflight, prompt, validator and repair, then **judges the new reply against the artist's real answer** (including the audio transcript).
- `DraftComparator::review()` — non-persisting judge used by the replay.

First baseline (8 latest failures): **0/8 pass**. The failures cluster on:

- cover-up / after-care replies that still do not hand off;
- asking for the current-tattoo photo again after the lead already sent it;
- the judge preferring Adriano's natural audio answer even when the SDR follows the methodology.

The replay is faithful for new cases (drafts now store `stage_key`, `agent_state`, `assumptions`, `flags` and `deposit_status`). Old cases use the current `chat_sessions.agent_state` as a best-effort reconstruction, so photo/price-gate state can be incomplete.

## Regression suite result (2026-11-12)

Pinned suite: 8 real failures (draft IDs `a3339b8c`, `a3339c71`, `a333a045`, `a333a25a`, `a333ae04`, `a333afb3`, `a333b875`, `a333f085`).

**8/8 pass** on the methodology requirements:

- `safe_resolution` — no unsafe text; canonical or accepted only.
- `handoff` — explicit handoff when the case requires the artist.
- `video` — video with 3–4s pauses when photos were already sent.
- `laser` — answers the cover-vs-remove question with general facts.
- `no_known_question` — no repeated known field.
- `no_price` — no cover-up price.

The LLM judge is still reported as a quality signal, not a hard gate. It currently gives 5 `artist_better`, 1 `tie`, 1 `draft_better`, and 2 `acceptable=false`; those are mostly cases where the artist's private audio contains operational details (exact photo list, promo rules) or a more natural tone. Gating on that would make the suite chase the artist's private information instead of the SDR methodology.

Fixes that made the suite pass:

- cover-up canonical: photos/video + artist name + explicit handoff;
- photo re-ask and video-after-photos guards;
- cover-up refusal: acknowledge the refusal, no re-offer, no known-field repeats, visit to the studio;
- cover-or-remove: general laser facts + handoff;
- promo + cover-up: combined answer, price stays with the artist;
- busy/paused lead: no qualification question, answer the request, propose the next step;
- assumptions: never asserted as fact, confirmation instead.

## Duplicate media transcripts (2026-11-13)

The Mhrcardoso conversation showed the same audio transcript more than once. Cause:

- the CRM writes artist voice-note transcripts into `agent_memory` (inline);
- the calibration describer also saves the same transcript in `artist_media_note`;
- the timeline attached a draft's note to **every** media bubble within 10 minutes, so the same transcript appeared on several bubbles (and on a later photo).

Fix:

- `agent_drafts.artist_media_ids` stores the exact WhatsApp media ids a note describes;
- the timeline attaches a note only to the matching bubble, once;
- `MediaNote` removes duplicate segments within a note and segments already shown in `chat_memory`;
- the describer only looks at the first two minutes of the artist burst;
- historical notes got their media ids backfilled (85 rows) and Mhrcardoso was re-described.

Regeneration: `agent:mock-conversation --regenerate` / `agent:mock-all --regenerate` now creates/updates simulated Beatriz drafts for every customer message with the current prompt, including messages that only had a live draft. The calibration page has a `?simulation=1` toggle that shows the simulated suggestions as primary and keeps the live conversation available.

## Staff reviews (2026-11-14)

Each SDR suggestion in **Calibração** now has a **“Revisar resposta”** form:

- verdict: `boa resposta` or `deveria ser diferente`;
- category: qualification, handoff, price, policy, context, tone, media, other;
- note: what was wrong;
- corrected reply: what Beatriz should have said.

Reviews are one per draft (saving replaces). The page shows a **Revisões da equipe** panel with the reviewed cases, the corrected replies, the reviewer and a link to the conversation. A count card shows total reviews and how many are marked to fix.

The weekly Copilot calibration facts now include `reviews`: total, `needs_fix`, top categories and up to five samples with the note and corrected reply. The analyst prompt treats them as priority evidence and suggests turning them into eval cases.

Reviews do not change the gates or send anything; they are staff input for calibration.

## Staff reviews incorporated (2026-11-15)

Five reviews were taken in: two `context`, two `handoff`, one `good`.

Changes:

- **Context implies a new tattoo** — “do mesmo lado”, “ao lado”, “mais uma” now create a `tattoo_type=nova` assumption and the briefing asks a closed confirmation. If the model asks the open “nova, cobertura ou reforma?” anyway, the validator replaces it with “Essa tatuagem seria uma tatuagem nova, certo?”. Verified on Eliza’s “ramo de flor do mesmo lado”.
- **Cover-up with photo already sent** — the photo is enough: recognize it and hand off. The video requirement was removed from the prompt and validator, and from the regression requirements. Verified on Grazi’s “Deixar com qualidade”.
- **Photo role from the chat** — every incoming image is now classified as `tatuagem_atual`, `referencia` or `outra` using the recent conversation as context. Only a `tatuagem_atual` image sets `cover_photo_received`; a reference image (someone else’s tattoo, flash, drawing) does not. Historical analyses are kept, so a later reference image never erases the current-tattoo evidence. Rows analyzed before this change fall back to the old `lead_photos` + cover-up rule.
- **Personal / abusive chats** — the new `personal` intent is not answered. After a first wrong-number close, a second spam message is also ignored. The real preflight classified the reviewed “Fala vagabundo…” message as `personal`; the agent skips it.
- **All handoffs stop the agent** — after any handoff phrase (“vou passar para o Adriano”, “vou encaminhar”, “ele vai entrar em contato”…), later turns are skipped, including in simulated drafts. Scenario `16-handoff-stops-followup` covers it.
- **Review status** — reviews have an `incorporated_at` field and an “Incorporada na calibração” checkbox. The five existing reviews were marked incorporated.
- **Regression** — new scenarios `14-personal-skip`, `15-spam-after-close` and `16-handoff-stops-followup`; the pinned 8 real-failure suite still passes 8/8 with the photo-only cover-up policy.

## End-to-end conversations (2026-11-15)

`php artisan agent:e2e --dir=tests/Agent/E2E` runs fake multi-turn WhatsApp
conversations through the real Postgres RPCs and the real Beatriz model. The
scenario is created inside a transaction that is always rolled back, so no
synthetic artist, lead or message stays in the CRM. Only WAHA is replaced by an
in-memory transport, and photo role can be supplied by the fixture.

The first suite covers six edge cases: new tattoo inferred from context,
cover-up photo with handoff and silence, a reference photo that is not the
current tattoo, a personal message followed by a real request, spam close
followed by silence, and a qualified lead receiving the exact price-book value.

The first run found and fixed two real gaps:

- A generic “quero fazer uma tatuagem” was treated as a new-tattoo assumption
  because of the loose `fazer uma` pattern. The pattern now requires “do mesmo
  lado / ao lado / mais uma / outra tattoo”.
- A high-confidence new-tattoo assumption could be skipped: the agent asked
  placement or size without confirming the type. The validator now requires the
  closed confirmation before another discovery question.

It also exposed that fields supplied in the current turn (meaning, first tattoo)
were missing from `facts.project`, so the agent could re-ask them. `TurnFacts`
now merges current entities into the turn facts before the prompt and validator.

`agent:e2e` is manual because it spends real model calls and uses the production
database. The fixture shape is documented in `tests/Agent/E2E/README.md`; a PHPUnit
test validates the JSON without calling the model.

## Recommendation

Keep Adriano on `draft`. Auto is still blocked: 12/40 comparable pairs, one historical unsafe draft, one missed handoff, acceptance 83% and response 30%. Next target: ~40 text-comparable turns with ≥90% acceptance, zero unsafe, zero missed handoff. Reconcile the price book before enabling `auto` with `full_selling=true`.
