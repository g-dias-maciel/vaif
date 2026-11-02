#!/usr/bin/env node
/**
 * Behavioural test for the reference-image vision step.
 *
 * Production bug: the lead sent an eagle photo as the first reference. The
 * vision model returned referencia_de_tatuagem=false with an empty subject
 * (the prompt said a real photo "is not a tattoo reference"), Parse Reference
 * Analysis returned null, the analysis was never stored, and the agent later
 * asked for the reference again.
 *
 * Run: node packages/flows/definitions/code/test-vision.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const wf = JSON.parse(fs.readFileSync(
  path.join(__dirname, '..', 'beatriz-whatsapp-agent.json'), 'utf8'));
const nodes = Object.fromEntries(wf.nodes.map((n) => [n.name, n]));

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

function run(name, mock, input) {
  const code = nodes[name].parameters.jsCode;
  const $ = (node) => ({
    first: () => ({ json: mock(node) || {} }),
    isExecuted: true,
  });
  return new Function('$', '$input', code)($, input || { first: () => ({ json: {} }) })[0].json;
}

// ── 1. The prompt must accept ordinary reference photos, and demand a subject
//       even when the image is not a tattoo.
console.log('=== Vision prompt ===');
const visionJs = nodes['Build Vision Request'].parameters.jsCode;
check('accepts animals/objects/photos as references',
  visionJs.includes('animal, objeto, paisagem') || visionJs.includes('animal, objeto'), '');
check('always demands assunto/resumo',
  visionJs.includes('SEMPRE preencha'), '');
check('only rejects clearly unrelated images',
  visionJs.includes('captura de tela de texto') || visionJs.includes('documento'), '');

// ── 2. An empty/false analysis still becomes a stored, minimal analysis so the
//       agent knows a photo arrived and does not ask again.
console.log('=== Empty vision response fallback ===');
const emptyResponse = {
  choices: [{ message: { content: JSON.stringify({
    referencia_de_tatuagem: false,
    assunto: '',
    resumo: '',
    estilo: 'indefinido',
  }) } }],
};
const parsed = run('Parse Reference Analysis', (name) => {
  if (name === 'Build System Prompt') return { system_message: 'BASE' };
  if (name === 'No Audio') return { transcript: null };
  return {};
}, { first: () => ({ json: emptyResponse }) });
check('analysis is not null', parsed.reference_analysis !== null, JSON.stringify(parsed.reference_analysis));
check('fallback subject is stored', parsed.reference_analysis.assunto === 'imagem recebida', parsed.reference_analysis.assunto);
check('prompt gets the reference section', parsed.system_message.includes('## Referência visual'), parsed.system_message.slice(0, 80));

// ── 3. A normal analysis is preserved. ──
console.log('=== Normal vision response ===');
const normalResponse = {
  choices: [{ message: { content: JSON.stringify({
    referencia_de_tatuagem: true,
    assunto: 'águia',
    resumo: 'águia de frente',
    estilo: 'realismo',
    confianca: 'alta',
  }) } }],
};
const normal = run('Parse Reference Analysis', (name) => {
  if (name === 'Build System Prompt') return { system_message: 'BASE' };
  if (name === 'No Audio') return { transcript: null };
  return {};
}, { first: () => ({ json: normalResponse }) });
check('subject preserved', normal.reference_analysis.assunto === 'águia', normal.reference_analysis.assunto);
check('section mentions the subject', normal.system_message.includes('águia'), '');

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
