#!/usr/bin/env node
/**
 * Behavioural tests for Pass 5 — conditions and promises.
 *
 * The agent may only state the policies the artist configured and may only
 * promise what the facts allow. Everything else is replaced by a safe reply.
 *
 * Run: node packages/flows/definitions/code/test-conditions-grounding.js
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

function facts(conditionOverrides = {}, pricingOverrides = {}) {
  return {
    turn: { intent: 'qualify', entities: {} },
    availability: { status: 'no_slots', missing: null, slots: [] },
    pricing: Object.assign({
      status: 'ready', base_cents: 150000, offered_cents: 150000,
      deposit_cents: 45000, promotion: null, open_quote: false,
    }, pricingOverrides),
    conditions: Object.assign({
      deposit_type: 'percent', deposit_value: 30, pix_available: true,
      reschedule_hours: 48, cancellation_hours: 24,
      payment_methods: ['pix', 'credit_card'],
      allow_hold_slot: false, guarantee_price: false,
    }, conditionOverrides),
    booking: { active: false, start_at: null, created_this_turn: false },
    project: { placement: 'braco', body_zone: 'fechamento', style: 'blackwork', tattoo_type: 'new', first_tattoo: true, meaning: 'força' },
    client: { name: 'Ana', returning: false, aftercare: false },
    studio: { city: 'Poços de Caldas', timezone: 'America/Sao_Paulo' },
  };
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

// ── Reschedule policy ──
console.log('=== Reschedule policy ===');
let r = run(facts(), [{ type: 'condition', key: 'reschedule', value: 'até 48h' }], 'Remarcação até 48h antes.');
check('configured reschedule accepted', r.ok === true, JSON.stringify(r));
r = run(facts(), [{ type: 'condition', key: 'reschedule', value: 'até 7 dias' }], 'Remarcação até 7 dias antes.');
check('wrong reschedule replaced', r.resolution === 'canonical_condition', r.resolution);
check('safe policy reply', /confirmar essa política/.test(r.final_reply), r.final_reply);
r = run(facts({ reschedule_hours: null }), [{ type: 'condition', key: 'reschedule', value: '48h' }], 'Até 48h.');
check('unconfigured reschedule rejected', r.violations.some(v => v.rule === 'condition_not_in_facts'), JSON.stringify(r.violations));

// ── Payment methods ──
console.log('=== Payment methods ===');
check('pix accepted', run(facts(), [{ type: 'condition', key: 'payment', value: 'PIX' }], 'Pode ser no PIX.').ok === true);
check('credit card accepted', run(facts(), [{ type: 'condition', key: 'payment', value: 'cartão de crédito' }], 'Aceitamos cartão.').ok === true);
r = run(facts(), [{ type: 'condition', key: 'payment', value: 'boleto' }], 'Pode ser boleto.');
check('unknown method rejected', r.resolution === 'canonical_condition', r.resolution);
check('payment reply asks the artist', /formas de pagamento/.test(r.final_reply), r.final_reply);
r = run(facts({ payment_methods: ['pix'] }), [{ type: 'condition', key: 'payment', value: 'cartão' }], 'Pode ser cartão.');
check('card rejected when only pix configured', r.resolution === 'canonical_condition', r.resolution);

// ── Cancellation ──
console.log('=== Cancellation ===');
check('configured cancellation accepted', run(facts(), [{ type: 'condition', key: 'cancellation', value: '24h' }], 'Cancelamento até 24h.').ok === true);
r = run(facts(), [{ type: 'condition', key: 'cancellation', value: '72h' }], 'Cancelamento até 72h.');
check('wrong cancellation replaced', r.resolution === 'canonical_condition', r.resolution);

// ── Promises ──
console.log('=== Promises ===');
r = run(facts(), [{ type: 'promise', key: 'hold_slot' }], 'Consigo segurar o horário.');
check('hold rejected by default', r.resolution === 'canonical_condition', r.resolution);
check('hold reply explains the signal', /segurar o horário sem o sinal/.test(r.final_reply), r.final_reply);
check('hold allowed when configured', run(facts({ allow_hold_slot: true }), [{ type: 'promise', key: 'hold_slot' }], 'Consigo segurar.').ok === true);
r = run(facts(), [{ type: 'promise', key: 'guarantee_price' }], 'Garanto esse preço.');
check('price guarantee rejected by default', r.resolution === 'canonical_condition', r.resolution);
check('guarantee allowed when configured', run(facts({ guarantee_price: true }), [{ type: 'promise', key: 'guarantee_price' }], 'Garanto.').ok === true);

// ── Discount promise ──
console.log('=== Discount ===');
r = run(facts({}, { base_cents: 150000, offered_cents: 135000 }), [{ type: 'promise', key: 'discount' }], 'Consigo um desconto.');
check('discount allowed with an approved lower quote', r.ok === true, JSON.stringify(r));
r = run(facts(), [{ type: 'promise', key: 'discount' }], 'Consigo um desconto.');
check('discount rejected without an approved lower quote', r.resolution === 'canonical_condition', r.resolution);

// ── Unknown condition ──
console.log('=== Unknown condition ===');
r = run(facts(), [{ type: 'condition', key: 'other', value: 'qualquer coisa' }], 'Temos uma regra especial.');
check('unknown condition rejected', r.resolution === 'canonical_condition', r.resolution);

console.log(`\n${passes} passed, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
