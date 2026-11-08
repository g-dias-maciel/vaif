#!/usr/bin/env node
/**
 * Deterministic tests for the grounded-reply validator.
 *
 * Loads the generated workflow and evaluates the real "Validate Shadow Reply"
 * node with mocked facts/claims. The validator is the code half of the
 * grounded architecture: AI extracts claims, code compares them to facts.
 *
 * Run: node packages/flows/definitions/code/test-validator.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const wf = JSON.parse(fs.readFileSync(
  path.join(__dirname, '..', 'beatriz-whatsapp-agent.json'), 'utf8'));
const nodes = Object.fromEntries(wf.nodes.map((n) => [n.name, n]));
const validateCode = nodes['Validate Shadow Reply'].parameters.jsCode;

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
    availability: {
      status: 'ready',
      missing: null,
      slots: [
        { id: 'F1', start_at: '2026-11-14T17:00:00Z', label: 'Sábado, 14/11 às 14h' },
        { id: 'F2', start_at: '2026-11-15T17:00:00Z', label: 'Domingo, 15/11 às 14h' },
      ],
    },
    pricing: {
      status: 'ready',
      base_cents: 150000,
      offered_cents: 150000,
      deposit_cents: 45000,
      promotion: null,
    },
    conditions: { deposit_type: 'percent', deposit_value: 30, pix_available: true },
    booking: { active: false, start_at: null, created_this_turn: false },
    project: {
      placement: 'braco',
      body_zone: 'fechamento',
      style: 'blackwork',
      tattoo_type: 'new',
      first_tattoo: true,
      meaning: 'força',
    },
    client: { name: 'Ana', returning: false, aftercare: false },
    studio: { city: 'Poços de Caldas', address: 'Rua Oswaldo Cruz 135' },
  };
  return Object.assign({}, base, overrides);
}

function pre(overrides = {}) {
  return Object.assign({
    intent: 'qualify',
    entities: {},
    flags: { needs_human: false, wants_artist: false },
    confidence: 0.9,
  }, overrides);
}

function run(factsObj, preObj, claims, reply = 'ok') {
  const $ = (name) => ({
    isExecuted: false,
    first: () => {
      if (name === 'Shadow Facts') return { json: { facts: factsObj } };
      if (name === 'Parse Shadow Preflight') return { json: preObj };
      if (name === 'AI Agent') return { json: { output: reply } };
      return { json: {} };
    },
    all: () => [],
  });
  const fn = new Function('$', '$input', validateCode);
  return fn($, { first: () => ({ json: { claims } }) })[0].json;
}

const rules = (r) => r.violations.map((v) => v.rule);

// ── Price ──
console.log('=== Price ===');
check('table price is accepted', run(facts(), pre(), [{ type: 'price', cents: 150000 }]).ok);
check('invented price is rejected',
  rules(run(facts(), pre(), [{ type: 'price', cents: 120000 }])).includes('price_not_in_facts'));
check('price without a row is rejected',
  rules(run(facts({ pricing: { status: 'no_price_row', base_cents: null, offered_cents: null, deposit_cents: 0 } }), pre(), [{ type: 'price', cents: 150000 }])).includes('price_without_row'));
check('price before qualification is rejected',
  rules(run(facts({ project: { placement: null, body_zone: null, style: null, tattoo_type: 'new' } }), pre(), [{ type: 'price', cents: 150000 }])).includes('price_before_qualification'));
check('cover-up never quotes',
  rules(run(facts({ project: { placement: 'braco', body_zone: 'medio', style: 'fine_line', tattoo_type: 'cover_up' } }), pre({ intent: 'coverup', flags: { needs_human: true } }), [{ type: 'price', cents: 150000 }])).includes('coverup_never_quotes'));

// ── Slots ──
console.log('=== Slots ===');
check('offered slot F1 is accepted', run(facts(), pre(), [{ type: 'slot_offer', slot_id: 'F1' }]).ok);
check('invented slot is rejected',
  rules(run(facts(), pre(), [{ type: 'slot_offer', slot_id: 'F9' }])).includes('slot_not_offered'));
check('three slots are rejected',
  rules(run(facts(), pre(), [
    { type: 'slot_offer', slot_id: 'F1' },
    { type: 'slot_offer', slot_id: 'F2' },
    { type: 'slot_offer', slot_id: 'F1' },
  ])).includes('too_many_slots'));
check('valid day is accepted', run(facts(), pre(), [{ type: 'day_offer', date: '2026-11-14' }]).ok);
check('day without slots is rejected',
  rules(run(facts(), pre(), [{ type: 'day_offer', date: '2026-11-20' }])).includes('day_without_slots'));

// ── No availability ──
console.log('=== Availability claims ===');
check('no availability while slots exist is rejected',
  rules(run(facts(), pre(), [{ type: 'no_availability' }])).includes('availability_exists'));
check('no availability when the agenda is empty is accepted',
  run(facts({ availability: { status: 'no_slots', slots: [] } }), pre(), [{ type: 'no_availability' }]).ok);
check('slot offer while not_ready is rejected',
  rules(run(facts({ availability: { status: 'not_ready', missing: 'local', slots: [] } }), pre(), [{ type: 'slot_offer', slot_id: 'F1' }])).includes('availability_not_ready'));

// ── Deposit / booking ──
console.log('=== Deposit and booking ===');
check('deposit with a booking is accepted',
  run(facts({ booking: { active: true, created_this_turn: false, start_at: '2026-11-21T17:00:00Z' } }), pre(), [{ type: 'deposit', cents: 45000 }]).ok);
check('deposit without a booking is rejected',
  rules(run(facts(), pre(), [{ type: 'deposit', cents: 45000 }])).includes('deposit_without_booking'));
check('deposit mismatch is rejected',
  rules(run(facts({ booking: { active: true } }), pre(), [{ type: 'deposit', cents: 30000 }])).includes('deposit_mismatch'));
check('booking confirmation without a booking is rejected',
  rules(run(facts(), pre(), [{ type: 'booking_confirmed' }])).includes('booking_not_created'));
check('the slot just booked may be mentioned in the confirmation',
  run(facts({ booking: { active: true, created_this_turn: true, start_at: '2026-11-14T17:00:00Z' } }), pre(), [{ type: 'slot_offer', start_at: '2026-11-14T17:00:00Z' }]).ok);
check('booking confirmation after a booking is accepted',
  run(facts({ booking: { active: true, created_this_turn: true } }), pre(), [{ type: 'booking_confirmed' }]).ok);

// ── Questions ──
console.log('=== Questions ===');
check('asking a known style is rejected',
  rules(run(facts(), pre(), [{ type: 'question', field: 'style' }])).includes('question_known_field'));
check('asking a missing placement is accepted',
  run(facts({ project: { placement: null, body_zone: null, style: null, tattoo_type: 'new' } }), pre(), [{ type: 'question', field: 'placement' }]).ok);
check('asking the name is always allowed',
  run(facts(), pre(), [{ type: 'question', field: 'name' }]).ok);

// ── Conditions / promises / location / handoff ──
console.log('=== Conditions, promises, location, handoff ===');
check('deposit condition 30% is accepted',
  run(facts(), pre(), [{ type: 'condition', key: 'deposit', value: '30%' }]).ok);
check('unknown condition is rejected',
  rules(run(facts(), pre(), [{ type: 'condition', key: 'reschedule', value: '48h' }])).includes('condition_not_in_facts'));
check('hold-slot promise is rejected',
  rules(run(facts(), pre(), [{ type: 'promise', key: 'hold_slot' }])).includes('promise_not_allowed'));
check('correct city is accepted',
  run(facts(), pre(), [{ type: 'location', city: 'Poços de Caldas' }]).ok);
check('wrong city is rejected',
  rules(run(facts(), pre(), [{ type: 'location', city: 'São Paulo' }])).includes('location_mismatch'));
check('unnecessary handoff is rejected',
  rules(run(facts(), pre(), [{ type: 'handoff' }])).includes('handoff_not_needed'));
check('requested handoff is accepted',
  run(facts(), pre({ flags: { needs_human: true, wants_artist: false } }), [{ type: 'handoff' }]).ok);
check('aftercare handoff is accepted',
  run(facts(), pre({ intent: 'aftercare', flags: { needs_human: true } }), [{ type: 'handoff' }]).ok);
check('cover-up handoff is accepted',
  run(facts(), pre({ intent: 'coverup', flags: { needs_human: true } }), [{ type: 'handoff' }]).ok);

console.log(`\n${passes} passed, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
