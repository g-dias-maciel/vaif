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
      if (name === 'Build System Prompt') return { json: { pricing_rows: [
        { placement: 'braco', body_zone: 'fechamento', table_price: 150000 },
      ] } };
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
check('reply replaced with a discovery question', /nova, uma cobertura ou uma reforma/.test(r.final_reply), r.final_reply);
check('no value leaked', !/R\$\s*\d/.test(r.final_reply), r.final_reply);
check('no doubts question before the process', !/d[uú]vida/.test(r.final_reply), r.final_reply);
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
  lead: { id: 'L3', pipeline_status: 'qualificando', processo_explicado: true, aguardando_confirmacao_duvidas: true, placement: 'braco', body_zone: 'fechamento' },
  agentOutput: 'Fica R$ 1.500 à vista ou 6x de R$ 250. Como fica esse valor para você?',
  classifier: gate({
    pipeline: 'orcamento_enviado',
    price_gate: { duvidas_eliminadas: true, processo_explicado: true },
    pricing: { table_cents: 150000, nego_cents: 150000 },
  }),
  combinedText: 'não',
});
check('first quote is sent from the authoritative price row', /R\$\s*1\.500/.test(r.final_reply), r.final_reply);
check('no violation', r.price_gate_violation === false);
check('pipeline → orcamento_enviado', r.pipeline_status === 'orcamento_enviado', r.pipeline_status);
check('pricing persisted', r.table_price_cents === 150000);
check('gate flag persisted on first quote', r.preco_liberado_val === true);

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

// ── D2. Sticky gate: preco_liberado persisted, process flag lost ──
console.log('=== Negotiation with sticky gate flag (processo_explicado=false) ===');
r = runParse({
  lead: { id: 'L4b', pipeline_status: 'qualificando', processo_explicado: false, preco_liberado: true },
  agentOutput: 'Se fechar agora faço R$ 1.200 em vez de R$ 1.500.',
  classifier: gate({ pipeline: 'orcamento_enviado', pricing: { table_cents: 150000, nego_cents: 120000 } }),
});
check('sticky gate keeps negotiation open', r.price_gate_violation === false);
check('reply kept', r.final_reply.includes('R$ 1.200'));

// ── D3. Regression: quote stage reached but processo_explicado flag false ──
console.log('=== Regression: negotiation after quote stage, no process flag ===');
r = runParse({
  lead: { id: 'L4c', pipeline_status: 'orcamento_enviado', processo_explicado: false, preco_liberado: false },
  agentOutput: 'Consigo fazer por R$ 1.200 à vista.',
  classifier: gate({ pipeline: 'orcamento_enviado', pricing: { table_cents: 150000, nego_cents: 120000 } }),
});
check('quote stage alone keeps the gate open', r.price_gate_violation === false);
check('no re-ask of the doubts question', r.final_reply.includes('R$ 1.200'));

// ── D4. First quote still blocked without process (gate not yet opened) ──
console.log('=== First quote with no process and no sticky flag still blocked ===');
r = runParse({
  lead: { id: 'L4d', pipeline_status: 'qualificando', processo_explicado: false, preco_liberado: false },
  agentOutput: 'Fica R$ 1.500 à vista.',
  classifier: gate({ pipeline: 'orcamento_enviado' }),
});
check('blocked before the gate opens', r.price_gate_violation === true);
check('reply is a discovery question, not the doubts question', /nova, uma cobertura ou uma reforma/.test(r.final_reply) && !/d[uú]vida/.test(r.final_reply), r.final_reply);

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

// ── H. Production reproduction: classifier misses orcamento_enviado ──
// Turn 1 sends the first price while the classifier proposes pipeline
// "qualificando" (the real bug). The gate must still open AND the pipeline
// must advance deterministically. Turn 2 is the negotiation after it.
console.log('=== Production case: first quote with classifier missing orcamento ===');
const turn1 = runParse({
  lead: { id: 'L8', pipeline_status: 'qualificando', processo_explicado: true, preco_liberado: false, aguardando_confirmacao_duvidas: true, placement: 'braco', body_zone: 'fechamento' },
  agentOutput: 'Perfeito! Agora, vamos falar sobre os valores. Para o fechamento no braço, fica R$ 1.500 à vista ou em até 6x de R$ 250 sem juros. Como fica esse valor para você?',
  classifier: gate({ pipeline: 'qualificando', price_gate: { duvidas_eliminadas: true }, pricing: { table_cents: 150000, nego_cents: 150000 } }),
  combinedText: 'não',
});
check('first quote not replaced', /R\$\s*1\.500/.test(turn1.final_reply), turn1.final_reply);
check('pipeline advanced to orcamento_enviado despite classifier', turn1.pipeline_status === 'orcamento_enviado', turn1.pipeline_status);
check('gate flag persisted', turn1.preco_liberado_val === true);

