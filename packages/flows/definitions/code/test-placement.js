#!/usr/bin/env node
/**
 * Behavioural test for placement extraction and its use in the price lookup.
 *
 * Production bug: the lead said "queria cobrir do ombro ao cotovelo". The word
 * "cobrir" was read as cover-up (it was the AREA), and the generic "braco"
 * placement stayed persisted. When the lead then answered "parte externa", the
 * parser did not map it to braco_externo, the price row was not found, and the
 * agent asked for the placement again instead of quoting.
 *
 * Run: node packages/flows/definitions/code/test-placement.js
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
  { placement: 'antebraco', body_zone: 'fechamento', table_price: 10000 },
  { placement: 'braco', body_zone: 'fechamento', table_price: 20000 },
];

function runParse({ start, leadText, classifier, agentOutput, rows }) {
  const $ = (name) => ({
    first: () => {
      if (name === 'Start Turn') return { json: start };
      if (name === 'AI Agent') return { json: { output: agentOutput || 'Certo!' } };
      if (name === 'Debounce Resolve') return { json: { combined_text: leadText } };
      if (name === 'Build System Prompt') return { json: { pricing_rows: rows || ROWS, offer: {} } };
      if (name === 'Parse Audio') return { json: {} };
      if (name === 'Parse Reference Analysis') return { json: {} };
      if (name === 'Resolve Artist') return { json: { id: 'a1', name: 'Gabriel' } };
      return { json: {} };
    },
  });
  const input = { first: () => ({ json: { choices: [{ message: { content: JSON.stringify(classifier) } }] } }) };
  return new Function('$', '$input', parseCode)($, input)[0].json;
}

function start(overrides = {}) {
  return {
    stage_key: 'qualificando',
    tattoo_type: 'new',
    placement: 'braco',
    body_zone: null,
    first_tattoo: true,
    style: null,
    agent_state: {
      processo_explicado: true,
      duvidas_eliminadas: true,
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
    qualification: { placement: null, body_zone: null, style: null, first_tattoo: null, significado: null, tipo_tatuagem: null },
    pricing: {},
    handoff: {},
    price_gate: {},
    ...overrides,
  };
}

// ── 1. "Cobrir do ombro ao cotovelo" is the AREA, not cover-up. ──
console.log('=== "Cobrir do ombro ao cotovelo" is a coverage answer ===');
let r = runParse({
  start: start(),
  leadText: 'queria cobrir do ombro ao cotovelo',
  classifier: classifier({ qualification: { placement: 'braco', body_zone: 'fechamento', tipo_tatuagem: 'cobertura' } }),
});
check('tattoo type is not flipped to cover_up', r.tattoo_type_val === null, String(r.tattoo_type_val));
check('placement stays the arm (no downgrade patch)', r.placement_val === null, String(r.placement_val));
check('zone becomes fechamento', r.body_zone_val === 'fechamento', String(r.body_zone_val));

// ── 2. The follow-up "parte externa" resolves the price row and quotes. ──
console.log('=== "Parte externa" maps to braco_externo and quotes ===');
r = runParse({
  start: start({ body_zone: 'fechamento', style: 'realismo' }),
  leadText: 'seria na parte externa\ncomo falei\ndo ombro ao cotovelo',
  classifier: classifier({ qualification: { placement: 'braco', body_zone: 'fechamento' }, pricing: { table_cents: 10000, nego_cents: 10000 } }),
  agentOutput: 'Para a parte externa do braço, no fechamento, fica R$ 100,00 à vista ou em até 6x de R$ 16,67 sem juros.',
});
check('placement resolved to braco_externo', r.placement_val === 'braco_externo', String(r.placement_val));
check('zone stays fechamento (no redundant patch)', r.body_zone_val === null, String(r.body_zone_val));
check('quote is sent instead of re-asking the region', /R\$\s*100,00/.test(r.final_reply), r.final_reply);
check('pipeline advances', r.stage_key === 'orcamento_enviado', r.stage_key);

// ── 3. Side answers map correctly. ──
console.log('=== Inner side ===');
r = runParse({
  start: start({ body_zone: 'fechamento' }),
  leadText: 'na parte de dentro',
  classifier: classifier({ qualification: { placement: 'braco', body_zone: 'fechamento' } }),
});
check('"parte de dentro" -> braco_interno', r.placement_val === 'braco_interno', String(r.placement_val));

// ── 4. "Ombro ao cotovelo" alone is the upper arm, not the shoulder. ──
console.log('=== Shoulder-to-elbow alone ===');
r = runParse({
  start: start({ placement: null }),
  leadText: 'do ombro ao cotovelo',
  classifier: classifier({ qualification: { placement: null, body_zone: 'fechamento' } }),
});
check('"ombro ao cotovelo" -> braco', r.placement_val === 'braco', String(r.placement_val));

// ── 5. A generic "braço" does not downgrade a specific persisted placement. ──
console.log('=== Generic arm does not downgrade a specific side ===');
r = runParse({
  start: start({ placement: 'braco_externo', body_zone: 'fechamento' }),
  leadText: 'no braco',
  classifier: classifier({ qualification: { placement: 'braco', body_zone: 'fechamento' } }),
});
check('persisted braco_externo is kept', r.placement_val === null, String(r.placement_val));

// ── 6. Explicit cover-up still works. ──
console.log('=== Explicit cover-up ===');
r = runParse({
  start: start(),
  leadText: 'quero cobrir uma tatuagem antiga',
  classifier: classifier({ qualification: { tipo_tatuagem: 'cobertura' } }),
});
check('explicit cover-up is persisted', r.tattoo_type_val === 'cover_up', String(r.tattoo_type_val));

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
