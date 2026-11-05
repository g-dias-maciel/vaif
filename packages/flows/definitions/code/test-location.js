#!/usr/bin/env node
/**
 * Behavioural test for the "where is the studio?" answer.
 *
 * Production case: the lead asked "Vc tá aqui em Nova Resende?" and Beatriz
 * answered "vou confirmar a localização exata com o artista", even though the
 * artist's city/address were saved in the configuration and rendered into the
 * system prompt.
 *
 * Run: node packages/flows/definitions/code/test-location.js
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

const ROWS = [{ placement: 'braco', body_zone: 'fechamento', table_price: 10000 }];
const OFFER = { promo_active: false };

function runParse({ artist, start, leadText, agentOutput, classifier }) {
  const $ = (name) => ({
    first: () => {
      if (name === 'Start Turn') return { json: start };
      if (name === 'AI Agent') return { json: { output: agentOutput } };
      if (name === 'Debounce Resolve') return { json: { combined_text: leadText } };
      if (name === 'Build System Prompt') return { json: { pricing_rows: ROWS, offer: OFFER } };
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
    address: 'Rua Oswaldo Cruz 135, Jardim Santa Rita',
    ...overrides,
  };
}

function start() {
  return {
    stage_key: 'qualificando',
    tattoo_type: null,
    placement: null,
    body_zone: null,
    style: null,
    first_tattoo: null,
    table_price_cents: null,
    quoted_price_cents: null,
    agent_state: {
      processo_explicado: false,
      duvidas_eliminadas: false,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: false,
      pending_question: null,
    },
  };
}

function classifier(overrides = {}) {
  return { pipeline: 'qualificando', event: null, qualification: {}, pricing: {}, handoff: {}, price_gate: {}, ...overrides };
}

const DEFERRAL = 'Oi! Sou a Beatriz, assistente do Adriano aqui no estúdio. Tudo bem? Sobre sua pergunta, vou confirmar a localização exata com o artista. Antes de começarmos, qual o seu nome?';

// ── 1. The production turn: the saved city replaces the deferral. ──
console.log('=== The saved city answers a location question ===');
let r = runParse({
  artist: artist(),
  start: start(),
  leadText: 'Vc tá aqui em Nova Resende?',
  agentOutput: DEFERRAL,
  classifier: classifier({ asks_location: true }),
});
check('mentions the saved city', /Poços de Caldas/.test(r.final_reply), r.final_reply);
check('mentions the address', /Rua Oswaldo Cruz 135/.test(r.final_reply), r.final_reply);
check('drops the deferral', !/vou confirmar a localiza/i.test(r.final_reply), r.final_reply);
check('still asks the name', /qual o seu nome/i.test(r.final_reply), r.final_reply);

// ── 2. The model already answered: keep its text. ──
console.log('=== An answer that already names the city is kept ===');
r = runParse({
  artist: artist(),
  start: start(),
  leadText: 'você é daqui?',
  agentOutput: 'Oi! Sou a Beatriz, assistente do Adriano. Sim, o estúdio fica em Poços de Caldas. Qual o seu nome?',
  classifier: classifier({ asks_location: true }),
});
check('does not duplicate the city', (r.final_reply.match(/Poços de Caldas/g) || []).length === 1, r.final_reply);

// ── 3. No location question: the reply is untouched. ──
console.log('=== Without a location question the reply is untouched ===');
r = runParse({
  artist: artist(),
  start: start(),
  leadText: 'quero fazer uma tatuagem',
  agentOutput: 'Oi! Sou a Beatriz. Qual o seu nome?',
  classifier: classifier({ asks_location: false }),
});
check('keeps the model reply', r.final_reply === 'Oi! Sou a Beatriz. Qual o seu nome?', r.final_reply);
check('does not invent a location', !/Poços de Caldas/.test(r.final_reply), r.final_reply);

// ── 4. City not configured: the deferral is left alone. ──
console.log('=== Without a saved city the deferral stays ===');
r = runParse({
  artist: artist({ city: '', address: '' }),
  start: start(),
  leadText: 'você é daqui?',
  agentOutput: 'Oi! Sou a Beatriz. Vou confirmar a localização com o artista. Qual o seu nome?',
  classifier: classifier({ asks_location: true }),
});
check('does not invent a city', !/O estúdio fica em/.test(r.final_reply), r.final_reply);

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
