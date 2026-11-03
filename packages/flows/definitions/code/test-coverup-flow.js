#!/usr/bin/env node
/**
 * Behavioural test for the cover-up / rework flow.
 *
 * These projects must be qualified and have the process explained, then hand
 * off to the artist WITHOUT a price and WITHOUT the doubts question — for every
 * artist (full-selling or not).
 *
 * Run: node packages/flows/definitions/code/test-coverup-flow.js
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

function run({ start, leadText, ai, classifier }) {
  const $ = (name) => ({
    first: () => {
      if (name === 'Start Turn') return { json: start };
      if (name === 'AI Agent') return { json: { output: ai } };
      if (name === 'Debounce Resolve') return { json: { combined_text: leadText } };
      if (name === 'Build System Prompt') {
        return { json: { pricing_rows: [{ placement: 'braco_externo', body_zone: 'fechamento', table_price: 10000 }], offer: { promo_active: false } } };
      }
      if (name === 'Resolve Artist') return { json: { id: 'a1', name: 'Adriano' } };
      return { json: {} };
    },
  });
  const input = { first: () => ({ json: { choices: [{ message: { content: JSON.stringify(classifier) } }] } }) };
  return new Function('$', '$input', parseCode)($, input)[0].json;
}

function start(overrides = {}) {
  return {
    stage_key: 'qualificando',
    placement: 'braco',
    body_zone: null,
    style: null,
    first_tattoo: true,
    agent_state: {
      processo_explicado: false,
      duvidas_eliminadas: false,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: false,
    },
    ...overrides,
  };
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

// ── 1. During discovery there is no handoff and no price. ──
console.log('=== Cover-up: discovery (before the process) ===');
let r = run({
  start: start({ tattoo_type: 'cover_up' }),
  leadText: 'quero cobrir uma tattoo no braço',
  ai: 'Entendi! Nesse braço, você quer cobrir só uma partinha, uma área média ou o braço inteiro?',
  classifier: classifier({ handoff: { reason: 'cover_up' } }),
});
check('no handoff before the process', r.stage_key === 'qualificando' && !r.handoff_reason, JSON.stringify({ s: r.stage_key, h: r.handoff_reason }));
check('no price before the process', !/R\$\s*\d/.test(r.final_reply), r.final_reply);

// ── 2. After the process explanation, hand off without a price. ──
console.log('=== Cover-up: process explained ===');
r = run({
  start: start({ tattoo_type: 'cover_up', body_zone: 'fechamento', style: 'realismo' }),
  leadText: 'sem dúvida',
  ai: 'O processo de criação do Adriano é assim: ele vai sentar junto com você e criar um projeto exclusivo. Fica R$ 1.500 à vista.',
  classifier: classifier({ handoff: { reason: 'cover_up' } }),
});
check('handoff after the process', r.stage_key === 'aguardando_artista' && r.handoff_reason === 'cover_up', JSON.stringify({ s: r.stage_key, h: r.handoff_reason }));
check('price is stripped', !/R\$\s*\d/.test(r.final_reply), r.final_reply);
check('handoff message is present', /passar para o Adriano/i.test(r.final_reply), r.final_reply);
check('no doubts question', !/alguma d[uú]vida\?/i.test(r.final_reply), r.final_reply);
check('no dangling price verb', !/\bFica\s*$/i.test(r.final_reply.trim()), r.final_reply);

// ── 3. Rework behaves the same and labels the reason. ──
console.log('=== Rework: process explained ===');
r = run({
  start: start({ tattoo_type: 'rework', body_zone: 'fechamento' }),
  leadText: 'ok',
  ai: 'O processo de criação do Adriano é assim: projeto exclusivo, tirar as medidas, encaixe perfeito.',
  classifier: classifier({ handoff: { reason: 'reforma' } }),
});
check('rework hands off', r.stage_key === 'aguardando_artista' && r.handoff_reason === 'reforma', JSON.stringify({ s: r.stage_key, h: r.handoff_reason }));
check('rework has no price', !/R\$\s*\d/.test(r.final_reply), r.final_reply);

// ── 4. New tattoos are unaffected and still get priced. ──
console.log('=== New tattoo still quotes ===');
r = run({
  start: start({ tattoo_type: 'new', body_zone: 'fechamento', agent_state: {
    processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false, preco_liberado: false,
  } }),
  leadText: 'não',
  ai: 'Para fechamento no braço, fica R$ 100,00 à vista.',
  classifier: classifier({ pricing: { table_cents: 10000, nego_cents: 10000 } }),
});
check('new project is priced', /R\$\s*100,00/.test(r.final_reply), r.final_reply);
check('new project does not hand off', r.stage_key === 'orcamento_enviado', r.stage_key);

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
