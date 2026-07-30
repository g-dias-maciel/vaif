/**
 * Test the parse-extract-whatsapp.js field extraction logic.
 * Simulates the n8n Code node environment with WAHA webhook mock data.
 * Run: node packages/flows/definitions/code/test-parse-extract-whatsapp.js
 */

const fs = require('fs');
const path = require('path');
const code = fs.readFileSync(path.join(__dirname, 'parse-extract-whatsapp.js'), 'utf8');

function runExtraction(userText, beatrizText, leadData) {
  const mockData = {
    'WAHA Webhook': {
      event: 'message',
      session: 'bruno_tattoo',
      payload: {
        id: 'true_551199999@c.us_ABC123',
        from: '5511999999999@c.us',
        body: userText || '',
        type: 'chat',
        _data: { notifyName: 'Test User' },
      },
    },
    'AI Agent': {
      output: beatrizText || '',
    },
    'Upsert Lead': leadData || makeLead('novo'),
  };

  const $_ = function (nodeName) {
    const data = mockData[nodeName] || {};
    return {
      first() { return { json: data }; },
      item: { json: data },
    };
  };

  const fn = new Function('$', '$input', code);
  const result = fn($_, { first() { return { json: {} }; } });
  return result[0].json;
}

function makeLead(status) {
  return {
    id: `test-${Date.now()}`,
    pipeline_status: status || 'novo',
    nome: null, placement: null, body_zone: null, style: null,
    primeira_tatuagem: null, significado: null,
    table_price: null, negotiated_price: null,
  };
}

// ── Tests ──
let passed = 0;
let failed = 0;

function test(name, fn) {
  try {
    fn();
    passed++;
    console.log(`  ✓ ${name}`);
  } catch (e) {
    failed++;
    console.log(`  ✗ ${name}: ${e.message}`);
  }
}

function assert(condition, msg) {
  if (!condition) throw new Error(msg);
}

// ── Placement ──
test('placement "braço"', () => {
  const r = runExtraction('Quero tatuar o braço esquerdo', 'Qual estilo?');
  assert(r.placement_val === 'braco', `got ${r.placement_val}`);
});

test('placement "costas"', () => {
  const r = runExtraction('Quero fechar as costas', 'Massa!');
  assert(r.placement_val === 'costas', `got ${r.placement_val}`);
});

test('placement "antebraco"', () => {
  const r = runExtraction('No antebraço direito', 'Legal!');
  assert(r.placement_val === 'antebraco', `got ${r.placement_val}`);
});

// ── Style ──
test('style "realismo"', () => {
  const r = runExtraction('Quero no estilo realismo no braço', 'Adorei!');
  assert(r.style_val === 'realismo', `got ${r.style_val}`);
});

test('style "old school"', () => {
  const r = runExtraction('Old school no braço', 'Clássico!');
  assert(r.style_val === 'old_school', `got ${r.style_val}`);
});

// ── Body zone ──
test('body zone "grande"', () => {
  const r = runExtraction('Uma tattoo grande nas costas', 'Ótimo!');
  assert(r.body_zone_val === 'grande', `got ${r.body_zone_val}`);
});

test('body zone "fechamento"', () => {
  const r = runExtraction('Quero fechar o braço', 'Que projeto!');
  assert(r.body_zone_val === 'fechamento', `got ${r.body_zone_val}`);
});

test('placement precedence: "braço externo" before "braço"', () => {
  const r = runExtraction('Quero no braço externo', 'Legal! Qual estilo?');
  assert(r.placement_val === 'braco_externo', `got ${r.placement_val}`);
});

test('placement precedence: "antebraço" before "braço"', () => {
  const r = runExtraction('No antebraço direito', 'Entendi!');
  assert(r.placement_val === 'antebraco', `got ${r.placement_val}`);
});

test('zone precedence: "fechamento" before smaller zones', () => {
  const r = runExtraction('Quero um fechamento grande', 'Uau!');
  assert(r.body_zone_val === 'fechamento', `got ${r.body_zone_val}`);
});

