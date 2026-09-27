#!/usr/bin/env node
/**
 * Behavioural test for the WhatsApp price gate (Beatriz).
 *
 * Loads the generated workflow, evaluates the "Parse Classification" jsCode
 * with mocked n8n globals ($, $input), and asserts the gate truth table:
 *
 *   - a price/PIX/sinal reply is allowed only once the creative process was
 *     explained AND (the lead cleared doubts this turn OR the quote stage was
 *     already reached);
 *   - a premature price reply is replaced by the safe doubts question and does
 *     not advance the pipeline or persist pricing.
 *
 * Run: node packages/flows/definitions/code/test-price-gate.js
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

function runParse({ lead, agentOutput, classifier, combinedText }) {
  // The Classify node returns an OpenRouter chat-completions response; the
  // parse node reads choices[0].message.content and JSON.parses it.
  const input = { json: { choices: [{ message: { content: JSON.stringify(classifier) } }] } };
  const $ = (name) => ({
    first: () => {
      if (name === 'Upsert Lead') return { json: lead };
      if (name === 'AI Agent') return { json: { output: agentOutput } };
      if (name === 'Debounce Resolve') return { json: { combined_text: combinedText || 'quero uma tatuagem nova' } };
      return { json: {} };
    },
    all: () => [],
  });
  const fn = new Function('$', '$input', parseCode);
  return fn($, { first: () => input })[0].json;
}

const DOUBTS_Q = 'Antes de falarmos de valores, ficou alguma dúvida?';

function gate(overrides) {
  return Object.assign({
    pipeline: null,
    qualification: {},
    pricing: {},
    price_gate: {},
    deposit: {},
    handoff: {},
    booking: {},
  }, overrides);
}

// ── A. Premature quote: process never explained ──
console.log('=== Premature quote before process ===');
let r = runParse({
  lead: { id: 'L1', pipeline_status: 'novo', processo_explicado: false },
  agentOutput: 'Fica R$ 1.500 à vista ou 6x de R$ 250.',
  classifier: gate({ pricing: { table_cents: 150000, nego_cents: 150000 } }),
});
check('reply replaced with the doubts question', r.final_reply === DOUBTS_Q, r.final_reply);
check('violation flagged', r.price_gate_violation === true);
check('pipeline not advanced', r.pipeline_status === 'novo', r.pipeline_status);
check('pricing not persisted', r.table_price_cents === null && r.negotiated_price_cents === null);

// ── B. Process explained, but lead has NOT cleared doubts ──
console.log('=== Quote after process but before doubts are cleared ===');
r = runParse({
  lead: { id: 'L2', pipeline_status: 'qualificando', processo_explicado: true },
  agentOutput: 'Fica R$ 1.500 à vista ou 6x de R$ 250.',
  classifier: gate({ pipeline: 'orcamento_enviado' }),
});
check('reply replaced', r.final_reply === DOUBTS_Q);
check('pipeline stays qualificando', r.pipeline_status === 'qualificando', r.pipeline_status);

// ── C. Legitimate first quote: process explained + doubts cleared this turn ──
console.log('=== Legitimate first quote ===');
r = runParse({
  lead: { id: 'L3', pipeline_status: 'qualificando', processo_explicado: true },
  agentOutput: 'Fica R$ 1.500 à vista ou 6x de R$ 250. Como fica esse valor para você?',
  classifier: gate({
    pipeline: 'orcamento_enviado',
    price_gate: { duvidas_eliminadas: true, processo_explicado: true },
    pricing: { table_cents: 150000, nego_cents: 150000 },
  }),
});
check('reply kept as-is', r.final_reply.includes('R$ 1.500'), r.final_reply);
check('no violation', r.price_gate_violation === false);
check('pipeline → orcamento_enviado', r.pipeline_status === 'orcamento_enviado', r.pipeline_status);
check('pricing persisted', r.table_price_cents === 150000);

// ── D. Negotiation turn: quote stage already reached ──
console.log('=== Negotiation after the gate is open ===');
r = runParse({
  lead: { id: 'L4', pipeline_status: 'orcamento_enviado', processo_explicado: true },
  agentOutput: 'Se fechar agora faço R$ 1.200 em vez de R$ 1.500.',
  classifier: gate({
    pipeline: 'orcamento_enviado',
    pricing: { table_cents: 150000, nego_cents: 120000 },
  }),
});
check('discount quote allowed', r.price_gate_violation === false);
check('reply kept', r.final_reply.includes('R$ 1.200'));
check('pipeline stays orcamento_enviado', r.pipeline_status === 'orcamento_enviado', r.pipeline_status);

// ── E. Deposit request after the gate is open ──
console.log('=== Deposit after the gate is open ===');
r = runParse({
  lead: { id: 'L5', pipeline_status: 'aguardando_deposito', processo_explicado: true },
  agentOutput: 'O sinal é R$ 450 e o PIX é pix@exemplo.com.',
  classifier: gate({ pipeline: 'aguardando_deposito', deposit: { amount_cents: 45000 } }),
});
check('deposit reply allowed', r.price_gate_violation === false);
check('deposit persisted', r.deposit_amount_cents === 45000 && r.deposit_status_val === 'aguardando_confirmacao');

// ── F. Premature deposit request ──
console.log('=== Premature deposit request ===');
r = runParse({
  lead: { id: 'L6', pipeline_status: 'qualificando', processo_explicado: true },
  agentOutput: 'O sinal é R$ 450 no PIX.',
  classifier: gate({ pipeline: 'aguardando_deposito', deposit: { amount_cents: 45000 } }),
});
check('premature deposit blocked', r.price_gate_violation === true);
check('deposit not persisted', r.deposit_amount_cents === null && r.deposit_status_val === null);
check('pipeline stays qualificando', r.pipeline_status === 'qualificando', r.pipeline_status);

// ── G. Non-price reply before the gate is not touched ──
console.log('=== Non-price reply before the gate ===');
r = runParse({
  lead: { id: 'L7', pipeline_status: 'qualificando', processo_explicado: false },
  agentOutput: 'Que legal! Qual estilo você tem em mente?',
  classifier: gate({}),
});
check('reply untouched', r.final_reply === 'Que legal! Qual estilo você tem em mente?');
check('no violation', r.price_gate_violation === false);

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
