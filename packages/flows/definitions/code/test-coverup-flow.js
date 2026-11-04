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

function run({ start, leadText, ai, classifier, photo = false }) {
  const $ = (name) => ({
    isExecuted: name === 'Parse Reference Analysis' ? !!photo : undefined,
    first: () => {
      if (name === 'Start Turn') return { json: start };
      if (name === 'AI Agent') return { json: { output: ai } };
      if (name === 'Debounce Resolve') return { json: { combined_text: leadText } };
      if (name === 'Build System Prompt') {
        return { json: { pricing_rows: [{ placement: 'braco_externo', body_zone: 'fechamento', table_price: 10000 }], offer: { promo_active: false } } };
      }
      if (name === 'Resolve Artist') return { json: { id: 'a1', name: 'Adriano' } };
      if (name === 'Parse Reference Analysis') {
        return { json: { reference_analysis: { assunto: 'tatuagem antiga no braço', confianca: 'alta' } } };
      }
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
check('asks for a photo of the existing tattoo', /manda uma foto/i.test(r.final_reply), r.final_reply);
check('does not ask for the size', !/partinha|área média|area media|local inteiro/i.test(r.final_reply), r.final_reply);
check('no price before the process', !/R\$\s*\d/.test(r.final_reply), r.final_reply);

// ── 1b. After the current-tattoo photo, ask what the lead wants on top. ──
console.log('=== Cover-up: asks for the new design reference ===');
r = run({
  start: start({ tattoo_type: 'cover_up', placement: 'braco' }),
  leadText: 'quero cobrir uma tattoo no braço',
  ai: 'Nesse braço, você quer cobrir só uma partinha, uma área média ou o braço inteiro?',
  classifier: classifier(),
  photo: true,
});
check('does not ask for the size once the photo arrived', !/partinha|área média|area media|local inteiro/i.test(r.final_reply), r.final_reply);
check('asks for a reference of the new design', /refer[eê]ncia/i.test(r.final_reply), r.final_reply);
check('the cover photo is recorded', r.chat_state.cover_photo === true, JSON.stringify({ c: r.chat_state.cover_photo }));
check('the question is tracked as the new-design photo', r.chat_state.pending_question === 'foto_nova', String(r.chat_state.pending_question));

// ── 1b2. With both photos, the process comes. ──
console.log('=== Cover-up: both photos in ===');
r = run({
  start: start({ tattoo_type: 'cover_up', reference_analysis: [{ assunto: 'tatuagem antiga' }, { assunto: 'flor grande' }] }),
  leadText: '[FOTO RECEBIDA]',
  ai: 'Legal!',
  classifier: classifier(),
  photo: true,
});
check('moves on to the process', /processo de criação/i.test(r.final_reply), r.final_reply);
check('the new reference is recorded', r.chat_state.new_reference === true, JSON.stringify({ n: r.chat_state.new_reference }));

// ── 1b3. The new-design photo is acknowledged as a reference. ──
console.log('=== Cover-up: new design acknowledgement ===');
r = run({
  start: start({ tattoo_type: 'cover_up', agent_state: {
    processo_explicado: false, duvidas_eliminadas: false, aguardando_confirmacao_duvidas: false, preco_liberado: false,
    cover_photo: true, new_reference: false, new_ref_asked: true, pending_question: 'foto_nova',
  } }),
  leadText: '[FOTO RECEBIDA]',
  ai: 'Legal!',
  classifier: classifier(),
  photo: true,
});
check('acknowledges it as a reference', /refer[eê]ncia/i.test(r.final_reply) && /curti/i.test(r.final_reply), r.final_reply);
check('does not call it the tattoo to cover', !/tatuagem que você quer cobrir/i.test(r.final_reply), r.final_reply);

// ── 1c. A later size question is replaced too, while the photo is missing. ──
console.log('=== Cover-up: size question after the process is blocked ===');
r = run({
  start: start({ tattoo_type: 'cover_up', placement: 'braco', agent_state: {
    processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false, preco_liberado: false,
  } }),
  leadText: 'pode ser',
  ai: 'Nesse braço, você quer cobrir só uma partinha, uma área média ou o braço inteiro?',
  classifier: classifier(),
});
check('no size question for cover-up', !/partinha|área média|area media|local inteiro/i.test(r.final_reply), r.final_reply);
check('asks for the photo instead', /manda uma foto/i.test(r.final_reply), r.final_reply);

// ── 1d. Rework asks for a photo of the tattoo to be reworked. ──
console.log('=== Rework: photo wording ===');
r = run({
  start: start({ tattoo_type: 'rework' }),
  leadText: 'quero reformar uma tatuagem',
  ai: 'Nesse local, você quer uma tattoo pequena, uma área média ou o local inteiro?',
  classifier: classifier(),
});
check('asks for a photo to reformar', /reformar/i.test(r.final_reply) && /foto/i.test(r.final_reply), r.final_reply);

// ── 1d2. Never ask "primeira tatuagem" for a cover-up. ──
console.log('=== Cover-up: no first-tattoo question ===');
r = run({
  start: start({ tattoo_type: 'cover_up', reference_analysis: [{ assunto: 'tatuagem antiga' }], agent_state: {
    processo_explicado: false, duvidas_eliminadas: false, aguardando_confirmacao_duvidas: false, preco_liberado: false,
    cover_photo: true, new_reference: true,
  } }),
  leadText: 'Danilo',
  ai: 'Prazer, Danilo! Primeiro, essa tatuagem que você quer cobrir é a sua primeira tatuagem ou você já tem outras?',
  classifier: classifier(),
});
check('does not ask if it is the first tattoo', !/primeira (?:tatuagem|tattoo)|j[aá] (?:tem|fez) outras/i.test(r.final_reply), r.final_reply);
check('moves on to the process', /processo de criação/i.test(r.final_reply), r.final_reply);

// ── 1e. The photo is the current tattoo, never a "reference". ──
console.log('=== Cover-up: photo acknowledgement ===');
r = run({
  start: start({ tattoo_type: 'cover_up' }),
  leadText: '[FOTO RECEBIDA]',
  ai: 'Vou te passar para o Adriano para ele avaliar.',
  classifier: classifier({ handoff: { reason: 'cover_up' } }),
  photo: true,
});
check('calls it the tattoo to cover', /foto da tatuagem que você quer cobrir/i.test(r.final_reply), r.final_reply);
check('never calls it a reference', !/vi a refer[êe]ncia/i.test(r.final_reply), r.final_reply);
check('never says curti', !/curti/i.test(r.final_reply), r.final_reply);
check('does not hand off before the process', r.stage_key === 'qualificando' && !r.handoff_reason, JSON.stringify({ s: r.stage_key, h: r.handoff_reason }));
check('asks for the new design next', /refer[eê]ncia/i.test(r.final_reply), r.final_reply);

// ── 1f. With the photo already sent, never ask where the tattoo is. ──
console.log('=== Cover-up: no "where" question after the photo ===');
r = run({
  start: start({ tattoo_type: 'cover_up', reference_analysis: [{ assunto: 'tatuagem antiga no antebraço' }], agent_state: {
    processo_explicado: false, duvidas_eliminadas: false, aguardando_confirmacao_duvidas: false, preco_liberado: false,
    cover_photo: true, new_reference: true,
  } }),
  leadText: 'Daniel',
  ai: 'Prazer em te conhecer, Daniel! Onde você gostaria de fazer a cobertura dessa tatuagem?',
  classifier: classifier(),
});
check('does not ask where', !/onde voc[eê] gostaria|onde no corpo|qual (?:o )?local/i.test(r.final_reply), r.final_reply);
check('moves on to the process', /processo de criação/i.test(r.final_reply), r.final_reply);
check('the placement is not recorded as a pending question', r.chat_state.pending_question === null, String(r.chat_state.pending_question));

// ── 2. After the process explanation, hand off without a price. ──
console.log('=== Cover-up: process explained ===');
r = run({
  start: start({ tattoo_type: 'cover_up', body_zone: 'fechamento', style: 'realismo' }),
  leadText: 'sem dúvida',
  photo: true,
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
  photo: true,
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
