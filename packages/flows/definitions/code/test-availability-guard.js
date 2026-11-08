#!/usr/bin/env node
/**
 * Behavioural tests for availability truth and reschedules (Beatriz).
 *
 * Loads the generated workflow and runs the real "Parse Classification" node
 * with mocked n8n globals.
 *
 * Covers the two production failures from 05–06 Nov:
 *
 *   1. An empty `Check Availability` result was read as "a agenda está cheia"
 *      even when the project had no placement/body zone. The guard must turn
 *      that claim into a qualification question.
 *   2. A reschedule was treated as a new lead: the agent re-asked "primeira
 *      tatuagem?" and even handed off. With a phone-level active booking the
 *      reply must keep the stage and never re-qualify.
 *
 * Run: node packages/flows/definitions/code/test-availability-guard.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const wf = JSON.parse(fs.readFileSync(
  path.join(__dirname, '..', 'beatriz-whatsapp-agent.json'), 'utf8'));
const nodes = Object.fromEntries(wf.nodes.map((n) => [n.name, n]));
const parseCode = nodes['Parse Classification'].parameters.jsCode;

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

function runParse({ lead, agentOutput, classifier, combinedText = 'quero uma tatuagem' }) {
  const input = { json: { choices: [{ message: { content: JSON.stringify(classifier) } }] } };
  const $ = (name) => ({
    isExecuted: false,
    first: () => {
      if (name === 'Start Turn') return { json: lead };
      if (name === 'AI Agent') return { json: { output: agentOutput } };
      if (name === 'Debounce Resolve') return { json: { combined_text: combinedText } };
      if (name === 'Debounce Start') return { json: { replay: false } };
      if (name === 'Build System Prompt') return { json: { pricing_rows: [
        { placement: 'antebraco', body_zone: 'medio', table_price: 60000 },
      ] } };
      if (name === 'Resolve Artist') return { json: {
        id: 'a1', name: 'Adriano', full_selling: true, pix_key: 'pix',
        deposit_type: 'percent', deposit_value: 30, timezone: 'America/Sao_Paulo',
      } };
      if (name === 'Load Booking') return { json: {} };
      if (name === 'Load Offer') return { json: {} };
      if (name === 'Parse Audio') return { json: {} };
      if (name === 'Parse Reference Analysis') return { json: {} };
      if (name === 'Parse Price Critique') return { json: { price_critique: null } };
      return { json: {} };
    },
    all: () => [],
  });
  const fn = new Function('$', '$input', parseCode);
  return fn($, { first: () => input })[0].json;
}

function baseLead(overrides) {
  return Object.assign({
    lead_id: 'L1',
    project_id: 'P1',
    stage_key: 'qualificando',
    tattoo_type: 'new',
    placement: null,
    body_zone: null,
    style: null,
    first_tattoo: null,
    meaning: null,
    agent_state: {},
    active_booking_id: null,
    active_booking_start: null,
  }, overrides);
}

function gate(overrides) {
  return Object.assign({
    pipeline: null,
    qualification: {},
    pricing: {},
    price_gate: {},
    deposit: {},
    handoff: {},
    booking: {},
    reschedule: false,
  }, overrides);
}

// ── 1. Unqualified project + "no availability" reply ──
console.log('=== Empty availability on an unqualified project ===');
let r = runParse({
  lead: baseLead(),
  agentOutput: 'Infelizmente não há disponibilidade para os dias 21 e 24.',
  classifier: gate({ pipeline: 'qualificando' }),
});
check('never claims the agenda is full', !/disponibilidade|agenda/i.test(r.final_reply), r.final_reply);
check('asks for the missing qualification', /onde no corpo|qual (?:o )?estilo|primeira tatuagem|tatuagem nova/i.test(r.final_reply), r.final_reply);
check('does not hand off', r.event_type !== 'handoff_triggered', String(r.event_type));

// ── 2. Qualified project keeps a genuine no-slots reply ──
console.log('=== Genuine no-slots reply on a qualified project ===');
r = runParse({
  lead: baseLead({ placement: 'antebraco', body_zone: 'medio', style: 'fine_line', first_tattoo: true }),
  agentOutput: 'Infelizmente não temos disponibilidade para o dia 21.',
  classifier: gate({ pipeline: 'qualificando' }),
});
check('keeps the no-slots answer', /disponibilidade/i.test(r.final_reply), r.final_reply);

// ── 3. Reschedule must not re-qualify ──
console.log('=== Reschedule with a phone-level booking ===');
r = runParse({
  lead: baseLead({
    stage_key: 'agendado',
    tattoo_type: 'new',
    placement: 'antebraco',
    body_zone: 'medio',
    style: 'fine_line',
    active_booking_id: 'B1',
    active_booking_start: '2026-11-21T17:00:00+00:00',
  }),
  agentOutput: 'Oi! É a sua primeira tatuagem?',
  classifier: gate({ pipeline: 'agendado', reschedule: true }),
  combinedText: 'preciso remarcar minha tatuagem',
});
check('does not re-ask first tattoo', !/primeira tatuagem/i.test(r.final_reply), r.final_reply);
check('mentions the current booking', /21\/11/.test(r.final_reply), r.final_reply);
check('keeps the agendado stage', r.stage_key === 'agendado', r.stage_key);
check('does not hand off', r.event_type !== 'handoff_triggered', String(r.event_type));

// ── 4. Reschedule reply that tries to hand off is contained ──
console.log('=== Reschedule never hands off ===');
r = runParse({
  lead: baseLead({
    stage_key: 'agendado',
    placement: 'antebraco',
    body_zone: 'medio',
    style: 'fine_line',
    active_booking_id: 'B1',
    active_booking_start: '2026-11-21T17:00:00+00:00',
  }),
  agentOutput: 'Vou te passar para o Adriano, ele resolve isso.',
  classifier: gate({ pipeline: 'aguardando_artista', reschedule: true }),
  combinedText: 'não vou conseguir ir no dia 21, preciso mudar',
});
check('no handoff event', r.event_type !== 'handoff_triggered', String(r.event_type));
check('no handoff reason', !r.handoff_reason, String(r.handoff_reason));
check('pipeline stays agendado', r.stage_key === 'agendado', r.stage_key);

// ── 5. A first-time scheduling request is not a reschedule ──
console.log('=== First booking is not a reschedule ===');
r = runParse({
  lead: baseLead({ placement: 'antebraco', body_zone: 'medio', style: 'fine_line', first_tattoo: true }),
  agentOutput: 'Perfeito! Vou ver os horários para você.',
  classifier: gate({ pipeline: 'qualificando', reschedule: false }),
  combinedText: 'quero agendar',
});
check('stays in qualification', r.stage_key === 'qualificando', r.stage_key);

// ── 6. Repeated question: first repeat is allowed, second hands off ──
console.log('=== Repeated question guard ===');
r = runParse({
  lead: baseLead({
    placement: 'antebraco', body_zone: 'medio', style: 'fine_line', first_tattoo: true,
    agent_state: { pending_question: 'style', processo_explicado: true, aguardando_confirmacao_duvidas: true },
  }),
  agentOutput: 'E qual estilo você tem em mente?',
  classifier: gate({ pipeline: 'qualificando' }),
});
check('first repeat does not hand off', r.event_type !== 'handoff_triggered', String(r.event_type));
check('first repeat is counted', r.chat_state.repeat_count === 1, String(r.chat_state.repeat_count));

r = runParse({
  lead: baseLead({
    placement: 'antebraco', body_zone: 'medio', style: 'fine_line', first_tattoo: true,
    agent_state: { pending_question: 'style', repeat_count: 1, processo_explicado: true, aguardando_confirmacao_duvidas: true },
  }),
  agentOutput: 'E qual estilo você tem em mente?',
  classifier: gate({ pipeline: 'qualificando' }),
});
check('second repeat hands off', r.event_type === 'handoff_triggered', String(r.event_type));
check('reason is ambiguous_reference', r.handoff_reason === 'ambiguous_reference', String(r.handoff_reason));
check('pipeline moves to the artist', r.stage_key === 'aguardando_artista', r.stage_key);

// ── 7. Qualified but gate stuck for three turns → hand off ──
console.log('=== Gate-stuck guard ===');
r = runParse({
  lead: baseLead({
    placement: 'antebraco', body_zone: 'medio', style: 'fine_line', first_tattoo: true,
    agent_state: { qualified_turns: 2, processo_explicado: false, preco_liberado: false },
  }),
  agentOutput: 'Me conta mais sobre o que você tem em mente.',
  classifier: gate({ pipeline: 'qualificando' }),
});
check('stuck gate hands off', r.event_type === 'handoff_triggered', String(r.event_type));
check('reason is gate_stuck', r.handoff_reason === 'gate_stuck', String(r.handoff_reason));
check('pipeline moves to the artist', r.stage_key === 'aguardando_artista', r.stage_key);

// ── 8. Existing client in aftercare is handed to the artist ──
console.log('=== Aftercare client ===');
r = runParse({
  lead: baseLead({ placement: 'panturrilha', body_zone: 'medio', style: 'realismo', first_tattoo: false }),
  agentOutput: 'Essa seria a sua primeira tatuagem?',
  classifier: gate({ pipeline: 'qualificando', aftercare: true }),
  combinedText: 'comprei a pomada que ele indicou, como cuido agora?',
});
check('aftercare does not re-qualify', !/primeira tatuagem/i.test(r.final_reply), r.final_reply);
check('aftercare hands off', r.event_type === 'handoff_triggered', String(r.event_type));
check('aftercare reason', r.handoff_reason === 'client_service', String(r.handoff_reason));
check('aftercare moves to the artist', r.stage_key === 'aguardando_artista', r.stage_key);

console.log(`\n${passes} passed, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
