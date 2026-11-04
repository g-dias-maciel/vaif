#!/usr/bin/env node
/**
 * Photo-derived placement/size.
 *
 * The vision model reads where the tattoo is and its approximate size. When
 * those come from the photo (not from the lead's words), Beatriz must confirm
 * them instead of assuming — and must not ask for them again.
 *
 * Run: node packages/flows/definitions/code/test-photo-qualification.js
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

function run({ start, leadText, ai = 'Ok!', classifier, analysis = null, photo = false }) {
  const $ = (name) => ({
    isExecuted: name === 'Parse Reference Analysis' ? !!photo : undefined,
    first: () => {
      if (name === 'Start Turn') return { json: start };
      if (name === 'AI Agent') return { json: { output: ai } };
      if (name === 'Debounce Resolve') return { json: { combined_text: leadText } };
      if (name === 'Build System Prompt') return { json: { pricing_rows: [], offer: { promo_active: false } } };
      if (name === 'Resolve Artist') return { json: { id: 'a1', name: 'Gabriel', full_selling: true } };
      if (name === 'Parse Reference Analysis') return { json: analysis ? { reference_analysis: analysis } : {} };
      return { json: {} };
    },
  });
  const input = { first: () => ({ json: { choices: [{ message: { content: JSON.stringify(classifier) } }] } }) };
  return new Function('$', '$input', parseCode)($, input)[0].json;
}

function classifier(overrides = {}) {
  return { pipeline: 'qualificando', event: null, qualification: {}, pricing: {}, handoff: {}, price_gate: {}, ...overrides };
}

function lead(overrides = {}) {
  return {
    name: 'Liz',
    stage_key: 'qualificando',
    tattoo_type: 'new',
    placement: null,
    body_zone: null,
    style: 'realismo',
    first_tattoo: false,
    quoted_price_cents: null,
    reference_analysis: [{ assunto: 'flor de lótus', tipo: 'foto_de_tatuagem', local: 'antebraco', tamanho: 'medio' }],
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

// ── 1. Photo supplies placement and size: confirm, don't ask. ──
console.log('=== Photo gives placement and size ===');
let r = run({
  start: lead(),
  leadText: 'liz',
  ai: 'Vou te explicar como funciona o processo do Gabriel.',
  classifier: classifier(),
});
check('records the placement', r.placement_val === 'antebraco', String(r.placement_val));
check('records the size', r.body_zone_val === 'medio', String(r.body_zone_val));
check('asks for confirmation', /vi na foto.*é isso mesmo\?/i.test(r.final_reply), r.final_reply);
check('mentions the place and size', /antebraço/i.test(r.final_reply) && /médio/i.test(r.final_reply), r.final_reply);
check('marks it as photo-derived', r.chat_state.placement_from_photo === true && r.chat_state.zone_from_photo === true,
  JSON.stringify({ p: r.chat_state.placement_from_photo, z: r.chat_state.zone_from_photo }));
check('records the confirmation question', r.chat_state.pending_question === 'confirmar_foto', String(r.chat_state.pending_question));
check('does not arm the doubts checkpoint', r.aguardando_confirmacao_duvidas_val === false, String(r.aguardando_confirmacao_duvidas_val));

// ── 2. The lead confirms. ──
console.log('=== Lead confirms the photo ===');
r = run({
  start: lead({
    placement: 'antebraco',
    body_zone: 'medio',
    agent_state: {
      processo_explicado: false,
      duvidas_eliminadas: false,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: false,
      pending_question: 'confirmar_foto',
      placement_from_photo: true,
      zone_from_photo: true,
    },
  }),
  leadText: 'isso',
  ai: 'Perfeito!',
  classifier: classifier(),
});
check('marks the photo as confirmed', r.chat_state.photo_confirmed === true, JSON.stringify({ c: r.chat_state.photo_confirmed }));
check('stops asking for confirmation', !/é isso mesmo\?/i.test(r.final_reply), r.final_reply);

// ── 3. The photo has no size: ask for it. ──
console.log('=== Photo without a size asks for it ===');
r = run({
  start: lead({ reference_analysis: [{ assunto: 'flor', tipo: 'foto_de_tatuagem', local: 'antebraco', tamanho: 'indefinido' }] }),
  leadText: 'liz',
  ai: 'Ok!',
  classifier: classifier(),
});
check('keeps the placement from the photo', r.placement_val === 'antebraco', String(r.placement_val));
check('asks the size', /partinha|área média|local inteiro/i.test(r.final_reply), r.final_reply);
check('does not ask for confirmation yet', !/é isso mesmo\?/i.test(r.final_reply), r.final_reply);

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
