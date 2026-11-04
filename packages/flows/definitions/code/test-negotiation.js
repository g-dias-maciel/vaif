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
      if (name === 'Resolve Artist') return { json: { id: 'a1', name: 'Gabriel', instagram_handle: '@teste', floor_pct: 80 } };
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

// ── 1b. The classifier often reports the discounted amount as "table_cents"
//        (16000/16000). The counterpart must still be detected from the
//        artist's price table + active promotion.
console.log('=== Classifier reports the discounted price as table ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
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
      preco_liberado: true,
    },
  },
  leadText: 'seria o valor mesmo',
  agentOutput: 'Entendi! Se você fechar agora, fica R$ 160,00 à vista ou em até 6x de R$ 26,67 sem juros. O que você acha?',
  classifier: classifier({ table_cents: 16000, nego_cents: 16000 }),
});
check('counterpart still detected from the price table', /@teste/i.test(r.final_reply), r.final_reply);

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

// ── 4. Never let a hesitating lead leave without a move. ──
console.log('=== Price hesitation is answered with a discount, not a goodbye ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'antebraco',
    body_zone: 'pequeno',
    style: 'fineline',
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
  leadText: 'tenho que ver se esse valor rola',
  agentOutput: 'Claro, sem problemas! Fique à vontade, vou ficar aguardando seu retorno.',
  classifier: classifier({ table_cents: 20000, nego_cents: 20000 }),
});
check('offers a discount', /desconto/i.test(r.final_reply), r.final_reply);
check('uses the artist floor (20%)', /20%/.test(r.final_reply), r.final_reply);
check('asks the counterpart (Instagram post)', /posta uma foto|instagram/i.test(r.final_reply), r.final_reply);
check('records the discounted offer', r.quote_offered_cents === 16000, String(r.quote_offered_cents));
check('does not end with a goodbye', !/fico aguardando|fique à vontade/i.test(r.final_reply), r.final_reply);

console.log('=== Generic hesitation keeps the lead with a question ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'antebraco',
    body_zone: 'pequeno',
    style: 'fineline',
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
  leadText: 'vou pensar',
  agentOutput: 'Tudo bem! Fico aguardando.',
  classifier: classifier({ table_cents: 20000, nego_cents: 20000 }),
});
check('asks a question instead of closing', /\?/.test(r.final_reply), r.final_reply);
check('does not end with a goodbye', !/fico aguardando|tudo bem/i.test(r.final_reply), r.final_reply);

// ── 5. Diagnose before offering a condition. ──
console.log('=== Decision-maker: ask what the person needs ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'antebraco',
    body_zone: 'pequeno',
    style: 'fineline',
    first_tattoo: true,
    table_price_cents: 20000,
    quoted_price_cents: 20000,
    agent_state: { processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false, preco_liberado: true },
  },
  leadText: 'preciso ver com meu marido',
  agentOutput: 'Claro, fique à vontade.',
  classifier: classifier({ table_cents: 20000, nego_cents: 20000 }),
});
check('asks what the other person needs', /o que essa pessoa precisa saber|o que ele precisa saber/i.test(r.final_reply), r.final_reply);
check('mentions holding the slot', /segurar/i.test(r.final_reply), r.final_reply);
check('records the reason', r.chat_state.negotiation_open === true && r.chat_state.negotiation_reason === 'decision',
  JSON.stringify({ o: r.chat_state.negotiation_open, r: r.chat_state.negotiation_reason }));

console.log('=== Follow-up "ok" escalates to the concrete offer ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'antebraco',
    body_zone: 'pequeno',
    style: 'fineline',
    first_tattoo: true,
    table_price_cents: 20000,
    quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: true, negotiation_reason: 'decision',
    },
  },
  leadText: 'ok',
  agentOutput: 'Perfeito! Fico aguardando seu retorno.',
  classifier: classifier({ table_cents: 20000, nego_cents: 20000 }),
});
check('makes the concrete offer', /desconto/i.test(r.final_reply), r.final_reply);
check('records the discounted offer', r.quote_offered_cents === 16000, String(r.quote_offered_cents));

console.log('=== Vague hesitation diagnoses first (no discount yet) ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'antebraco',
    body_zone: 'pequeno',
    style: 'fineline',
    first_tattoo: true,
    table_price_cents: 20000,
    quoted_price_cents: 20000,
    agent_state: { processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false, preco_liberado: true },
  },
  leadText: 'vou pensar',
  agentOutput: 'Tudo bem, fico aguardando.',
  classifier: classifier({ table_cents: 20000, nego_cents: 20000 }),
});
check('asks the isolating question', /é o valor, é o horário|o que está te impedindo/i.test(r.final_reply), r.final_reply);
check('does not discount yet', !/desconto/i.test(r.final_reply), r.final_reply);

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
