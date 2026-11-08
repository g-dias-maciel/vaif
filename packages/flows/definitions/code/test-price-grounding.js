#!/usr/bin/env node
/**
 * Behavioural tests for Pass 1/3 — price grounding in enforce mode.
 *
 * The enforce path no longer runs `Parse Classification`; the validator reads
 * the facts and the AI reply only. A price outside the facts is replaced by the
 * canonical table price.
 *
 * Run: node packages/flows/definitions/code/test-price-grounding.js
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
    turn: { intent: 'ask_price', entities: {} },
    availability: { status: 'no_slots', missing: null, slots: [] },
    pricing: { status: 'ready', base_cents: 150000, offered_cents: 150000, deposit_cents: 45000, promotion: null, open_quote: false },
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

// ── Price is correct ──
console.log('=== Correct price ===');
let r = run(facts({ pricing: { status: 'ready', base_cents: 150000, offered_cents: 150000, deposit_cents: 45000, promotion: null, open_quote: true } }), [{ type: 'price', cents: 150000 }], 'Fica R$ 1.500 à vista.');
check('accepted', r.ok === true, JSON.stringify(r));
check('reply unchanged', r.final_reply === 'Fica R$ 1.500 à vista.', r.final_reply);
check('quote already open -> no extra quote', r.needs_quote === false, String(r.needs_quote));

r = run(facts(), [{ type: 'price', cents: 150000 }], 'Fica R$ 1.500 à vista.');
check('valid price without a quote row -> needs_quote', r.needs_quote === true, String(r.needs_quote));

// ── A legitimate negotiated price from an open quote survives ──
console.log('=== Negotiated price ===');
r = run(
  facts({ pricing: { status: 'ready', base_cents: 150000, offered_cents: 135000, deposit_cents: 40500, promotion: null, open_quote: true } }),
  [{ type: 'price', cents: 135000 }],
  'Fica R$ 1.350 à vista.',
);
check('offered price accepted', r.ok === true, JSON.stringify(r));

// ── Wrong price is canonicalised ──
console.log('=== Wrong price ===');
r = run(facts(), [{ type: 'price', cents: 120000 }], 'Fica R$ 1.200 à vista.');
check('violation recorded', !r.ok && r.violations.some(v => v.rule === 'price_not_in_facts'), JSON.stringify(r.violations));
check('canonical price used', /1\.500/.test(r.final_reply), r.final_reply);
check('canonical marker', r.resolution === 'canonical_price', r.resolution);

// ── No price claim ──
console.log('=== No price claim ===');
r = run(facts(), [], 'Qual estilo você tem em mente?');
check('accepted without a price', r.ok === true && r.final_reply === 'Qual estilo você tem em mente?', JSON.stringify(r));

// ── Cover-up never quotes ──
console.log('=== Cover-up ===');
r = run(
  facts({ project: { placement: 'braco', body_zone: 'medio', style: 'fine_line', tattoo_type: 'cover_up' } }),
  [{ type: 'price', cents: 150000 }],
  'Fica R$ 1.500.',
);
check('cover-up violation', r.violations.some(v => v.rule === 'coverup_never_quotes'), JSON.stringify(r.violations));
check('cover-up keeps legacy handling', r.resolution === 'kept_legacy', r.resolution);

// ── No price row ──
console.log('=== Missing price row ===');
r = run(
  facts({ pricing: { status: 'no_price_row', base_cents: null, offered_cents: null, deposit_cents: 0, open_quote: false } }),
  [{ type: 'price', cents: 150000 }],
  'Fica R$ 1.500.',
);
check('missing row violation', r.violations.some(v => v.rule === 'price_without_row'), JSON.stringify(r.violations));
check('missing row keeps legacy handling', r.resolution === 'kept_legacy', r.resolution);

// ── Before qualification ──
console.log('=== Before qualification ===');
r = run(
  facts({ project: { placement: 'braco', body_zone: null, style: null, tattoo_type: 'new' } }),
  [{ type: 'price', cents: 150000 }],
  'Fica R$ 1.500.',
);
check('qualification violation', r.violations.some(v => v.rule === 'price_before_qualification'), JSON.stringify(r.violations));
check('canonical after qualification violation', r.resolution === 'canonical_price', r.resolution);

// ── Deposit ──
console.log('=== Deposit ===');
r = run(
  facts({ booking: { active: true, created_this_turn: true, start_at: '2026-11-14T17:00:00Z' } }),
  [{ type: 'deposit', cents: 45000 }],
  'O sinal fica R$ 450 no PIX.',
);
check('correct deposit accepted', r.ok === true, JSON.stringify(r));

r = run(
  facts({ booking: { active: true, created_this_turn: false, start_at: '2026-11-14T17:00:00Z' } }),
  [{ type: 'deposit', cents: 30000 }],
  'O sinal fica R$ 300.',
);
check('wrong deposit rejected', r.violations.some(v => v.rule === 'deposit_mismatch'), JSON.stringify(r.violations));
check('canonical deposit uses the facts amount', /450/.test(r.final_reply) && r.resolution === 'canonical_deposit', r.final_reply);

r = run(facts(), [{ type: 'deposit', cents: 45000 }], 'O sinal fica R$ 450.');
check('deposit without booking rejected', r.violations.some(v => v.rule === 'deposit_without_booking'), JSON.stringify(r.violations));

// ── The tool that let the model write any price is gone ──
console.log('=== Backend quote ownership ===');
check('Write Quote tool is not connected', !wf.connections['Write Quote']);
check('the agent has no data tools', !Object.values(wf.connections).some(c => c.ai_tool));
check('the apply step is idempotent',
  nodes['Apply Grounded Turn'].parameters.query.includes('apply_grounded_turn_once'));

console.log(`\n${passes} passed, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
