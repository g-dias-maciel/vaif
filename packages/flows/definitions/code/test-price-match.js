#!/usr/bin/env node
/**
 * Behavioural test for AI price matching.
 *
 * The classifier receives the artist's price table and picks the row that
 * matches the lead's request. It never returns a number: the price always comes
 * from the table. The parse validates the row:
 *
 *  - an exact deterministic match always wins;
 *  - otherwise a validated, non-low-confidence AI row is used;
 *  - an unknown row / low confidence is ignored, which hands off to the artist.
 *
 * Run: node packages/flows/definitions/code/test-price-match.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const wf = JSON.parse(fs.readFileSync(
  path.join(__dirname, '..', 'beatriz-whatsapp-agent.json'), 'utf8'));
const parseCode = wf.nodes.find((n) => n.name === 'Parse Classification').parameters.jsCode;

let failures = 0;
let passes = 0;

function check(label, cond, detail) {
  if (cond) {
    passes++;
    console.log('  PASS: ' + label);
  } else {
    failures++;
    console.log('  FAIL: ' + label + (detail ? ' -> ' + detail : ''));
  }
}

function run({ start, leadText, ai, rows, classifier, critique = null, offer = { promo_active: false } }) {
  const $ = (name) => ({
    first: () => {
      if (name === 'Start Turn') return { json: start };
      if (name === 'AI Agent') return { json: { output: ai || 'Ok!' } };
      if (name === 'Debounce Resolve') return { json: { combined_text: leadText } };
      if (name === 'Build System Prompt') return { json: { pricing_rows: rows, offer } };
      if (name === 'Resolve Artist') return { json: { id: 'a1', name: 'Gabriel', full_selling: true } };
      if (name === 'Parse Price Critique' && critique) return { json: { price_critique: critique } };
      return { json: {} };
    },
  });
  const input = { first: () => ({ json: { choices: [{ message: { content: JSON.stringify(classifier) } }] } }) };
  return new Function('$', '$input', parseCode)($, input)[0].json;
}

const DIFFERENT_SIDES = [
  { placement: 'braco_externo', body_zone: 'medio', table_price: 20000 },
  { placement: 'braco_interno', body_zone: 'medio', table_price: 15000 },
];

function lead(overrides = {}) {
  return {
    stage_key: 'qualificando',
    tattoo_type: 'new',
    placement: 'braco',
    body_zone: 'medio',
    style: 'realismo',
    first_tattoo: true,
    quoted_price_cents: null,
    agent_state: {
      processo_explicado: true,
      duvidas_eliminadas: true,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: false,
      pending_question: null,
    },
    ...overrides,
  };
}

function classifier(priceMatch) {
  return {
    pipeline: 'qualificando',
    event: null,
    qualification: {},
    pricing: {},
    handoff: { reason: null },
    price_gate: {},
    price_match: priceMatch,
  };
}

// ── 1. The table lookup is ambiguous; the AI row resolves it. ──
console.log('=== AI row resolves an ambiguous region ===');
let r = run({
  start: lead(),
  leadText: 'quero na parte de fora do braço, tamanho médio',
  rows: DIFFERENT_SIDES,
  classifier: classifier({ match: true, placement: 'braco_externo', body_zone: 'medio', confidence: 'alta', reason: 'lead disse parte de fora' }),
});
check('quotes the matched row', /R\$\s*200,00/.test(r.final_reply), r.final_reply);
check('describes the matched region', /parte externa do braço/i.test(r.final_reply), r.final_reply);
check('records the row price', r.quote_offered_cents === 20000, String(r.quote_offered_cents));

// ── 2. A row that is not in the table is ignored. ──
console.log('=== Unknown row is ignored ===');
r = run({
  start: lead(),
  leadText: 'quero no braço, tamanho médio',
  rows: DIFFERENT_SIDES,
  classifier: classifier({ match: true, placement: 'braco_externo', body_zone: 'grande', confidence: 'alta', reason: 'palpite' }),
});
check('does not quote an invented row', !/R\$\s*\d/.test(r.final_reply), r.final_reply);
check('asks which side instead of inventing', /parte de fora do braço|parte de dentro|antebraço/i.test(r.final_reply), r.final_reply);
check('no quote is recorded', r.quote_offered_cents === null, String(r.quote_offered_cents));

// ── 3. Low confidence is ignored. ──
console.log('=== Low confidence is ignored ===');
r = run({
  start: lead(),
  leadText: 'quero no braço, tamanho médio',
  rows: DIFFERENT_SIDES,
  classifier: classifier({ match: true, placement: 'braco_externo', body_zone: 'medio', confidence: 'baixa', reason: 'não tenho certeza' }),
});
check('does not quote on a low-confidence match', !/R\$\s*\d/.test(r.final_reply), r.final_reply);
check('asks for the missing detail', /parte de fora do braço|parte de dentro|antebraço/i.test(r.final_reply), r.final_reply);

// ── 4. An exact deterministic match always wins. ──
console.log('=== Exact table match beats the AI ===');
r = run({
  start: lead({ placement: 'antebraco', body_zone: 'medio' }),
  leadText: 'quero no antebraço, médio',
  rows: [
    { placement: 'antebraco', body_zone: 'medio', table_price: 70000 },
    { placement: 'braco_externo', body_zone: 'medio', table_price: 20000 },
  ],
  classifier: classifier({ match: true, placement: 'braco_externo', body_zone: 'medio', confidence: 'alta', reason: 'errado' }),
});
check('uses the exact row', /R\$\s*700,00/.test(r.final_reply), r.final_reply);
check('describes the exact region', /no antebraço/i.test(r.final_reply), r.final_reply);

// ── 5. The AI can also decline. ──
console.log('=== AI declines the match ===');
r = run({
  start: lead(),
  leadText: 'quanto custa?',
  rows: DIFFERENT_SIDES,
  classifier: classifier({ match: false, placement: null, body_zone: null, confidence: 'alta', reason: 'não disse qual lado do braço' }),
});
check('no quote without a match', !/R\$\s*\d/.test(r.final_reply), r.final_reply);
check('asks for the missing detail', /parte de fora do braço|parte de dentro|antebraço/i.test(r.final_reply), r.final_reply);

// ── 6. A specific region with no row goes to the artist. ──
console.log('=== Specific region, no row: hand off ===');
r = run({
  start: lead({ placement: 'costela', body_zone: 'grande' }),
  leadText: 'quero na costela, grande',
  rows: DIFFERENT_SIDES,
  classifier: classifier({ match: false, placement: null, body_zone: null, confidence: 'alta', reason: 'sem linha para costela' }),
});
check('no quote', !/R\$\s*\d/.test(r.final_reply), r.final_reply);
check('hands off to the artist', r.stage_key === 'aguardando_artista' && r.handoff_reason === 'no_price_row',
  JSON.stringify({ s: r.stage_key, h: r.handoff_reason }));

// ── 7. The critic can veto a match the classifier accepted. ──
console.log('=== Critic vetoes a bad match ===');
r = run({
  start: lead(),
  leadText: 'quero no braço, médio',
  rows: DIFFERENT_SIDES,
  classifier: classifier({ match: true, placement: 'braco_externo', body_zone: 'medio', confidence: 'alta', reason: 'chute' }),
  critique: { agree: false, confidence: 'alta', reason: 'o lead não disse qual lado do braço' },
});
check('no quote after the veto', !/R\$\s*\d/.test(r.final_reply), r.final_reply);
check('no quote is recorded', r.quote_offered_cents === null, String(r.quote_offered_cents));

// ── 8. The critic confirms a good match. ──
console.log('=== Critic confirms the row ===');
r = run({
  start: lead(),
  leadText: 'quero na parte de fora do braço, médio',
  rows: DIFFERENT_SIDES,
  classifier: classifier({ match: true, placement: 'braco_externo', body_zone: 'medio', confidence: 'alta', reason: 'parte de fora' }),
  critique: { agree: true, confidence: 'alta', reason: 'a mensagem diz parte de fora' },
});
check('quotes the confirmed row', /R\$\s*200,00/.test(r.final_reply), r.final_reply);

// ── 9. A low-confidence critic vetoes too. ──
console.log('=== Critic low confidence ===');
r = run({
  start: lead(),
  leadText: 'quero no braço, médio',
  rows: DIFFERENT_SIDES,
  classifier: classifier({ match: true, placement: 'braco_externo', body_zone: 'medio', confidence: 'alta', reason: 'parte de fora' }),
  critique: { agree: true, confidence: 'baixa', reason: 'talvez' },
});
check('no quote on low-confidence critique', !/R\$\s*\d/.test(r.final_reply), r.final_reply);

// ── 10. The promotion multiplier applies on top of the matched row. ──
console.log('=== Promotion + AI row ===');
r = run({
  start: lead(),
  leadText: 'quero no braço, médio',
  rows: DIFFERENT_SIDES,
  offer: { promo_active: true, name: 'Leve 2 pelo valor de 1', multiplier: 2 },
  classifier: classifier({ match: true, placement: 'braco_externo', body_zone: 'medio', confidence: 'alta', reason: 'parte de fora' }),
  critique: { agree: true, confidence: 'alta', reason: 'ok' },
});
check('doubles the matched row price', /R\$\s*400,00/.test(r.final_reply), r.final_reply);
check('does not quote the raw row price', !/R\$\s*200,00/.test(r.final_reply), r.final_reply);
check('mentions the promotion', /promo[cç][aã]o/i.test(r.final_reply), r.final_reply);
check('records base and offered', r.quote_base_cents === 20000 && r.quote_offered_cents === 40000,
  JSON.stringify({ b: r.quote_base_cents, o: r.quote_offered_cents }));
check('marks it as a promotion quote', r.offer_quoted === true, String(r.offer_quoted));

// ── 11. Same with the critic vetoing: no promo quote goes out. ──
console.log('=== Promotion + critic veto ===');
r = run({
  start: lead(),
  leadText: 'quero no braço, médio',
  rows: DIFFERENT_SIDES,
  offer: { promo_active: true, name: 'Leve 2 pelo valor de 1', multiplier: 2 },
  classifier: classifier({ match: true, placement: 'braco_externo', body_zone: 'medio', confidence: 'alta', reason: 'chute' }),
  critique: { agree: false, confidence: 'alta', reason: 'não disse o lado' },
});
check('no price at all', !/R\$\s*\d/.test(r.final_reply), r.final_reply);
check('no promotion quote recorded', r.quote_offered_cents === null, String(r.quote_offered_cents));

// ── 12. The AI-matched cell is persisted on the project. ──
console.log('=== AI row persists placement and zone ===');
r = run({
  start: lead({ placement: null, body_zone: null }),
  leadText: 'quero no antebraço',
  rows: [{ placement: 'antebraco', body_zone: 'pequeno', table_price: 20000 }],
  classifier: classifier({ match: true, placement: 'antebraco', body_zone: 'pequeno', confidence: 'alta', reason: 'lead disse antebraço' }),
  critique: { agree: true, confidence: 'alta', reason: 'ok' },
});
check('persists the matched placement', r.placement_val === 'antebraco', String(r.placement_val));
check('persists the matched zone', r.body_zone_val === 'pequeno', String(r.body_zone_val));

// ── 13. A quoted project never asks for the size again. ──
console.log('=== Already quoted: no size question ===');
r = run({
  start: lead({ placement: null, body_zone: null, stage_key: 'orcamento_enviado', quoted_price_cents: 20000, table_price_cents: 20000,
    agent_state: { processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false, preco_liberado: true } }),
  leadText: 'seria o preco mesmo',
  ai: 'Consigo fazer um desconto, fica R$ 180,00.',
  rows: DIFFERENT_SIDES,
  classifier: classifier({ match: false, placement: null, body_zone: null, confidence: 'alta', reason: 'x' }),
});
check('does not ask for the size again', !/pequena, média, grande|fechamento/i.test(r.final_reply), r.final_reply);

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
