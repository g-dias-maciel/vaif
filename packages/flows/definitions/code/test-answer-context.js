#!/usr/bin/env node
/**
 * Behavioural test for answer interpretation with the pending question.
 *
 * Production bug: Beatriz asked the type question, the lead answered "Seria uma
 * tatuagem nov" (truncated) and then "isso" to the first-tattoo question. The
 * regex layer matched neither, so the type stayed unknown and Beatriz asked the
 * type question again.
 *
 * The previous turn now persists `pending_question`; when the lead is answering
 * it, the classifier's reading of the answer (which also sees the question) and
 * a small deterministic fallback resolve short/truncated answers.
 *
 * Run: node packages/flows/definitions/code/test-answer-context.js
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

function run({ start, leadText, ai = 'Ok!', classifier }) {
  const $ = (name) => ({
    first: () => {
      if (name === 'Start Turn') return { json: start };
      if (name === 'AI Agent') return { json: { output: ai } };
      if (name === 'Debounce Resolve') return { json: { combined_text: leadText } };
      if (name === 'Build System Prompt') return { json: { pricing_rows: [], offer: { promo_active: false } } };
      if (name === 'Resolve Artist') return { json: { id: 'a1', name: 'Gabriel' } };
      return { json: {} };
    },
  });
  const input = { first: () => ({ json: { choices: [{ message: { content: JSON.stringify(classifier) } }] } }) };
  return new Function('$', '$input', parseCode)($, input)[0].json;
}

function lead(overrides = {}) {
  return {
    stage_key: 'qualificando',
    placement: null,
    body_zone: null,
    style: null,
    first_tattoo: null,
    tattoo_type: null,
    agent_state: {
      processo_explicado: false,
      duvidas_eliminadas: false,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: false,
      pending_question: null,
    },
    ...overrides,
  };
}

function classifier(qualification = {}) {
  return {
    pipeline: 'qualificando',
    event: null,
    qualification,
    pricing: {},
    handoff: { reason: null },
    price_gate: {},
  };
}

// ── 1. The exact production case: "Seria uma tatuagem nov". ──
console.log('=== Type question pending: truncated answer ===');
let r = run({
  start: lead({ agent_state: { ...lead().agent_state, pending_question: 'tipo' } }),
  leadText: 'Seria uma tatuagem nov',
  classifier: classifier({ tipo_tatuagem: 'nova' }),
});
check('the type is resolved from the answer', r.tattoo_type_val === 'new', String(r.tattoo_type_val));

console.log('=== Type question pending: classifier missed it, prefix fallback ===');
r = run({
  start: lead({ agent_state: { ...lead().agent_state, pending_question: 'tipo' } }),
  leadText: 'nov',
  classifier: classifier({ tipo_tatuagem: null }),
});
check('a truncated "nov" still resolves to nova', r.tattoo_type_val === 'new', String(r.tattoo_type_val));

console.log('=== Type question pending: cover-up answer ===');
r = run({
  start: lead({ agent_state: { ...lead().agent_state, pending_question: 'tipo' } }),
  leadText: 'quero cobrir uma antiga',
  classifier: classifier({ tipo_tatuagem: 'cobertura' }),
});
check('cover-up is recognised from the answer', r.tattoo_type_val === 'cover_up', String(r.tattoo_type_val));
check('no handoff before the process', r.stage_key === 'qualificando', r.stage_key);

console.log('=== Type question pending: explicit denial is not a cover-up ===');
r = run({
  start: lead({ agent_state: { ...lead().agent_state, pending_question: 'tipo' } }),
  leadText: 'não é cobertura, é nova',
  classifier: classifier({ tipo_tatuagem: 'cobertura' }),
});
check('a denied cover-up does not set the type', r.tattoo_type_val !== 'cover_up', String(r.tattoo_type_val));

// ── 1c. The AI reads the answer; the JS only validates it. ──
console.log('=== AI-first: terse answer the term list would miss ===');
r = run({
  start: lead({ agent_state: { ...lead().agent_state, pending_question: 'tipo' } }),
  leadText: 'sim, quero cobrir',
  classifier: classifier({ tipo_tatuagem: 'cobertura' }),
});
check('the classifier sets the type', r.tattoo_type_val === 'cover_up', String(r.tattoo_type_val));

console.log('=== AI-first: typo in the noun ===');
r = run({
  start: lead(),
  leadText: 'quero cobrir essa tatuagen',
  classifier: classifier({ tipo_tatuagem: 'cobertura' }),
});
check('the classifier recovers from the typo', r.tattoo_type_val === 'cover_up', String(r.tattoo_type_val));

console.log('=== AI-first: "cobrir" about the AREA is still rejected ===');
r = run({
  start: lead(),
  leadText: 'queria cobrir do ombro ao cotovelo',
  classifier: classifier({ tipo_tatuagem: 'cobertura' }),
});
check('the area reading is not a cover-up', r.tattoo_type_val === null, String(r.tattoo_type_val));

console.log('=== AI-first: an explicit correction beats the model ===');
r = run({
  start: lead({ tattoo_type: 'cover_up', agent_state: { ...lead().agent_state, pending_question: null } }),
  leadText: 'não, é uma tatuagem nova',
  classifier: classifier({ tipo_tatuagem: 'cobertura' }),
});
check('the lead correction wins', r.tattoo_type_val === 'new', String(r.tattoo_type_val));

// ── 2. The second production case: "isso" answering the first-tattoo question. ──
console.log('=== First-tattoo question pending: "isso" ===');
r = run({
  start: lead({ agent_state: { ...lead().agent_state, pending_question: 'primeira_tatuagem' } }),
  leadText: 'isso',
  classifier: classifier({ first_tattoo: null }),
});
check('"isso" means yes', r.first_tattoo_val === true, String(r.first_tattoo_val));

console.log('=== First-tattoo question pending: "não" ===');
r = run({
  start: lead({ agent_state: { ...lead().agent_state, pending_question: 'primeira_tatuagem' } }),
  leadText: 'não',
  classifier: classifier({ first_tattoo: null }),
});
check('"não" means no', r.first_tattoo_val === false, String(r.first_tattoo_val));

console.log('=== No pending question: a bare "isso" is not a type ===');
r = run({
  start: lead(),
  leadText: 'isso',
  classifier: classifier({}),
});
check('the type stays empty', r.tattoo_type_val === null, String(r.tattoo_type_val));

// ── 3. The pending question is persisted for the next turn. ──
console.log('=== Pending question round-trip ===');
r = run({
  start: lead(),
  leadText: 'oi',
  ai: 'Oi! A tatuagem que você quer é uma tatuagem nova, uma cobertura (cover-up) ou uma reforma de uma tatuagem que você já tem?',
  classifier: classifier({}),
});
check('the type question is recorded as pending', r.chat_state.pending_question === 'tipo', String(r.chat_state.pending_question));

r = run({
  start: lead({ agent_state: { ...lead().agent_state, pending_question: 'tipo' } }),
  leadText: 'nova',
  ai: 'Ótimo! E essa seria a sua primeira tatuagem?',
  classifier: classifier({ tipo_tatuagem: 'nova' }),
});
check('the first-tattoo question replaces it', r.chat_state.pending_question === 'primeira_tatuagem', String(r.chat_state.pending_question));

r = run({
  start: lead({ agent_state: { ...lead().agent_state, pending_question: 'primeira_tatuagem' } }),
  leadText: 'isso',
  ai: 'Perfeito! Onde no corpo você quer fazer?',
  classifier: classifier({ first_tattoo: true }),
});
check('the next question is recorded', r.chat_state.pending_question === 'placement', String(r.chat_state.pending_question));

r = run({
  start: lead(),
  leadText: 'ok',
  ai: 'Combinado, já anotei tudo.',
  classifier: classifier({}),
});
check('no question clears it', r.chat_state.pending_question === null, String(r.chat_state.pending_question));

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
