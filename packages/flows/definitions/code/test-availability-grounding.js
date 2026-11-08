#!/usr/bin/env node
/**
 * Behavioural tests for Pass 2 — availability grounding in enforce mode.
 *
 * Loads the generated workflow and evaluates the real "Validate Enforce Reply"
 * node. Slot claims must match the facts; a false "no availability" is
 * replaced by the canonical offer; a booking confirmation without a booking is
 * never sent.
 *
 * Run: node packages/flows/definitions/code/test-availability-grounding.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const wf = JSON.parse(fs.readFileSync(
  path.join(__dirname, '..', 'beatriz-whatsapp-agent.json'), 'utf8'));
const nodes = Object.fromEntries(wf.nodes.map((n) => [n.name, n]));
const validateCode = nodes['Validate Enforce Reply'].parameters.jsCode;

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

function facts(overrides = {}) {
  const base = {
    turn: { intent: 'ask_availability', entities: {} },
    availability: {
      status: 'ready',
      missing: null,
      slots: [
        { id: 'F1', start_at: '2026-11-14T17:00:00Z', label: 'Sábado, 14/11 às 14h' },
        { id: 'F2', start_at: '2026-11-15T17:00:00Z', label: 'Domingo, 15/11 às 14h' },
      ],
    },
    pricing: { status: 'ready', base_cents: 150000, offered_cents: 150000, deposit_cents: 45000, promotion: null },
    conditions: { deposit_type: 'percent', deposit_value: 30, pix_available: true },
    booking: { active: false, start_at: null, created_this_turn: false },
    project: { placement: 'braco', body_zone: 'fechamento', style: 'blackwork', tattoo_type: 'new', first_tattoo: true, meaning: 'força' },
    client: { name: 'Ana', returning: false, aftercare: false },
    studio: { city: 'Poços de Caldas', timezone: 'America/Sao_Paulo' },
  };
  return Object.assign({}, base, overrides);
}

function run(factsObj, claims, reply = 'ok') {
  const $ = (name) => ({
    isExecuted: false,
    first: () => {
      if (name === 'Load Enforce Facts') return { json: { facts: factsObj } };
      if (name === 'AI Agent') return { json: { output: reply } };
      return { json: {} };
    },
    all: () => [],
  });
  const fn = new Function('$', '$input', validateCode);
  return fn($, { first: () => ({ json: { claims } }) })[0].json;
}

// ── Slot offers ──
console.log('=== Slot offers ===');
let r = run(facts(), [{ type: 'slot_offer', slot_id: 'F1' }], 'Tenho sábado às 14h.');
check('real slot is accepted', r.ok === true, JSON.stringify(r));
check('offered slot stored', r.offered_slots.length === 1 && r.offered_slots[0].id === 'F1', JSON.stringify(r.offered_slots));

r = run(facts(), [{ type: 'slot_offer', slot_id: 'F9' }], 'Tenho sexta às 9h.');
check('invented slot becomes canonical', r.resolution === 'canonical_slots', r.resolution);
check('canonical uses the real labels', /Sábado, 14\/11/.test(r.final_reply) && /Domingo, 15\/11/.test(r.final_reply), r.final_reply);
check('canonical stores the real offers', r.offered_slots.length === 2, JSON.stringify(r.offered_slots));

r = run(facts(), [
  { type: 'slot_offer', slot_id: 'F1' },
  { type: 'slot_offer', slot_id: 'F2' },
  { type: 'slot_offer', slot_id: 'F1' },
], 'Três opções.');
check('more than two offers becomes canonical', r.resolution === 'canonical_slots', r.resolution);

// ── Premature offer before the availability intent ──
console.log('=== Premature offer ===');
r = run(facts({ turn: { intent: 'qualify', entities: {} } }), [{ type: 'slot_offer', slot_id: 'F1' }], 'Tenho sábado às 14h.');
check('offer before the right phase is blocked', r.resolution === 'canonical_premature_slots', r.resolution);
check('no slot leaks in the canonical', !/Sábado, 14\/11/.test(r.final_reply), r.final_reply);

// ── No availability ──
console.log('=== No availability ===');
r = run(facts(), [{ type: 'no_availability' }], 'Não tenho horário.');
check('false no-availability is replaced', r.resolution === 'canonical_slots', r.resolution);
check('replacement offers real slots', /Sábado, 14\/11/.test(r.final_reply), r.final_reply);

r = run(facts({ availability: { status: 'no_slots', slots: [], missing: null } }), [{ type: 'no_availability' }], 'Não tenho horário nesse dia.');
check('real no-slots is accepted', r.ok === true, JSON.stringify(r));

r = run(facts({ availability: { status: 'no_slots', slots: [], missing: null } }), [{ type: 'slot_offer', slot_id: 'F1' }], 'Tenho sábado.');
check('slot offer with no slots is canonicalised', r.resolution === 'canonical_no_slots', r.resolution);
check('canonical asks another day', /Qual outro dia funciona/.test(r.final_reply), r.final_reply);

// ── Not ready ──
console.log('=== Not ready ===');
r = run(
  facts({ availability: { status: 'not_ready', slots: [], missing: 'local' } }),
  [{ type: 'slot_offer', slot_id: 'F1' }],
  'Tenho sábado.',
);
check('slot offer while not ready becomes a question', r.resolution === 'canonical_missing', r.resolution);
check('asks the missing placement', /Onde no corpo/.test(r.final_reply), r.final_reply);

r = run(
  facts({ availability: { status: 'not_ready', slots: [], missing: 'tamanho' } }),
  [{ type: 'no_availability' }],
  'A agenda está cheia.',
);
check('missing size asks size', /pequena, uma área média/.test(r.final_reply), r.final_reply);

// ── Day offers ──
console.log('=== Day offers ===');
r = run(facts(), [{ type: 'day_offer', date: '2026-11-14' }], 'Sábado funciona?');
check('real day is accepted', r.ok === true, JSON.stringify(r));
r = run(facts(), [{ type: 'day_offer', date: '2026-11-20' }], 'Sexta funciona?');
check('day without slots is canonicalised', r.resolution === 'canonical_slots', r.resolution);

// ── Booking ──
console.log('=== Booking ===');
r = run(facts(), [{ type: 'booking_confirmed' }], 'Fechado, reservei.');
check('confirmation without a booking is rejected', r.resolution === 'canonical_slots', r.resolution);
r = run(
  facts({ booking: { active: true, created_this_turn: true, start_at: '2026-11-14T17:00:00Z' } }),
  [{ type: 'booking_confirmed' }],
  'Fechado, reservei sábado às 14h.',
);
check('confirmation after a real booking is accepted', r.ok === true, JSON.stringify(r));

// ── Persisted state ──
console.log('=== Persisted state ===');
r = run(facts(), [{ type: 'slot_offer', slot_id: 'F2' }], 'Domingo às 14h.');
check('offered_slots is returned for chat_state', r.offered_slots.length === 1 && r.offered_slots[0].id === 'F2', JSON.stringify(r.offered_slots));

console.log(`\n${passes} passed, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
