#!/usr/bin/env node
/**
 * Behavioural tests for the repair-once loop (Pass 5).
 *
 * A grounded reply with violations is rewritten once against the facts. If the
 * rewrite is valid, it is sent; if it still violates, the validator's canonical
 * fallback is used. The original violations remain in the audit.
 *
 * Run: node packages/flows/definitions/code/test-repair-loop.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const wf = JSON.parse(fs.readFileSync(
  path.join(__dirname, '..', 'beatriz-whatsapp-agent.json'), 'utf8'));
const nodes = Object.fromEntries(wf.nodes.map((n) => [n.name, n]));

const facts = {
  turn: { intent: 'ask_price', entities: {} },
  availability: { status: 'no_slots', missing: null, slots: [] },
  pricing: { status: 'ready', base_cents: 150000, offered_cents: 150000, deposit_cents: 45000, promotion: null, open_quote: false },
  conditions: {
    deposit_type: 'percent', deposit_value: 30, pix_available: true,
    reschedule_hours: 48, cancellation_hours: 24,
    payment_methods: ['pix', 'credit_card'], allow_hold_slot: false, guarantee_price: false,
  },
  booking: { active: false, start_at: null, created_this_turn: false },
  project: { placement: 'braco', body_zone: 'fechamento', style: 'blackwork', tattoo_type: 'new', first_tattoo: true, meaning: 'força' },
  client: { name: 'Ana', returning: false, aftercare: false },
  studio: { city: 'Poços de Caldas', timezone: 'America/Sao_Paulo' },
};

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

function evalNode(name, mocks, input = {}) {
  const $ = (node) => ({
    isExecuted: mocks[node] !== undefined,
    first: () => {
      if (!(node in mocks)) throw new Error('node not executed: ' + node);
      return { json: mocks[node] };
    },
    all: () => [],
  });
  const fn = new Function('$', '$input', nodes[name].parameters.jsCode);
  return fn($, { first: () => ({ json: input }) })[0].json;
}

const wrongReply = 'Fica R$ 1.200 à vista.';
const validation = evalNode('Validate Enforce Reply', {
  'Load Enforce Facts': { facts },
  'Parse Enforce Preflight': { intent: 'ask_price', entities: {}, flags: { has_video: false } },
  'AI Agent': { output: wrongReply },
}, { claims: [{ type: 'price', cents: 120000 }] });

console.log('=== Original validation ===');
check('wrong price is repairable', validation.ok === false && validation.repairable === true, JSON.stringify(validation));
check('original reply is preserved', validation.original_reply === wrongReply, validation.original_reply);

console.log('=== Build repair ===');
const repair = evalNode('Build Repair', {
  'Load Enforce Facts': { facts },
  'Validate Enforce Reply': validation,
  'AI Agent': { output: wrongReply },
});
check('repair request carries the facts', JSON.stringify(repair.body).includes('150000'), '');
check('repair request carries the violations', JSON.stringify(repair.body).includes('price_not_in_facts'), '');
check('repair request carries the original reply', JSON.stringify(repair.body).includes('1.200'), '');

console.log('=== Parse repaired reply ===');
const parsed = evalNode('Parse Repair', {}, {
  choices: [{ message: { content: JSON.stringify({ reply: 'Fica R$ 1.500 à vista.', claims: [{ type: 'price', cents: 150000 }] }) } }],
});
check('repaired reply parsed', parsed.reply === 'Fica R$ 1.500 à vista.', parsed.reply);
check('repaired claims parsed', parsed.claims.length === 1 && parsed.claims[0].cents === 150000, JSON.stringify(parsed.claims));

console.log('=== Validate repaired reply ===');
const repaired = evalNode('Validate Repaired Reply', {
  'Load Enforce Facts': { facts },
  'Parse Enforce Preflight': { intent: 'ask_price', entities: {}, flags: { has_video: false } },
}, parsed);
check('repaired reply is accepted', repaired.ok === true, JSON.stringify(repaired));
check('repaired claims are effective', repaired.effective_claims.length === 1, JSON.stringify(repaired.effective_claims));

console.log('=== Resolve ===');
const final = evalNode('Resolve Final Reply', {
  'Validate Enforce Reply': validation,
  'Validate Repaired Reply': repaired,
});
check('repaired reply wins', final.final_reply === 'Fica R$ 1.500 à vista.', final.final_reply);
check('resolution marks the repair', final.resolution === 'repaired', final.resolution);
check('original violations kept for audit', final.original_violations.length > 0, JSON.stringify(final.original_violations));

const originalOnly = evalNode('Resolve Final Reply', { 'Validate Enforce Reply': validation });
check('without a repair the canonical reply wins', originalOnly.final_reply === validation.final_reply, originalOnly.final_reply);
check('without a repair it is not marked repaired', originalOnly.repaired === false, String(originalOnly.repaired));

console.log(`\n${passes} passed, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