console.log('=== Production case: negotiation turn after that quote ===');
const turn2 = runParse({
  lead: { id: 'L8', pipeline_status: turn1.pipeline_status, processo_explicado: turn1.processo_explicado_val, preco_liberado: turn1.preco_liberado_val, duvidas_eliminadas: turn1.duvidas_eliminadas_val, aguardando_confirmacao_duvidas: turn1.aguardando_confirmacao_duvidas_val },
  agentOutput: 'Entendi, e é totalmente válido. Para ajudar, posso te oferecer uma condição especial: se você fechar agora, consigo fazer um desconto de 20%, ficando R$ 1.200 à vista ou em até 6x de R$ 200 sem juros. O que acha?',
  classifier: gate({ pipeline: 'qualificando', pricing: { table_cents: 150000, nego_cents: 120000 } }),
});
check('discount NOT replaced by the doubts question', turn2.final_reply.includes('R$ 1.200'), turn2.final_reply);
check('no violation on negotiation', turn2.price_gate_violation === false);
check('pipeline stays orcamento_enviado', turn2.pipeline_status === 'orcamento_enviado', turn2.pipeline_status);

// ── I. Doubt-loop enforcement (pre-price) ──
console.log('=== Doubt loop enforced while pre-price ===');
let d = runParse({
  lead: { id: 'L9', pipeline_status: 'qualificando', processo_explicado: true, preco_liberado: false },
  agentOutput: 'A dor varia de pessoa para pessoa. Se precisar de mais alguma informação, estou à disposição!',
  classifier: gate({ pipeline: 'qualificando' }),
});
check('generic sign-off gets the doubts question appended', /Mais alguma d[uú]vida\?$/.test(d.final_reply.trim()), d.final_reply);
check('original answer preserved', d.final_reply.includes('A dor varia de pessoa para pessoa'));

d = runParse({
  lead: { id: 'L9', pipeline_status: 'qualificando', processo_explicado: true, preco_liberado: false },
  agentOutput: 'A dor varia de pessoa para pessoa. Mais alguma dúvida?',
  classifier: gate({ pipeline: 'qualificando' }),
});
check('follow-up not duplicated', (d.final_reply.match(/Mais alguma d[uú]vida\?/gi) || []).length === 1, d.final_reply);

// Regression: the model signs off with the phrase "mais alguma dúvida" written
// as a STATEMENT instead of the question ("se tiver mais alguma dúvida, é só
// falar"). The old `askedDoubts` regex treated that as already-asked and left
// the reply ending in a statement. It must be stripped and replaced by the
// actual question.
d = runParse({
  lead: { id: 'L9', pipeline_status: 'qualificando', processo_explicado: true, preco_liberado: false },
  agentOutput: 'A dor é bem relativa e varia de pessoa para pessoa. Se você tiver mais alguma dúvida, é só falar!',
  classifier: gate({ pipeline: 'qualificando' }),
});
check('statement-form sign-off ends with the doubts question', /Mais alguma d[uú]vida\?$/.test(d.final_reply.trim()), d.final_reply);
check('sign-off statement is stripped, not merely followed by the question', !/é só falar|se (você )?tiver mais alguma/i.test(d.final_reply), d.final_reply);
check('answer text preserved', d.final_reply.includes('A dor é bem relativa'), d.final_reply);

d = runParse({
  lead: { id: 'L9', pipeline_status: 'qualificando', processo_explicado: true, preco_liberado: false },
  agentOutput: 'A dor varia de pessoa para pessoa. Qualquer coisa me chama.',
  classifier: gate({ pipeline: 'qualificando' }),
});
check('"qualquer coisa me chama" also stripped and replaced', /Mais alguma d[uú]vida\?$/.test(d.final_reply.trim()) && !/qualquer coisa me chama/i.test(d.final_reply), d.final_reply);

d = runParse({
  lead: { id: 'L9', pipeline_status: 'qualificando', processo_explicado: true, preco_liberado: false },
  agentOutput: 'Para braco_externo fechamento fica R$ 1.500 à vista.',
  classifier: gate({ pipeline: 'orcamento_enviado', price_gate: { duvidas_eliminadas: true }, pricing: { table_cents: 150000 } }),
});
check('price turn does NOT get the doubts question appended', !/Mais alguma d[uú]vida/i.test(d.final_reply), d.final_reply);

d = runParse({
  lead: { id: 'L9', pipeline_status: 'qualificando', processo_explicado: false, preco_liberado: false },
  agentOutput: 'Que legal! Qual estilo você tem em mente?',
  classifier: gate({ pipeline: 'qualificando' }),
});
check('discovery phase untouched (process not explained)', d.final_reply === 'Que legal! Qual estilo você tem em mente?', d.final_reply);

