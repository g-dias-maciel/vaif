#!/usr/bin/env node
/**
 * Behavioural tests for Pass 7 — video and portfolio context.
 *
 * Videos are handled by the model in natural language; there is no robotic
 * canonical override. Portfolio is the artist's public page link, not an image
 * upload.
 *
 * Run: node packages/flows/definitions/code/test-context-grounding.js
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
    turn: { intent: 'qualify', entities: {} },
    availability: { status: 'no_slots', missing: null, slots: [] },
    pricing: { status: 'ready', base_cents: 150000, offered_cents: 150000, deposit_cents: 45000, promotion: null, open_quote: false },
    conditions: {
      deposit_type: 'percent', deposit_value: 30, pix_available: true,
      reschedule_hours: 48, cancellation_hours: 24,
      payment_methods: ['pix'], allow_hold_slot: false, guarantee_price: false,
    },
    booking: { active: false, start_at: null, created_this_turn: false },
    project: { placement: 'braco', body_zone: 'fechamento', style: 'blackwork', tattoo_type: 'new', first_tattoo: true, meaning: 'força' },
    client: { name: 'Ana', returning: false, aftercare: false },
    portfolio: { url: 'https://instagram.com/artist', instagram: '@artist' },
    studio: { city: 'Poços de Caldas', timezone: 'America/Sao_Paulo' },
  };
  return Object.assign({}, base, overrides);
}

function run(factsObj, preflight, claims, reply = 'ok') {
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

// ── Video is natural language, not a template ──
console.log('=== Video ===');
let r = run(facts(), { intent: 'qualify', entities: {}, flags: {} }, [], 'Entendi! Por aqui eu não consigo abrir vídeo. Me manda uma foto ou me conta em texto o que você quer?');
check('a natural video reply is accepted', r.ok === true, JSON.stringify(r));
check('no canonical video override exists', !validateCode.includes('canonical_video'), 'canonical_video found');
check('the reply is not rewritten', r.final_reply.startsWith('Entendi! Por aqui'), r.final_reply);

// ── Portfolio is a link, not a media send ──
console.log('=== Portfolio link ===');
r = run(facts(), { intent: 'qualify', entities: {}, flags: {} }, [], 'Dá uma olhada no trabalho dele aqui: https://instagram.com/artist');
check('a portfolio link reply is accepted', r.ok === true, JSON.stringify(r));
check('the reply is unchanged', r.final_reply.includes('instagram.com/artist'), r.final_reply);

// ── The prompt carries the link and no image pipeline exists ──
console.log('=== Wiring ===');
const promptCode = nodes['Build System Prompt'].parameters.jsCode;
check('the prompt facts include the portfolio URL', promptCode.includes("gport.url") && promptCode.includes('Portfólio: '));
check('no portfolio media nodes remain',
  !Object.keys(nodes).some((name) => name.includes('Portfolio')));
check('portfolio images are not part of the payload',
  !promptCode.includes('Portfolio: '), 'old image pipeline found');

console.log(`\n${passes} passed, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
