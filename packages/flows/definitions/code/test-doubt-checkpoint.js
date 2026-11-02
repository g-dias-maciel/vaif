#!/usr/bin/env node
/**
 * Behavioural test for the pre-price doubt checkpoint.
 *
 * Production bug: the process explanation began with a rhetorical "né?", which
 * the checkpoint guard counted as a real earlier question. The "aguardando
 * confirmação" flag was never persisted, so the lead's next "não" was ignored
 * and Beatriz asked the doubts question again instead of quoting.
 *
 * Run: node packages/flows/definitions/code/test-doubt-checkpoint.js
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

const PROCESS_REPLY =
  'Fazer uma tattoo com um significado pessoal é sempre especial, né? '
  + 'Então, agora que já temos todas as informações, vou te explicar como funciona o processo do Gabriel.\n\n'
  + 'Então, Kiok, o processo de criação do Gabriel acontece da seguinte forma: no dia da sua tatuagem, '
  + 'ele vai sentar junto com você, reservando os primeiros minutos para conversar e entender tudo que você '
  + 'deseja pra sua tatuagem, ouvir todas as suas ideias e entender todas as suas expectativas em relação a ela, '
  + 'tudo bem? Durante essa conversa, ele vai criar um projeto exclusivo junto com você. O objetivo é você ficar '
  + '100% satisfeito com o resultado da arte. Com a arte finalizada, ele vai tirar as medidas do local para fazer '
  + 'o encaixe perfeito no seu corpo e, aí sim, dar início à sua tatuagem.\n\n'
  + 'Mais alguma dúvida?';

function startTurn(overrides = {}) {
  return {
    stage_key: 'qualificando',
    placement: 'braco',
    body_zone: 'fechamento',
    style: 'realismo',
    tattoo_type: 'new',
    agent_state: {
      processo_explicado: false,
      duvidas_eliminadas: false,
      aguardando_confirmacao_duvidas: false,
      preco_liberado: false,
    },
    ...overrides,
  };
}

function runParse({ start, agentOutput, combinedText, classifier }) {
  const $ = (name) => ({
    first: () => {
      if (name === 'Start Turn') return { json: start };
      if (name === 'AI Agent') return { json: { output: agentOutput } };
      if (name === 'Debounce Resolve') return { json: { combined_text: combinedText } };
      if (name === 'Build System Prompt') {
        return {
          json: {
            pricing_rows: [{ placement: 'braco', body_zone: 'fechamento', table_price: 10000 }],
            offer: {},
          },
        };
      }
      if (name === 'Parse Audio') return { json: {} };
      if (name === 'Parse Reference Analysis') return { json: {} };
      if (name === 'Resolve Artist') return { json: { id: 'a1' } };
      return { json: {} };
    },
  });
  const input = { first: () => ({ json: { choices: [{ message: { content: JSON.stringify(classifier) } }] } }) };
  return new Function('$', '$input', parseCode)($, input)[0].json;
}

// ── 1. The process explanation must arm the checkpoint even with a "né?"
//       rhetorical opener and the "tudo bem?" inside the fixed process text.
console.log('=== Process explanation arms the checkpoint ===');
const turn1 = runParse({
  start: startTurn(),
  agentOutput: PROCESS_REPLY,
  combinedText: 'seria pessoal',
  classifier: {
    pipeline: 'qualificando',
    event: null,
    qualification: { placement: 'braco', body_zone: 'fechamento', style: 'realismo' },
    pricing: {},
    handoff: {},
    price_gate: { processo_explicado: true, duvidas_eliminadas: false },
  },
});
check('process detected', turn1.processo_explicado_val === true);
check('checkpoint is armed', turn1.aguardando_confirmacao_duvidas_val === true);
check('reply ends with the doubts question', /d[uú]vida\?$/.test(turn1.final_reply.trim()), turn1.final_reply);

// ── 2. The next burst ("legal / nao / tudo tranquilo") must clear the
//       checkpoint and produce the authoritative quote.
console.log('=== Burst answer clears the checkpoint ===');
const turn2 = runParse({
  start: startTurn({
    agent_state: {
      processo_explicado: true,
      duvidas_eliminadas: false,
      aguardando_confirmacao_duvidas: true,
      preco_liberado: false,
    },
  }),
  agentOutput: 'Que bom que está tudo tranquilo, Kiok! Agora vamos ao que interessa. '
    + 'Para o seu braço inteiro com o estilo realista, o preço fica R$ 100,00 à vista ou em até 6x de R$ 16,67 sem juros.',
  combinedText: 'legal\nnao\ntudo tranquilo',
  classifier: {
    pipeline: 'qualificando',
    event: null,
    qualification: {},
    pricing: { table_cents: 10000, nego_cents: 10000 },
    handoff: {},
    price_gate: { processo_explicado: true, duvidas_eliminadas: false },
  },
});
check('doubts cleared', turn2.duvidas_eliminadas_val === true);
check('checkpoint no longer pending', turn2.aguardando_confirmacao_duvidas_val === false);
check('quote is sent', /R\$\s*100,00/.test(turn2.final_reply), turn2.final_reply);
check('doubts question is not repeated', !/alguma d[uú]vida\?/i.test(turn2.final_reply), turn2.final_reply);
check('pipeline advances', turn2.stage_key === 'orcamento_enviado', turn2.stage_key);

// ── 3. A real follow-up doubt still blocks the quote. ──
console.log('=== A real doubt does not clear the checkpoint ===');
const turn3 = runParse({
  start: startTurn({
    agent_state: {
      processo_explicado: true,
      duvidas_eliminadas: false,
      aguardando_confirmacao_duvidas: true,
      preco_liberado: false,
    },
  }),
  agentOutput: 'Fica R$ 100,00 à vista.',
  combinedText: 'não\ndói muito?',
  classifier: {
    pipeline: 'qualificando',
    event: null,
    qualification: {},
    pricing: { table_cents: 10000, nego_cents: 10000 },
    handoff: {},
    price_gate: { processo_explicado: true, duvidas_eliminadas: true },
  },
});
check('doubts stay unresolved', turn3.duvidas_eliminadas_val === false);
check('checkpoint remains pending', turn3.aguardando_confirmacao_duvidas_val === true);
check('quote is blocked', !/R\$\s*\d/.test(turn3.final_reply), turn3.final_reply);

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
