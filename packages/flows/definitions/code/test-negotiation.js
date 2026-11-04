#!/usr/bin/env node
/**
 * Behavioural test for negotiated discounts on later turns.
 *
 * Production bug: Beatriz offered 20% off R$ 200; the lead said "pode ser".
 * The model replied "R$ 160", but the promotion-quote normaliser replaced it
 * with the R$ 200 promotion quote because the active offer was still live.
 *
 * The promotion normaliser must only run on the FIRST quote. After the gate is
 * open, a negotiated price is the model's (and the lead's) source of truth.
 *
 * Run: node packages/flows/definitions/code/test-negotiation.js
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

const ROWS = [
  { placement: 'braco_externo', body_zone: 'fechamento', table_price: 10000 },
  { placement: 'braco_interno', body_zone: 'fechamento', table_price: 10000 },
];
const OFFER = { promo_active: true, multiplier: 2, name: 'Leve 2 pelo valor de 1' };

function runParse({ start, leadText, agentOutput, classifier }) {
  const $ = (name) => ({
    first: () => {
      if (name === 'Start Turn') return { json: start };
      if (name === 'AI Agent') return { json: { output: agentOutput } };
      if (name === 'Debounce Resolve') return { json: { combined_text: leadText } };
      if (name === 'Build System Prompt') return { json: { pricing_rows: ROWS, offer: OFFER } };
      if (name === 'Parse Audio') return { json: {} };
      if (name === 'Parse Reference Analysis') return { json: {} };
      if (name === 'Resolve Artist') return { json: { id: 'a1', name: 'Gabriel', instagram_handle: '@teste', floor_pct: 80 } };
      return { json: {} };
    },
  });
  const input = { first: () => ({ json: { choices: [{ message: { content: JSON.stringify(classifier) } }] } }) };
  return new Function('$', '$input', parseCode)($, input)[0].json;
}

function classifier(pricing, extra = {}) {
  return {
    pipeline: 'orcamento_enviado',
    event: null,
    qualification: { placement: 'braco', body_zone: 'fechamento', style: 'realismo', first_tattoo: true },
    pricing,
    handoff: {},
    price_gate: { processo_explicado: true, duvidas_eliminadas: true },
    ...extra,
  };
}

// ── 1. The production case: 20% off after the first quote. ──
console.log('=== Negotiated discount after the quote is open ===');
let r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'braco',
    body_zone: 'fechamento',
    style: 'realismo',
    first_tattoo: true,
    table_price_cents: 20000,
    quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true,
      duvidas_eliminadas: true,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: true,
    },
  },
  leadText: 'pode ser',
  agentOutput: 'Legal, Danilo! Com o desconto, o valor fica R$ 160,00 à vista ou em até 6x de R$ 26,67 sem juros. Como fica esse valor para você?',
  classifier: classifier({ table_cents: 20000, nego_cents: 16000 }),
});
check('negotiated price is kept', /R\$\s*160,00/.test(r.final_reply), r.final_reply);
check('promotion price does not replace it', !/R\$\s*200,00/.test(r.final_reply), r.final_reply);
check('negotiated price is persisted as the offer', r.quote_offered_cents === 16000, String(r.quote_offered_cents));
check('stage stays orcamento_enviado', r.stage_key === 'orcamento_enviado', r.stage_key);
check('discount mentions the Instagram counterpart', /@teste/i.test(r.final_reply), r.final_reply);

// ── 1b. The classifier often reports the discounted amount as "table_cents"
//        (16000/16000). The counterpart must still be detected from the
//        artist's price table + active promotion.
console.log('=== Classifier reports the discounted price as table ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'braco',
    body_zone: 'fechamento',
    style: 'realismo',
    first_tattoo: true,
    table_price_cents: null,
    quoted_price_cents: null,
    agent_state: {
      processo_explicado: true,
      duvidas_eliminadas: true,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: true,
    },
  },
  leadText: 'seria o valor mesmo',
  agentOutput: 'Entendi! Se você fechar agora, fica R$ 160,00 à vista ou em até 6x de R$ 26,67 sem juros. O que você acha?',
  classifier: classifier({ table_cents: 16000, nego_cents: 16000 }),
});
check('counterpart still detected from the price table', /@teste/i.test(r.final_reply), r.final_reply);

// ── 2. The first quote still normalises the table reply to the promotion. ──
console.log('=== First quote still uses the promotion price ===');
r = runParse({
  start: {
    stage_key: 'qualificando',
    tattoo_type: 'new',
    placement: 'braco',
    body_zone: 'fechamento',
    style: 'realismo',
    first_tattoo: true,
    table_price_cents: null,
    quoted_price_cents: null,
    agent_state: {
      processo_explicado: true,
      duvidas_eliminadas: true,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: false,
    },
  },
  leadText: 'sem duvidas',
  agentOutput: 'Para o braço, fica R$ 100,00 à vista.',
  classifier: classifier({ table_cents: 10000, nego_cents: 10000 }),
});
check('first quote shows the promotion price', /R\$\s*200,00/.test(r.final_reply), r.final_reply);
check('promotion is persisted as the first offer', r.quote_offered_cents === 20000, String(r.quote_offered_cents));
check('first quote does not add the discount counterpart', !/@teste/i.test(r.final_reply), r.final_reply);

// ── 4. Never let a hesitating lead leave without a move. ──
console.log('=== Price hesitation is answered with a discount, not a goodbye ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'antebraco',
    body_zone: 'pequeno',
    style: 'fineline',
    first_tattoo: true,
    table_price_cents: 20000,
    quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true,
      duvidas_eliminadas: true,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: true,
    },
  },
  leadText: 'tenho que ver se esse valor rola',
  agentOutput: 'Claro, sem problemas! Fique à vontade, vou ficar aguardando seu retorno.',
  classifier: classifier({ table_cents: 20000, nego_cents: 20000 }, { objection: 'price' }),
});
check('offers a discount', /desconto/i.test(r.final_reply), r.final_reply);
check('uses the artist floor (20%)', /20%/.test(r.final_reply), r.final_reply);
check('asks the counterpart (Instagram post)', /posta uma foto|instagram/i.test(r.final_reply), r.final_reply);
check('records the discounted offer', r.quote_offered_cents === 16000, String(r.quote_offered_cents));
check('does not end with a goodbye', !/fico aguardando|fique à vontade/i.test(r.final_reply), r.final_reply);

console.log('=== Generic hesitation keeps the lead with a question ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'antebraco',
    body_zone: 'pequeno',
    style: 'fineline',
    first_tattoo: true,
    table_price_cents: 20000,
    quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true,
      duvidas_eliminadas: true,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: true,
    },
  },
  leadText: 'vou pensar',
  agentOutput: 'Tudo bem! Fico aguardando.',
  classifier: classifier({ table_cents: 20000, nego_cents: 20000 }, { objection: 'vague' }),
});
check('asks a question instead of closing', /\?/.test(r.final_reply), r.final_reply);
check('does not end with a goodbye', !/fico aguardando|tudo bem/i.test(r.final_reply), r.final_reply);

// ── 5. Diagnose before offering a condition. ──
console.log('=== Decision-maker: ask what the person needs ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'antebraco',
    body_zone: 'pequeno',
    style: 'fineline',
    first_tattoo: true,
    table_price_cents: 20000,
    quoted_price_cents: 20000,
    agent_state: { processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false, preco_liberado: true },
  },
  leadText: 'preciso ver com meu marido',
  agentOutput: 'Claro, fique à vontade.',
  classifier: classifier({ table_cents: 20000, nego_cents: 20000 }, { objection: 'decision' }),
});
check('asks what the other person needs', /o que essa pessoa precisa saber|o que ele precisa saber/i.test(r.final_reply), r.final_reply);
check('does not pre-assume price', !/condi[cç][aã]o especial|desconto/i.test(r.final_reply), r.final_reply);
check('never claims a slot is guaranteed', !/garantid/i.test(r.final_reply), r.final_reply);
check('never offers to hold', !/(?:posso|vou) segurar/i.test(r.final_reply), r.final_reply);
check('records the reason', r.chat_state.negotiation_open === true && r.chat_state.negotiation_reason === 'decision',
  JSON.stringify({ o: r.chat_state.negotiation_open, r: r.chat_state.negotiation_reason }));
check('starts at the diagnose stage', r.chat_state.negotiation_stage === 'diagnose', String(r.chat_state.negotiation_stage));
check('no colon, semicolon or dash', !/[:;]|\s[—–-]\s/.test(r.final_reply), r.final_reply);

console.log('=== Follow-up "ok" escalates to the concrete offer ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'antebraco',
    body_zone: 'pequeno',
    style: 'fineline',
    first_tattoo: true,
    table_price_cents: 20000,
    quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: true, negotiation_reason: 'decision', negotiation_stage: 'diagnose',
    },
  },
  leadText: 'ok',
  agentOutput: 'Perfeito! Fico aguardando seu retorno.',
  classifier: classifier({ table_cents: 20000, nego_cents: 20000 }),
});
check('makes the concrete offer', /desconto/i.test(r.final_reply), r.final_reply);
check('records the discounted offer', r.quote_offered_cents === 16000, String(r.quote_offered_cents));
check('moves to the close stage', r.chat_state.negotiation_stage === 'close', String(r.chat_state.negotiation_stage));
check('no colon, semicolon or dash', !/[:;]|\s[—–-]\s/.test(r.final_reply), r.final_reply);

console.log('=== Vague hesitation diagnoses first (no discount yet) ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'antebraco',
    body_zone: 'pequeno',
    style: 'fineline',
    first_tattoo: true,
    table_price_cents: 20000,
    quoted_price_cents: 20000,
    agent_state: { processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false, preco_liberado: true },
  },
  leadText: 'vou pensar',
  agentOutput: 'Tudo bem, fico aguardando.',
  classifier: classifier({ table_cents: 20000, nego_cents: 20000 }, { objection: 'vague' }),
});
check('asks the isolating question', /é o valor, é o horário|o que está te impedindo/i.test(r.final_reply), r.final_reply);
check('does not discount yet', !/desconto/i.test(r.final_reply), r.final_reply);

// ── 6. The discount applies to the quoted price, not the table. ──
console.log('=== Promotion: discount from the quoted price ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'antebraco',
    body_zone: 'medio',
    style: 'fine_line',
    first_tattoo: true,
    table_price_cents: 10000,
    quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: true, negotiation_reason: 'decision', negotiation_stage: 'diagnose',
    },
  },
  leadText: 'ta bem',
  agentOutput: 'Perfeito!',
  classifier: classifier({ table_cents: 10000, nego_cents: 20000 }, { objection: 'decision' }),
});
check('discounts the quoted price', /R\$\s*160,00 em vez de R\$\s*200,00/.test(r.final_reply), r.final_reply);
check('records base and offered correctly', r.quote_base_cents === 20000 && r.quote_offered_cents === 16000,
  JSON.stringify({ b: r.quote_base_cents, o: r.quote_offered_cents }));

console.log('=== No promotion: discount from the table price ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'antebraco',
    body_zone: 'medio',
    style: 'fine_line',
    first_tattoo: true,
    table_price_cents: 10000,
    quoted_price_cents: 10000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: true, negotiation_reason: 'decision', negotiation_stage: 'diagnose',
    },
  },
  leadText: 'ta bem',
  agentOutput: 'Perfeito!',
  classifier: classifier({ table_cents: 10000, nego_cents: 10000 }, { objection: 'decision' }),
});
check('discounts the table price', /R\$\s*80,00 em vez de R\$\s*100,00/.test(r.final_reply), r.final_reply);

// ── 7. Offering to hold the price gives the lead a way out. ──
console.log('=== Hold offers are replaced by urgency ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado',
    tattoo_type: 'new',
    placement: 'antebraco',
    body_zone: 'medio',
    style: 'fine_line',
    first_tattoo: true,
    table_price_cents: 20000,
    quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: true, negotiation_reason: 'decision', negotiation_stage: 'diagnose',
    },
  },
  leadText: 'preciso ver com meu marido',
  agentOutput: 'Claro! E se precisar, posso segurar o preço e o horário enquanto você conversa com ele. O valor e o agendamento ficarão garantidos nesse período.',
  classifier: classifier({ table_cents: 20000, nego_cents: 20000 }, { objection: 'decision' }),
});
check('does not offer to hold', !/posso segurar|garantidos nesse per[íi]odo/i.test(r.final_reply), r.final_reply);
check('states the condition', /vale para hoje/i.test(r.final_reply), r.final_reply);
check('never claims a slot', !/garantid/i.test(r.final_reply), r.final_reply);

// ── 8. A decision objection does not repeat the price. ──
console.log('=== Decision objection: no re-quote ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado', tattoo_type: 'new', placement: 'antebraco', body_zone: 'grande',
    style: 'fine_line', first_tattoo: true, table_price_cents: 10000, quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: true, negotiation_reason: 'decision', negotiation_stage: null,
    },
  },
  leadText: 'preciso confirmar o valor com ele',
  agentOutput: 'Perfeito! Fico à disposição.',
  classifier: classifier({ table_cents: 10000, nego_cents: 20000 }, { objection: 'decision', asks_for_price: true }),
  // stage null: first hesitation, so the reply is the diagnosis, not the offer
});
check('does not repeat the price', !/R\$\s*\d/.test(r.final_reply), r.final_reply);

console.log('=== Quote and objection are not concatenated ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado', tattoo_type: 'new', placement: 'antebraco', body_zone: 'grande',
    style: 'fine_line', first_tattoo: true, table_price_cents: 10000, quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: true, negotiation_reason: 'decision', negotiation_stage: 'diagnose',
    },
  },
  leadText: 'vou mostrar pra ele',
  agentOutput: 'Para uma tatuagem grande no antebraço, fica R$ 200,00 à vista. Como fica esse valor para você?',
  classifier: classifier({ table_cents: 10000, nego_cents: 20000 }, { objection: 'decision' }),
});
check('keeps the quote', /R\$\s*200,00/.test(r.final_reply), r.final_reply);
check('does not append the decision question', !/o que essa pessoa precisa saber/i.test(r.final_reply), r.final_reply);
check('a single question', (r.final_reply.match(/\?/g) || []).length === 1, r.final_reply);

console.log('=== A slot guarantee is never sent ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado', tattoo_type: 'new', placement: 'antebraco', body_zone: 'grande',
    style: 'fine_line', first_tattoo: true, table_price_cents: 10000, quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: true, negotiation_reason: 'decision', negotiation_stage: 'diagnose',
    },
  },
  leadText: 'preciso ver com meu namorado',
  agentOutput: 'Entendi! O horário e o valor ficam garantidos se você decidir fechar agora. Qualquer coisa me chama.',
  classifier: classifier({ table_cents: 10000, nego_cents: 20000 }, { objection: 'decision' }),
});
check('does not claim a guaranteed slot', !/garantid/i.test(r.final_reply), r.final_reply);

// ── 9. Greeting keeps the name question (no truncation) ──
console.log('=== Greeting keeps its questions ===');
r = runParse({
  start: {
    stage_key: 'qualificando', tattoo_type: 'new', placement: 'antebraco', body_zone: 'grande',
    style: 'fine_line', first_tattoo: false, table_price_cents: null, quoted_price_cents: null,
    agent_state: { processo_explicado: false, duvidas_eliminadas: false, aguardando_confirmacao_duvidas: false, preco_liberado: false },
  },
  leadText: 'oi quero uma tatuagem',
  agentOutput: 'Oi! Eu sou a Beatriz, assistente do Gabriel aqui no estúdio. Tudo bem? Vi que você quer uma tatuagem de flor de lótus. Antes de começarmos, como posso te chamar?',
  classifier: classifier({ table_cents: 0, nego_cents: 0 }),
});
check('keeps the name question', /qual o seu nome/i.test(r.final_reply), r.final_reply);

// ── 10. Deposit during a negotiation is stripped, not pushed ──
console.log('=== Deposit while hesitating is stripped ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado', tattoo_type: 'new', placement: 'antebraco', body_zone: 'grande',
    style: 'fine_line', first_tattoo: true, table_price_cents: 10000, quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: true, negotiation_reason: 'decision', negotiation_stage: null,
    },
  },
  leadText: 'preciso ver com meu namorado',
  agentOutput: 'Entendo, mas se fechar agora mantemos a cotação de R$ 200,00. O sinal para confirmar é de R$ 20,00, que será descontado do total. Isso garante seu horário!',
  classifier: classifier({ table_cents: 10000, nego_cents: 20000 }, { objection: 'decision' }),
});
check('no sinal in the reply', !/sinal|pix|R\$\s*20/i.test(r.final_reply), r.final_reply);
check('keeps the negotiation question', /o que essa pessoa precisa saber/i.test(r.final_reply), r.final_reply);
check('does not push the schedule', !/primeiro vamos escolher o hor[áa]rio/i.test(r.final_reply), r.final_reply);
check('no slot guarantee', !/garantid/i.test(r.final_reply), r.final_reply);

// ── 11. The model's own offer is never overwritten by a re-quote. ──
console.log('=== Model discount survives ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado', tattoo_type: 'new', placement: 'braco_externo', body_zone: 'fechamento',
    style: 'fine_line', first_tattoo: true, table_price_cents: 10000, quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: true, negotiation_reason: 'decision', negotiation_stage: 'diagnose',
    },
  },
  leadText: 'seria o preco',
  agentOutput: 'Entendi, Lilo. Se fechar hoje, eu consigo fazer por R$ 180,00 em vez de R$ 200,00. E esse valor ainda inclui a promoção. Fecho pra você agora?',
  classifier: classifier({ table_cents: 10000, nego_cents: 20000 }, { asks_for_price: true }),
});
check('keeps the model offer', /R\$\s*180,00/.test(r.final_reply), r.final_reply);
check('does not replace it with the quoted price', !/fica R\$\s*200,00 à vista/i.test(r.final_reply), r.final_reply);
check('moves to the close stage', r.chat_state.negotiation_stage === 'close', String(r.chat_state.negotiation_stage));

console.log('=== A silent model still gets the repeat quote ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado', tattoo_type: 'new', placement: 'braco_externo', body_zone: 'fechamento',
    style: 'fine_line', first_tattoo: true, table_price_cents: 10000, quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: false, negotiation_reason: null, negotiation_stage: null,
    },
  },
  leadText: 'quanto custa mesmo?',
  agentOutput: 'Claro!',
  classifier: classifier({ table_cents: 10000, nego_cents: 20000 }, { asks_for_price: true }),
});
check('repeats the quoted price', /R\$\s*200,00/.test(r.final_reply), r.final_reply);

// ── 12. A discount mentioned without a number becomes a concrete offer. ──
console.log('=== Dangling discount gets a number ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado', tattoo_type: 'new', placement: 'braco_externo', body_zone: 'fechamento',
    style: 'fine_line', first_tattoo: true, table_price_cents: 10000, quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: false, negotiation_reason: null, negotiation_stage: null,
    },
  },
  leadText: 'preciso conversar com meu marido',
  agentOutput: 'Entendi, Lilo! É super importante conversar com ele. O que exatamente você acha que ele pode querer saber? O desconto da promoção só vale se fechar agora.',
  classifier: classifier({ table_cents: 10000, nego_cents: 20000 }, { objection: 'decision' }),
});
check('gives the concrete value', /R\$\s*160,00/.test(r.final_reply), r.final_reply);
check('mentions the post counterpart', /posta uma foto|instagram/i.test(r.final_reply), r.final_reply);
check('urgency only with the value', /fechar agora/i.test(r.final_reply) && /R\$/.test(r.final_reply), r.final_reply);
check('does not repeat the dangling phrase', !/só vale se fechar agora\.$/i.test(r.final_reply), r.final_reply);

console.log('=== Decision reply has no deadline ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado', tattoo_type: 'new', placement: 'braco_externo', body_zone: 'fechamento',
    style: 'fine_line', first_tattoo: true, table_price_cents: 10000, quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: true, negotiation_reason: 'decision', negotiation_stage: null,
    },
  },
  leadText: 'preciso conversar com meu marido',
  agentOutput: 'Claro! Fique à vontade.',
  classifier: classifier({ table_cents: 10000, nego_cents: 20000 }, { objection: 'decision' }),
});
check('asks what the person needs', /o que essa pessoa precisa saber/i.test(r.final_reply), r.final_reply);
check('no deadline without a value', !/fechar agora|fechando hoje|vale para hoje/i.test(r.final_reply), r.final_reply);

// ── 13. A farewell with the price is still a farewell. ──
console.log('=== Farewell during negotiation is replaced ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado', tattoo_type: 'new', placement: 'braco_externo', body_zone: 'fechamento',
    style: 'fine_line', first_tattoo: true, table_price_cents: 10000, quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: true, negotiation_reason: 'decision', negotiation_stage: null,
    },
  },
  leadText: 'entendi\nvou falar com meu marido',
  agentOutput: 'Claro, Lilo! Pode falar com ele à vontade. Só te aviso que essa cotação de R$ 200,00 é válida até 11 de novembro de 2026. Se precisar conversar mais ou tiver alguma dúvida, estou aqui. 😊',
  classifier: classifier({ table_cents: 10000, nego_cents: 20000 }, { objection: 'decision' }),
});
check('does not let the lead go', !/pode falar com ele à vontade|estou aqui/i.test(r.final_reply), r.final_reply);
check('keeps the negotiation', /o que essa pessoa precisa saber/i.test(r.final_reply), r.final_reply);
check('drops the validity date', !/v[áa]lida at[ée]/i.test(r.final_reply), r.final_reply);

console.log('=== Farewell paraphrase with a validity price is replaced ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado', tattoo_type: 'new', placement: 'braco_externo', body_zone: 'fechamento',
    style: 'fine_line', first_tattoo: true, table_price_cents: 10000, quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: false, negotiation_reason: null, negotiation_stage: null,
    },
  },
  leadText: 'preciso falar com meu marido',
  agentOutput: 'Claro, Manu! Fica à vontade para conversar com ele. O importante é que você e ele estejam confortáveis com a decisão. \n\nSó lembrando que essa cotação de R$ 200,00 está válida até 11 de novembro de 2026. Se você precisar de mais alguma informação ou se houver alguma dúvida, estou por aqui!',
  classifier: classifier({ table_cents: 10000, nego_cents: 20000 }, { objection: 'decision' }),
});
check('replaces the paraphrased farewell', !/fica à vontade|estou por aqui|se você precisar/i.test(r.final_reply), r.final_reply);
check('asks what the other person needs', /o que essa pessoa precisa saber/i.test(r.final_reply), r.final_reply);
check('advances to diagnose', r.chat_state.negotiation_stage === 'diagnose', String(r.chat_state.negotiation_stage));

console.log('=== A second price decline gets installments, not the same offer ===');
r = runParse({
  start: {
    stage_key: 'orcamento_enviado', tattoo_type: 'new', placement: 'braco_externo', body_zone: 'fechamento',
    style: 'fine_line', first_tattoo: true, table_price_cents: 10000, quoted_price_cents: 20000,
    agent_state: {
      processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false,
      preco_liberado: true, negotiation_open: true, negotiation_reason: 'price', negotiation_stage: 'close',
    },
  },
  leadText: 'nao sei se consigo',
  agentOutput: 'Compreendo, Manu! Se você precisar de mais tempo para decidir, sem problema. Só me avise quando estiver pronta. E, se tiver alguma dúvida ou quiser conversar mais sobre a tatuagem, estou aqui!',
  classifier: classifier({ table_cents: 10000, nego_cents: 20000 }, { objection: 'price' }),
});
check('no wrong decision question', !/pessoa precisa saber/i.test(r.final_reply), r.final_reply);
check('no identical discount re-offer', !/20% de desconto/i.test(r.final_reply), r.final_reply);
check('offers the installments', /6x/i.test(r.final_reply), r.final_reply);
check('states the floor value', /R\$\s*160,00/.test(r.final_reply), r.final_reply);
check('stays at the close stage', r.chat_state.negotiation_stage === 'close', String(r.chat_state.negotiation_stage));

console.log('=== Greeting asks the name with the new wording ===');
r = runParse({
  start: {
    stage_key: 'novo', tattoo_type: null, placement: null, body_zone: null, style: null,
    first_tattoo: null, table_price_cents: null, quoted_price_cents: null,
    agent_state: { processo_explicado: false, duvidas_eliminadas: false, aguardando_confirmacao_duvidas: false, preco_liberado: false },
  },
  leadText: 'oi',
  agentOutput: 'Oi! Eu sou a Beatriz, assistente do Gabriel. Tudo bem? Antes de começarmos, como posso te chamar?',
  classifier: classifier({}),
});
check('uses "qual o seu nome"', /qual o seu nome/i.test(r.final_reply), r.final_reply);
check('never uses "como posso te chamar"', !/como posso te chamar/i.test(r.final_reply), r.final_reply);

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
