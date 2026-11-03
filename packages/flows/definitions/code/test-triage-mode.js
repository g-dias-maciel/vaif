#!/usr/bin/env node
/**
 * Behavioural test for triage mode (full selling disabled).
 *
 * Production bug (Gabriel): `full_selling=false`, yet after the process
 * explanation the deterministic quote engine fired and Beatriz sent
 * "Para uma tatuagem média no braço, fica R$ 200,00...".
 *
 * With full selling off the agent qualifies and hands off; it never quotes,
 * never schedules and never asks the doubts checkpoint.
 *
 * Run: node packages/flows/definitions/code/test-triage-mode.js
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

function run({ start, leadText, ai, classifier, fullSelling = false }) {
  const $ = (name) => ({
    first: () => {
      if (name === 'Start Turn') return { json: start };
      if (name === 'AI Agent') return { json: { output: ai } };
      if (name === 'Debounce Resolve') return { json: { combined_text: leadText } };
      if (name === 'Build System Prompt') {
        return { json: { pricing_rows: [{ placement: 'braco', body_zone: 'medio', table_price: 20000 }], offer: { promo_active: false } } };
      }
      if (name === 'Resolve Artist') return { json: { id: 'a1', name: 'Gabriel', full_selling: fullSelling } };
      return { json: {} };
    },
  });
  const input = { first: () => ({ json: { choices: [{ message: { content: JSON.stringify(classifier) } }] } }) };
  return new Function('$', '$input', parseCode)($, input)[0].json;
}

function classifier(overrides = {}) {
  return {
    pipeline: 'qualificando',
    event: null,
    qualification: {},
    pricing: {},
    handoff: { reason: null },
    price_gate: {},
    ...overrides,
  };
}

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

// ── 1. Gabriel's exact turn: "n" after the process explanation. ──
console.log('=== Triage: no quote after the process ===');
let r = run({
  start: lead(),
  leadText: 'n',
  ai: 'Então, José, o processo de criação do Gabriel acontece da seguinte forma: ele vai sentar junto com você...',
  classifier: classifier({ pricing: { table_cents: 20000, nego_cents: 20000 } }),
});
check('no price is sent', !/R\$\s*\d/.test(r.final_reply), r.final_reply);
check('the lead is handed off', r.stage_key === 'aguardando_artista' && r.handoff_reason === 'triage_only',
  JSON.stringify({ s: r.stage_key, h: r.handoff_reason }));
check('the handoff mentions the artist', /passar para o Gabriel/i.test(r.final_reply), r.final_reply);
check('no quote is recorded', r.quote_offered_cents === null && r.offer_quoted === false,
  JSON.stringify({ o: r.quote_offered_cents, q: r.offer_quoted }));
check('the price stays locked', r.preco_liberado_val === false, String(r.preco_liberado_val));

// ── 2. Model quotes before the qualification is complete. ──
console.log('=== Triage: early price is replaced by the next question ===');
r = run({
  start: lead({ style: null }),
  leadText: 'quanto custa?',
  ai: 'Para uma tatuagem média no braço, fica R$ 200,00 à vista.',
  classifier: classifier(),
});
check('the price is removed', !/R\$\s*\d/.test(r.final_reply), r.final_reply);
check('the discovery continues', /estilo/i.test(r.final_reply), r.final_reply);
check('the lead is not handed off yet', r.stage_key === 'qualificando', r.stage_key);

// ── 3. Triage never asks the doubts checkpoint. ──
console.log('=== Triage: no doubts checkpoint ===');
r = run({
  start: lead({ agent_state: { ...lead().agent_state, processo_explicado: true, duvidas_eliminadas: false } }),
  leadText: 'ok',
  ai: 'Entendi!',
  classifier: classifier(),
});
check('no doubts question', !/alguma d[uú]vida\?/i.test(r.final_reply), r.final_reply);
check('the checkpoint is not armed', r.aguardando_confirmacao_duvidas_val === false, String(r.aguardando_confirmacao_duvidas_val));

// ── 4. Triage with a cover-up keeps the cover-up handoff. ──
console.log('=== Triage: cover-up keeps its own reason ===');
r = run({
  start: lead({ tattoo_type: 'cover_up', placement: null, body_zone: null, style: null, reference_analysis: [{ assunto: 'tattoo antiga' }] }),
  leadText: 'n',
  ai: 'O processo de criação do Gabriel é assim: ele vai sentar junto com você.',
  classifier: classifier({ handoff: { reason: 'cover_up' } }),
});
check('hands off as a cover-up', r.handoff_reason === 'cover_up', String(r.handoff_reason));

// ── 5. Full selling still quotes. ──
console.log('=== Full selling unaffected ===');
r = run({
  start: lead(),
  leadText: 'n',
  ai: 'Ok!',
  classifier: classifier({ pricing: { table_cents: 20000, nego_cents: 20000 } }),
  fullSelling: true,
});
check('quotes the table price', /R\$\s*200,00/.test(r.final_reply), r.final_reply);
check('goes to orcamento_enviado', r.stage_key === 'orcamento_enviado', r.stage_key);

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
