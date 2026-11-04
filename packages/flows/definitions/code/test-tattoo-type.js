#!/usr/bin/env node
/**
 * Behavioural test for `tipo_tatuagem` persistence.
 *
 * Production bug: the lead had already said the tattoo was new. On a later
 * turn Beatriz asked the coverage-of-the-area question ("você quer cobrir só
 * uma partinha...?"), and the classifier returned tipo_tatuagem="cobertura"
 * from Beatriz's own wording. That flipped the persisted type to cover_up and
 * caused a false cover-up handoff plus the type question being asked again.
 *
 * The persisted type may only change when the LEAD says it.
 *
 * Run: node packages/flows/definitions/code/test-tattoo-type.js
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

function runParse({ persistedType, leadText, classifierTipo, agentOutput }) {
  const start = {
    stage_key: 'qualificando',
    tattoo_type: persistedType,
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
  };
  const $ = (name) => ({
    first: () => {
      if (name === 'Start Turn') return { json: start };
      if (name === 'AI Agent') return { json: { output: agentOutput || 'Certo!' } };
      if (name === 'Debounce Resolve') return { json: { combined_text: leadText } };
      if (name === 'Build System Prompt') return { json: { pricing_rows: [], offer: {} } };
      if (name === 'Parse Audio') return { json: {} };
      if (name === 'Parse Reference Analysis') return { json: {} };
      if (name === 'Resolve Artist') return { json: { id: 'a1', name: 'Gabriel' } };
      return { json: {} };
    },
  });
  const classifier = {
    pipeline: 'qualificando',
    event: null,
    qualification: {
      placement: null,
      body_zone: null,
      style: null,
      first_tattoo: null,
      significado: null,
      tipo_tatuagem: classifierTipo,
    },
    pricing: {},
    handoff: {},
    price_gate: { processo_explicado: false, duvidas_eliminadas: false },
  };
  const input = { first: () => ({ json: { choices: [{ message: { content: JSON.stringify(classifier) } }] } }) };
  return new Function('$', '$input', parseCode)($, input)[0].json;
}

// The type is exactly the classifier's reading, validated against the enum.
// No keyword list decides it anymore.
console.log('=== Classifier says nova ===');
let r = runParse({ persistedType: null, leadText: 'qualquer coisa', classifierTipo: 'nova' });
check('new is saved', r.tattoo_type_val === 'new', String(r.tattoo_type_val));

console.log('=== Classifier says cobertura ===');
r = runParse({ persistedType: 'new', leadText: 'qualquer coisa', classifierTipo: 'cobertura' });
check('cover-up replaces new', r.tattoo_type_val === 'cover_up', String(r.tattoo_type_val));

console.log('=== Classifier says reforma ===');
r = runParse({ persistedType: null, leadText: 'qualquer coisa', classifierTipo: 'reforma' });
check('rework is saved', r.tattoo_type_val === 'rework', String(r.tattoo_type_val));

console.log('=== Classifier returns an invalid value ===');
r = runParse({ persistedType: 'cover_up', leadText: 'qualquer coisa', classifierTipo: 'sei la' });
check('nothing is patched', r.tattoo_type_val === null, String(r.tattoo_type_val));

console.log('=== Classifier corrects a persisted cover-up back to new ===');
r = runParse({ persistedType: 'cover_up', leadText: 'nao, e uma tatuagem nova', classifierTipo: 'nova' });
check('the correction wins', r.tattoo_type_val === 'new', String(r.tattoo_type_val));

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
