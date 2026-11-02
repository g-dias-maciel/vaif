#!/usr/bin/env node
/**
 * Behavioural test for negotiated discounts on later turns.
 *
 * Production bug: Beatriz offered 20% off R$ 200; the lead said "pode ser".
 * The model replied "R$ 160", but the promotion-quote normaliser replaced it
 * with the R$ 200 promotion quote because the active offer was still live.
 *
 * The promotion normaliser must only run on the FIRST quote. After the gate is
 * open, a negotiated price is the model's (and the lead's) source of truth.
 *
 * Run: node packages/flows/definitions/code/test-negotiation.js
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

const ROWS = [
  { placement: 'braco_externo', body_zone: 'fechamento', table_price: 10000 },
  { placement: 'braco_interno', body_zone: 'fechamento', table_price: 10000 },
];
const OFFER = { promo_active: true, multiplier: 2, name: 'Leve 2 pelo valor de 1' };

function runParse({ start, leadText, agentOutput, classifier }) {
  const $ = (name) => ({
    first: () => {
      if (name === 'Start Turn') return { json: start };
      if (name === 'AI Agent') return { json: { output: agentOutput } };
      if (name === 'Debounce Resolve') return { json: { combined_text: leadText } };
      if (name === 'Build System Prompt') return { json: { pricing_rows: ROWS, offer: OFFER } };
      if (name === 'Parse Audio') return { json: {} };
      if (name === 'Parse Reference Analysis') return { json: {} };
      if (name === 'Resolve Artist') return { json: { id: 'a1', name: 'Gabriel', instagram_handle: '@teste' } };
      return { json: {} };
    },
  });
  const input = { first: () => ({ json: { choices: [{ message: { content: JSON.stringify(classifier) } }] } }) };
  return new Function('$', '$input', parseCode)($, input)[0].json;
}

function classifier(pricing) {
  return {
    pipeline: 'orcamento_enviado',
    event: null,
    qualification: { placement: 'braco', body_zone: 'fechamento', style: 'realismo', first_tattoo: true },
    pricing,
    handoff: {},
    price_gate: { processo_explicado: true, duvidas_eliminadas: true },
  };
}

// ── 1. The production case: 20% off after the first quote. ──
console.log('=== Negotiated discount after the quote is open ===');
let r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'braco',
    body_zone: 'fechamento',
    style: 'realismo',
    first_tattoo: true,
    table_price_cents: 20000,
    quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true,
      duvidas_eliminadas: true,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: true,
    },
  },
  leadText: 'pode ser',
  agentOutput: 'Legal, Danilo! Com o desconto, o valor fica R$ 160,00 à vista ou em até 6x de R$ 26,67 sem juros. Como fica esse valor para você?',
  classifier: classifier({ table_cents: 20000, nego_cents: 16000 }),
});
check('negotiated price is kept', /R\$\s*160,00/.test(r.final_reply), r.final_reply);
check('promotion price does not replace it', !/R\$\s*200,00/.test(r.final_reply), r.final_reply);
check('negotiated price is persisted as the offer', r.quote_offered_cents === 16000, String(r.quote_offered_cents));
check('stage stays orcamento_enviado', r.stage_key === 'orcamento_enviado', r.stage_key);
check('discount mentions the Instagram counterpart', /@teste/i.test(r.final_reply), r.final_reply);

// ── 2. The first quote still normalises the table reply to the promotion. ──
console.log('=== First quote still uses the promotion price ===');
r = runParse({
  start: {
    stage_key: 'qualificando',
    tattoo_type: 'new',
    placement: 'braco',
    body_zone: 'fechamento',
    style: 'realismo',
    first_tattoo: true,
    table_price_cents: null,
    quoted_price_cents: null,
    agent_state: {
      processo_explicado: true,
      duvidas_eliminadas: true,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: false,
    },
  },
  leadText: 'sem duvidas',
  agentOutput: 'Para o braço, fica R$ 100,00 à vista.',
  classifier: classifier({ table_cents: 10000, nego_cents: 10000 }),
});
check('first quote shows the promotion price', /R\$\s*200,00/.test(r.final_reply), r.final_reply);
check('promotion is persisted as the first offer', r.quote_offered_cents === 20000, String(r.quote_offered_cents));
check('first quote does not add the discount counterpart', !/@teste/i.test(r.final_reply), r.final_reply);

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