// ── Deterministic checkpoint: only clear doubts while the explicit checkpoint
// is pending. The classifier cannot open it, and a batched "não + quanto fica"
// must produce the authoritative quote rather than reopen the checkpoint or
// enter negotiation.
console.log('=== Deterministic doubt checkpoint and first quote ===');
d = runParse({
  lead: { id: 'L10', pipeline_status: 'qualificando', processo_explicado: true, preco_liberado: false,
    duvidas_eliminadas: false, aguardando_confirmacao_duvidas: false,
    placement: 'braco', body_zone: 'fechamento' },
  agentOutput: 'Perfeito, ficou R$ 1.500 à vista.',
  classifier: gate({ pipeline: 'orcamento_enviado', price_gate: { duvidas_eliminadas: true }, pricing: { table_cents: 150000 } }),
  combinedText: 'não',
});
check('classifier cannot clear doubts before checkpoint is pending', d.duvidas_eliminadas_val === false);
check('premature classifier quote is blocked', d.price_gate_violation === true && !/R\$\s*\d/.test(d.final_reply), d.final_reply);
check('checkpoint becomes pending only after the question is sent', d.aguardando_confirmacao_duvidas_val === true);
check('price gate is not persisted before a price is actually sent', d.preco_liberado_val === false);

d = runParse({
  lead: { id: 'L10', pipeline_status: 'qualificando', processo_explicado: true, preco_liberado: false,
    duvidas_eliminadas: false, aguardando_confirmacao_duvidas: true,
    placement: 'braco', body_zone: 'fechamento' },
  agentOutput: 'Entendi. Posso te fazer uma pergunta? É pelo valor ou tem mais alguma coisa te deixando em dúvida?',
  classifier: gate({ pipeline: 'qualificando', price_gate: { duvidas_eliminadas: false } }),
  combinedText: 'não\nquanto que fica?',
});
check('batched no + price request clears the pending checkpoint', d.duvidas_eliminadas_val === true);
check('first quote is generated deterministically from the price table', /R\$\s*1\.500,00/.test(d.final_reply), d.final_reply);
check('first quote cannot be replaced by model negotiation', !/objeção|dúvida\?/i.test(d.final_reply), d.final_reply);
check('price becomes liberated only after quote is sent', d.preco_liberado_val === true && d.pipeline_status === 'orcamento_enviado');
check('checkpoint is no longer pending', d.aguardando_confirmacao_duvidas_val === false);

d = runParse({
  lead: { id: 'L11', pipeline_status: 'qualificando', processo_explicado: true, preco_liberado: false,
    duvidas_eliminadas: false, aguardando_confirmacao_duvidas: true,
    placement: 'braco', body_zone: 'fechamento' },
  agentOutput: 'Fica R$ 1.500 à vista.',
  classifier: gate({ pipeline: 'orcamento_enviado', price_gate: { duvidas_eliminadas: true }, pricing: { table_cents: 150000 } }),
  combinedText: 'quanto que fica?',
});
check('price request alone does not clear pending doubts', d.duvidas_eliminadas_val === false);
check('price request alone cannot emit a quote', d.price_gate_violation === true && !/R\$\s*\d/.test(d.final_reply), d.final_reply);
check('pending checkpoint remains pending', d.aguardando_confirmacao_duvidas_val === true);

d = runParse({
  lead: { id: 'L11b', pipeline_status: 'qualificando', processo_explicado: true, preco_liberado: false,
    duvidas_eliminadas: false, aguardando_confirmacao_duvidas: true,
    placement: 'braco', body_zone: 'fechamento' },
  agentOutput: 'Fica R$ 1.500 à vista.',
  classifier: gate({ pipeline: 'orcamento_enviado', price_gate: { duvidas_eliminadas: true }, pricing: { table_cents: 150000 } }),
  combinedText: 'não\ndói muito?',
});
check('no followed by a new non-price question does not clear doubts', d.duvidas_eliminadas_val === false);
check('new doubt plus premature price remains blocked', d.price_gate_violation === true && !/R\$\s*\d/.test(d.final_reply), d.final_reply);

d = runParse({
  lead: { id: 'L12', pipeline_status: 'qualificando', processo_explicado: true, preco_liberado: false,
    duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
    placement: 'braco', body_zone: 'fechamento' },
  agentOutput: 'Entendi. Posso te fazer uma pergunta? É pelo valor ou tem mais alguma coisa te deixando em dúvida?',
  classifier: gate({ pipeline: 'qualificando' }),
  combinedText: 'quanto que fica?',
});
check('already-cleared lead does not return to doubt/negotiation loop', /R\$\s*1\.500,00/.test(d.final_reply) && !/dúvida\?/i.test(d.final_reply), d.final_reply);
check('cleared-but-unquoted lead is not marked price-liberated prematurely', d.preco_liberado_val === true && d.pipeline_status === 'orcamento_enviado');

d = runParse({
  lead: { id: 'L13', pipeline_status: 'orcamento_enviado', processo_explicado: true,
    preco_liberado: true, duvidas_eliminadas: true, table_price: 150000,
    negotiated_price: 120000, placement: 'braco', body_zone: 'fechamento' },
  agentOutput: 'Entendi. Posso te fazer uma pergunta? É pelo valor ou tem mais alguma coisa te deixando em dúvida?',
  classifier: gate({ pipeline: 'orcamento_enviado' }),
  combinedText: 'quanto que fica?',
});
check('separate follow-up price request repeats the current quote, not negotiation', /R\$\s*1\.200,00/.test(d.final_reply) && !/pergunta|deixando em dúvida/i.test(d.final_reply), d.final_reply);

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