test('body zone "pequeno"', () => {
  const r = runExtraction('Algo pequeno no pulso', 'Entendi!');
  assert(r.body_zone_val === 'pequeno', `got ${r.body_zone_val}`);
});

// ── First tattoo ──
test('first tattoo = true', () => {
  const r = runExtraction('Vai ser a primeira tattoo', 'Que legal!');
  assert(r.primeira_tatuagem_val === true, `got ${r.primeira_tatuagem_val}`);
});

test('first tattoo = false', () => {
  const r = runExtraction('Já tenho várias tattoos', 'Beleza!');
  assert(r.primeira_tatuagem_val === false, `got ${r.primeira_tatuagem_val}`);
});

// ── Significado ──
test('significado extraction', () => {
  const r = runExtraction('É em homenagem ao meu pai', 'Que lindo!');
  assert(r.significado_val != null, `got ${r.significado_val}`);
});

// ── Price detection ──
test('detects price in Beatriz response', () => {
  const r = runExtraction(
    'Quanto fica?',
    'O valor fica R$ 600 à vista ou 6x de R$ 120. Como fica para você?'
  );
  assert(r.price_updated === true, `price_updated=${r.price_updated}`);
  assert(r.table_price_cents === 60000, `table_price_cents=${r.table_price_cents}`);
  assert(r.pipeline_status === 'orcamento_enviado', `pipeline=${r.pipeline_status}`);
});

// ── Pipeline transitions ──
test('pipeline: novo -> qualificando', () => {
  const r = runExtraction(
    'Quero no braço, estilo realismo',
    'Ótima escolha! É sua primeira tatuagem?',
    makeLead('novo')
  );
  assert(r.pipeline_status === 'qualificando', `got ${r.pipeline_status}`);
  assert(r.event_type === 'pipeline_state_changed', `got ${r.event_type}`);
});

test('pipeline: stays qualificando', () => {
  const r = runExtraction(
    'Sim, primeira',
    'Fique tranquilo! Tem referência?',
    makeLead('qualificando')
  );
  assert(r.pipeline_status === 'qualificando', `got ${r.pipeline_status}`);
});

// ── Handoff ──
test('handoff: cover-up in user message', () => {
  const r = runExtraction(
    'Quero cobrir uma tatuagem antiga',
    'Cover-ups são bem específicos — vou te passar direto pro Bruno.',
    makeLead('novo')
  );
  assert(r.pipeline_status === 'aguardando_artista', `got ${r.pipeline_status}`);
  assert(r.event_type === 'handoff_triggered');
});

test('handoff: lead asks for artist', () => {
  const r = runExtraction(
    'quero falar com o artista direto',
    'Claro, vou te passar pro Bruno.',
    makeLead('qualificando')
  );
  assert(r.pipeline_status === 'aguardando_artista', `got ${r.pipeline_status}`);
  assert(r.handoff_reason === 'lead_requested_artist', `got ${r.handoff_reason}`);
});

// ── No false positives ──
test('greeting stays novo', () => {
  const r = runExtraction('Oi', 'Olá! Eu sou a Beatriz. Como posso te ajudar?', makeLead('novo'));
  assert(r.pipeline_status === 'novo', `got ${r.pipeline_status}`);
  assert(r.event_type === null, `got ${r.event_type}`);
});

test('no price in non-price response', () => {
  const r = runExtraction(
    'Quero tatuar o braço',
    'Legal! Qual estilo?',
    makeLead('qualificando')
  );
  assert(!r.price_updated, 'price_updated should be false');
});

// ── Field completeness ──
test('all output fields present', () => {
  const r = runExtraction('Oi', 'Olá!', makeLead('novo'));
  const fields = ['lead_id', 'pipeline_status', 'event_type', 'placement_val',
    'body_zone_val', 'style_val', 'primeira_tatuagem_val', 'significado_val',
    'table_price_cents', 'negotiated_price_cents', 'handoff_reason', 'agent_text'];
  for (const f of fields) {
    assert(f in r, `missing field: ${f}`);
  }
});

// ── Summary ──
console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) process.exit(1);
