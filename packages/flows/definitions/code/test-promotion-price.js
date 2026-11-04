#!/usr/bin/env node
/**
 * Behavioural test for promotion pricing.
 *
 * With an active "leve 2" promotion the quoted price is the table price times
 * the promotion multiplier (the artist configures 2x = "pay double, second
 * tattoo up to the table value"). The model routinely quotes the table price
 * and merely mentions the promotion, which undercharges the pair. A quote the
 * lead already received is never raised (price protection), and the second
 * tattoo is confirmed by the artist — the agent must not schedule it.
 *
 * Run: node packages/flows/definitions/code/test-promotion-price.js
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

function run({ start, leadText, ai, offer, classifier }) {
  const $ = (name) => ({
    first: () => {
      if (name === 'Start Turn') return { json: start };
      if (name === 'AI Agent') return { json: { output: ai } };
      if (name === 'Debounce Resolve') return { json: { combined_text: leadText } };
      if (name === 'Build System Prompt') {
        return { json: { pricing_rows: [{ placement: 'panturrilha', body_zone: 'medio', table_price: 60000 }], offer } };
      }
      if (name === 'Resolve Artist') return { json: { id: 'a1', name: 'Adriano' } };
      return { json: {} };
    },
  });
  const input = { first: () => ({ json: { choices: [{ message: { content: JSON.stringify(classifier) } }] } }) };
  return new Function('$', '$input', parseCode)($, input)[0].json;
}

const PROMO = {
  promo_active: true,
  name: 'Leve 2 pelo valor de 1',
  multiplier: 2,
  open_quote: false,
  open_quote_first_price: null,
  open_quote_second_cap: null,
};
const NO_PROMO = { promo_active: false, multiplier: 0, open_quote: false };

const classifier = (overrides = {}) => ({
  pipeline: 'qualificando',
  event: null,
  qualification: {},
  pricing: {},
  handoff: { reason: null },
  price_gate: {},
  ...overrides,
});

function lead(overrides = {}) {
  return {
    stage_key: 'qualificando',
    placement: 'panturrilha',
    body_zone: 'medio',
    style: 'realismo',
    first_tattoo: true,
    tattoo_type: 'new',
    quoted_price_cents: null,
    agent_state: {
      processo_explicado: true,
      duvidas_eliminadas: true,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: false,
    },
    ...overrides,
  };
}

// ── 1. Model quotes the table price and mentions the promotion. ──
console.log('=== Table price + active promotion ===');
let r = run({
  start: lead(),
  leadText: 'qual o valor?',
  ai: 'Ficou R$ 600,00. Com a promoção Leve 2 você leva uma segunda tatuagem de mesmo valor.',
  offer: PROMO,
  classifier: classifier({ pricing: { table_cents: 60000, nego_cents: 60000 } }),
});
check('quotes the promotion price', /R\$\s*1\.200,00/.test(r.final_reply), r.final_reply);
check('does not quote the table price', !/R\$\s*600,00/.test(r.final_reply), r.final_reply);
check('records the promotion quote', r.offer_quoted === true, JSON.stringify({ q: r.offer_quoted }));
check('base is the table price', r.quote_base_cents === 60000, String(r.quote_base_cents));
check('offered is the promotion price', r.quote_offered_cents === 120000, String(r.quote_offered_cents));
check('second tattoo is confirmed by the artist, not scheduled', /não agendo a segunda/i.test(r.final_reply), r.final_reply);
check('offers the second as same size and free', /mesmo tamanho/i.test(r.final_reply) && /gr[áa]tis|de gra[çc]a/i.test(r.final_reply), r.final_reply);
check('never prices the second tattoo', !/segunda[^.]{0,60}R\$|valor at[ée]/i.test(r.final_reply), r.final_reply);

// ── 1b. The open-quote line carries no second-tattoo price either. ──
console.log('=== Open quote: no second price ===');
r = run({
  start: lead(),
  leadText: 'qual o valor?',
  ai: 'Ficou R$ 600,00. Com a promoção Leve 2 você leva uma segunda de mesmo valor.',
  offer: { ...PROMO, open_quote: true, open_quote_first_price: 60000, open_quote_second_cap: 60000 },
  classifier: classifier({ pricing: { table_cents: 60000, nego_cents: 60000 } }),
});
check('keeps the honoured price', /R\$\s*600,00/.test(r.final_reply), r.final_reply);
check('does not mention a value for the second', !/segunda[^.]{0,60}R\$|valor at[ée]/i.test(r.final_reply), r.final_reply);

// ── 2. A quote already sent is honoured, even when it is below the promotion. ──
console.log('=== Open quote price protection ===');
r = run({
  start: lead(),
  leadText: 'qual o valor?',
  ai: 'Ficou R$ 600,00. Com a promoção Leve 2 você leva uma segunda de mesmo valor.',
  offer: { ...PROMO, open_quote: true, open_quote_first_price: 60000, open_quote_second_cap: 60000 },
  classifier: classifier({ pricing: { table_cents: 60000, nego_cents: 60000 } }),
});
check('keeps the price the lead already received', /R\$\s*600,00/.test(r.final_reply), r.final_reply);
check('does not raise it to the promotion price', !/R\$\s*1\.200,00/.test(r.final_reply), r.final_reply);

// ── 3. Same protection when the lead asks again later. ──
console.log('=== Repeat with an open quote ===');
r = run({
  start: lead({
    stage_key: 'orcamento_enviado',
    quoted_price_cents: 60000,
    agent_state: { processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false, preco_liberado: true },
  }),
  leadText: 'quanto fica mesmo?',
  ai: 'Fica R$ 600,00 com a promoção.',
  offer: { ...PROMO, open_quote: true, open_quote_first_price: 60000 },
  classifier: classifier(),
});
check('repeats the honoured price', /R\$\s*600,00/.test(r.final_reply), r.final_reply);

// ── 4. Without an open quote the promotion price wins on a repeat too. ──
console.log('=== Repeat without an open quote ===');
r = run({
  start: lead({
    stage_key: 'orcamento_enviado',
    quoted_price_cents: null,
    agent_state: { processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false, preco_liberado: true },
  }),
  leadText: 'e o valor?',
  ai: 'Fica R$ 600,00 com a promoção Leve 2.',
  offer: PROMO,
  classifier: classifier(),
});
check('replaces the table price with the promotion price', /R\$\s*1\.200,00/.test(r.final_reply), r.final_reply);

// ── 5. The agent must not promise the second tattoo in the same session. ──
console.log('=== Second tattoo belongs to the artist ===');
r = run({
  start: lead({
    stage_key: 'orcamento_enviado',
    agent_state: { processo_explicado: true, duvidas_eliminadas: true, aguardando_confirmacao_duvidas: false, preco_liberado: true },
  }),
  leadText: 'meu primo pode fazer junto?',
  ai: 'Claro! Seu primo pode vir junto e fazer a tatuagem na mesma sessão, assim vocês aproveitam a promoção. Qual dia fica melhor?',
  offer: PROMO,
  classifier: classifier(),
});
check('no same-session promise', !/mesma sess[aã]o/i.test(r.final_reply), r.final_reply);
check('states the artist confirms the second', /confirmada direto com o Adriano/i.test(r.final_reply), r.final_reply);

// ── 6. No promotion: the table price is untouched. ──
console.log('=== No promotion ===');
r = run({
  start: lead(),
  leadText: 'qual o valor?',
  ai: 'Ficou R$ 600,00 à vista.',
  offer: NO_PROMO,
  classifier: classifier({ pricing: { table_cents: 60000, nego_cents: 60000 } }),
});
check('quotes the table price', /R\$\s*600,00/.test(r.final_reply), r.final_reply);
check('records a standard quote', r.offer_quoted === false && r.quote_offered_cents === 60000, JSON.stringify({ q: r.offer_quoted, o: r.quote_offered_cents }));

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
