#!/usr/bin/env node
/**
 * Typo resilience.
 *
 * The keyword lists in the parse still exist, but the classifier reads the
 * message first. Each field below has a typo the list does not match; the
 * classifier's reading must resolve it.
 *
 * Run: node packages/flows/definitions/code/test-typos.js
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

function run({ start, leadText, ai = 'Ok!', classifier, rows = [] }) {
  const $ = (name) => ({
    isExecuted: undefined,
    first: () => {
      if (name === 'Start Turn') return { json: start };
      if (name === 'AI Agent') return { json: { output: ai } };
      if (name === 'Debounce Resolve') return { json: { combined_text: leadText } };
      if (name === 'Build System Prompt') return { json: { pricing_rows: rows, offer: { promo_active: false } } };
      if (name === 'Resolve Artist') return { json: { id: 'a1', name: 'Gabriel', full_selling: true, floor_pct: 80, instagram_handle: '@x' } };
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
    placement: null,
    body_zone: null,
    style: null,
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

// ── Placement ──
console.log('=== placement typo ===');
let r = run({
  start: lead(),
  leadText: 'quero no antebrasso',
  classifier: classifier({ qualification: { placement: 'antebraco' } }),
});
check('resolves the placement', r.placement_val === 'antebraco', String(r.placement_val));

// ── Zone ──
console.log('=== zone typo ===');
r = run({
  start: lead({ placement: 'antebraco' }),
  leadText: 'quero fexamento',
  classifier: classifier({ qualification: { body_zone: 'fechamento' } }),
});
check('resolves the zone', r.body_zone_val === 'fechamento', String(r.body_zone_val));

// ── Style ──
console.log('=== style typo ===');
r = run({
  start: lead({ placement: 'antebraco', body_zone: 'pequeno' }),
  leadText: 'quero em realizmo',
  classifier: classifier({ qualification: { style: 'realismo' } }),
});
check('resolves the style', r.style_val === 'realismo', String(r.style_val));

// ── Doubts gate ──
console.log('=== doubts typo releases the price ===');
r = run({
  start: lead({
    stage_key: 'qualificando',
    placement: 'antebraco',
    body_zone: 'pequeno',
    style: 'realismo',
    agent_state: {
      processo_explicado: true,
      duvidas_eliminadas: false,
      aguardando_confirmacao_duvidas: true,
      preco_liberado: false,
      pending_question: null,
    },
  }),
  leadText: 'sem duvda',
  rows: [{ placement: 'antebraco', body_zone: 'pequeno', table_price: 10000 }],
  classifier: classifier({ doubts_cleared: true }),
});
check('the quote goes out', /R\$\s*\d/.test(r.final_reply), r.final_reply);
check('the gate opens', r.preco_liberado_val === true, String(r.preco_liberado_val));

// ── Objection ──
console.log('=== objection typo ===');
r = run({
  start: lead({
    stage_key: 'orcamento_enviado',
    placement: 'antebraco',
    body_zone: 'pequeno',
    quoted_price_cents: 20000,
    table_price_cents: 20000,
    agent_state: {
      processo_explicado: true,
      duvidas_eliminadas: true,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: true,
      pending_question: null,
    },
  }),
  leadText: 'vou penssar melhor',
  ai: 'Tudo bem, fico aguardando.',
  classifier: classifier({ objection: 'vague' }),
});
check('records the objection', r.chat_state.negotiation_open === true && r.chat_state.negotiation_reason === 'vague',
  JSON.stringify({ o: r.chat_state.negotiation_open, r: r.chat_state.negotiation_reason }));
check('asks the isolating question', /é o valor, é o horário|o que está te impedindo/i.test(r.final_reply), r.final_reply);

// ── Asks for the price ──
console.log('=== "qnto custa" repeats the quote ===');
r = run({
  start: lead({
    stage_key: 'orcamento_enviado',
    placement: 'antebraco',
    body_zone: 'pequeno',
    quoted_price_cents: 20000,
    table_price_cents: 20000,
    agent_state: {
      processo_explicado: true,
      duvidas_eliminadas: true,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: true,
      pending_question: null,
    },
  }),
  leadText: 'qnto custa?',
  rows: [{ placement: 'antebraco', body_zone: 'pequeno', table_price: 20000 }],
  classifier: classifier({ asks_for_price: true }),
});
check('repeats the price', /R\$\s*200,00/.test(r.final_reply), r.final_reply);

// ── Wants the artist ──
console.log('=== "artsta" still hands off ===');
r = run({
  start: lead({ placement: 'antebraco', body_zone: 'pequeno' }),
  leadText: 'quero falar com o artsta',
  ai: 'Claro! Vou te passar para o Gabriel.',
  classifier: classifier({ handoff: { reason: 'lead_requested_artist' }, wants_artist: true }),
});
check('hands off to the artist', r.handoff_reason === 'lead_requested_artist', String(r.handoff_reason));

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
