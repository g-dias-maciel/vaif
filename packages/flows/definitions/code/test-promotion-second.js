#!/usr/bin/env node
/**
 * Behavioural test for the second person of a 2-for-1 promotion.
 *
 * The promoter already closed the 2-for-1 with someone else, so the second
 * tattoo belongs to the artist: Beatriz must not quote or schedule it. She says
 * it is with the artist and hands the lead off.
 *
 * Run: node packages/flows/definitions/code/test-promotion-second.js
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

const OFFER = {
  promo_active: true,
  name: 'Leve 2 pelo valor de 1',
  description: 'Fazendo uma tatuagem, você ganha outra do mesmo tamanho, grátis.',
  multiplier: 2,
  open_quote_valid: false,
};

function runParse({ artist, start, leadText, agentOutput, classifier }) {
  const $ = (name) => ({
    first: () => {
      if (name === 'Start Turn') return { json: start };
      if (name === 'AI Agent') return { json: { output: agentOutput } };
      if (name === 'Debounce Resolve') return { json: { combined_text: leadText } };
      if (name === 'Build System Prompt') {
        return {
          json: {
            pricing_rows: [{ placement: 'braco_externo', body_zone: 'fechamento', table_price: 35000 }],
            offer: OFFER,
          },
        };
      }
      if (name === 'Parse Audio') return { json: {} };
      if (name === 'Parse Reference Analysis') return { json: {} };
      if (name === 'Resolve Artist') return { json: artist };
      return { json: {} };
    },
  });
  const input = { first: () => ({ json: { choices: [{ message: { content: JSON.stringify(classifier) } }] } }) };
  return new Function('$', '$input', parseCode)($, input)[0].json;
}

function artist(overrides = {}) {
  return {
    id: 'a1',
    name: 'Adriano',
    instagram_handle: '@teste',
    floor_pct: 80,
    city: 'Poços de Caldas',
    address: 'Rua Oswaldo Cruz 135',
    ...overrides,
  };
}

function start(overrides = {}) {
  return {
    stage_key: 'qualificando',
    tattoo_type: 'nova',
    placement: 'braco_externo',
    body_zone: 'fechamento',
    style: 'fineline',
    first_tattoo: false,
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

// ── 1. The lead is the second person: hand off, no price, no slot. ──
console.log('=== Second person of a closed 2-for-1 hands off ===');
let r = runParse({
  artist: artist(),
  start: start(),
  leadText: 'meu amigo já fechou a promoção de 2 por 1 com você e eu vou fazer a segunda tattoo',
  agentOutput: 'Perfeito! Fica R$ 700,00 e a segunda é grátis. Tenho horário quinta às 14h, quer agendar?',
  classifier: classifier({ promotion_second: true, pipeline: 'orcamento_enviado', event: 'quote_sent' }),
});
check('hands off', r.handoff_reason === 'promotion_second', JSON.stringify(r.handoff_reason));
check('stage is waiting for the artist', r.stage_key === 'aguardando_artista', r.stage_key);
check('event is a handoff', r.event_type === 'handoff_triggered', r.event_type);
check('no price', !/R\$\s*\d/.test(r.final_reply), r.final_reply);
check('no slot', !/hor[áa]rio|agendar|quinta|14h/i.test(r.final_reply), r.final_reply);
check('says it is with the artist', /é com o Adriano/i.test(r.final_reply), r.final_reply);
check('says it will hand off', /vou passar/i.test(r.final_reply), r.final_reply);
check('no forbidden punctuation', !/[:;—–]/.test(r.final_reply), r.final_reply);

// ── 2. Merely asking about the promotion is not a handoff. ──
console.log('=== Asking about the promotion does not hand off ===');
const ASK = 'Oi! A promoção de 2 por 1 ainda tá valendo? Como funciona?';
r = runParse({
  artist: artist(),
  start: start({
    stage_key: 'novo',
    placement: null,
    body_zone: null,
    style: null,
    first_tattoo: null,
    agent_state: {
      processo_explicado: false,
      duvidas_eliminadas: false,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: false,
      pending_question: null,
    },
  }),
  leadText: ASK,
  agentOutput: 'Oi! Sim, fazendo uma tatuagem você ganha outra do mesmo tamanho, grátis. Qual o seu nome?',
  classifier: classifier({ pipeline: 'novo', promotion_second: false }),
});
check('no handoff', !r.handoff_reason, JSON.stringify(r.handoff_reason));
check('stays in discovery', r.stage_key === 'novo', r.stage_key);
check('keeps the model reply', /ganha outra do mesmo tamanho/i.test(r.final_reply), r.final_reply);

// ── 3. A closed lead is terminal: the guard does not reopen it. ──
console.log('=== A closed lead is left closed ===');
r = runParse({
  artist: artist(),
  start: start({ stage_key: 'fechado' }),
  leadText: 'minha namorada já fechou a promoção e eu vou fazer a segunda',
  agentOutput: 'Combinado!',
  classifier: classifier({ promotion_second: true }),
});
check('keeps the closed stage', r.stage_key === 'fechado', r.stage_key);
check('no handoff reason', !r.handoff_reason, JSON.stringify(r.handoff_reason));

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
