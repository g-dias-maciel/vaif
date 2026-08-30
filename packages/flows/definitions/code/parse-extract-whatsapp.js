// Parse conversation to extract qualification fields from user's message
// and detect price/handoff from Beatriz's response.
// WhatsApp variant — reads from WAHA webhook payload instead of Telegram.

const lead = $('Upsert Lead').first().json;
const waInput = $('WAHA Webhook').first().json;
const agentOutput = $('AI Agent').first().json;

const userText = (waInput.body?.payload?.body || '').toLowerCase();
const beatrizText = (agentOutput.output || '').toLowerCase();

// ── 1. Extract qualification fields from user's message ──

function extractFromMap(text, lookupMap) {
  for (const [key, val] of lookupMap) {
    if (text.includes(key)) return val;
  }
  return null;
}

const placementMap = new Map([
  ['braço externo', 'braco_externo'], ['braco externo', 'braco_externo'],
  ['braço interno', 'braco_interno'], ['braco interno', 'braco_interno'],
  ['antebraço', 'antebraco'], ['antebraco', 'antebraco'],
  ['panturrilha', 'panturrilha'],
  ['tornozelo', 'tornozelo'],
  ['pescoço', 'pescoco'], ['pescoco', 'pescoco'],
  ['costela', 'costela'],
  ['costas', 'costas'],
  ['barriga', 'barriga'], ['abdomen', 'barriga'], ['abdômen', 'barriga'],
  ['ombro', 'ombro'],
  ['pulso', 'pulso'],
  ['braço', 'braco'], ['braco', 'braco'],
  ['peito', 'peito'],
  ['perna', 'perna'],
  ['coxa', 'coxa'],
  ['dedo', 'dedo'],
  ['mão', 'mao'], ['mao', 'mao'],
  ['pé', 'pe'], ['pe', 'pe'],
]);

function extractPlacement(text) {
  return extractFromMap(text, placementMap);
}

const zoneMap = new Map([
  ['fechamento', 'fechamento'], ['fechar', 'fechamento'], ['fechado', 'fechamento'],
  ['sleeve', 'fechamento'],
  ['inteiro', 'fechamento'], ['inteira', 'fechamento'],
  ['completo', 'fechamento'], ['completa', 'fechamento'],
  ['cobrir tudo', 'fechamento'],
  ['quase tudo', 'grande'], ['a maior parte', 'grande'], ['maior parte', 'grande'],
  ['grande', 'grande'],
  ['uma área média', 'medio'], ['area media', 'medio'], ['metade', 'medio'],
  ['médio', 'medio'], ['média', 'medio'], ['medio', 'medio'],
  ['só uma partinha', 'pequeno'], ['so uma partinha', 'pequeno'], ['partinha', 'pequeno'],
  ['um pedacinho', 'pequeno'], ['pedacinho', 'pequeno'],
  ['só um detalhe', 'pequeno'], ['so um detalhe', 'pequeno'],
  ['pequeno', 'pequeno'], ['pequena', 'pequeno'],
]);

function extractBodyZone(text) {
  return extractFromMap(text, zoneMap);
}

const styleMap = new Map([
  ['neo tradicional', 'neo_tradicional'], ['neo trad', 'neo_tradicional'],
  ['old school', 'old_school'], ['oldschool', 'old_school'],
  ['fine line', 'fine_line'], ['fineline', 'fine_line'],
  ['new school', 'new_school'],
  ['geométrico', 'geometrico'], ['geométrica', 'geometrico'], ['geometrico', 'geometrico'],
  ['pontilhismo', 'pontilhismo'],
  ['aquarela', 'aquarela'], ['aquarelado', 'aquarela'],
  ['realismo', 'realismo'],
  ['blackwork', 'blackwork'],
  ['tradicional', 'tradicional'],
  ['japonês', 'japones'], ['japones', 'japones'], ['oriental', 'oriental'],
  ['tribal', 'tribal'],
  ['floral', 'floral'],
]);

function extractStyle(text) {
  return extractFromMap(text, styleMap);
}

// First tattoo detection
function extractFirstTattoo(text) {
  if (/minha primeira|é minha 1ª|é a primeira|primeira tattoo|primeira tatu|nunca fiz|nunca tatuei|vai ser a primeira/.test(text)) return true;
  if (/já tenho|já fiz|já tatuei|tenho várias|tenho algumas|não é a primeira|segunda tattoo|terceira tattoo/.test(text)) return false;
  return null;
}

// Significado extraction
function extractSignificado(text) {
  const m = text.match(/(?:significa|significado é?|homenagem|lembrança|simboliza|representa|em memória|pra lembrar)(?::\s+|\s+)([^.]+)/);
  return m ? m[1].trim() : null;
}

// Tipo de tatuagem extraction (nova / cobertura / reforma)
function extractTipoTatuagem(text) {
  if (/cobertura|cobrir|cover[- ]?up|tatuagem por cima|por cima de(?:sta| uma)/.test(text)) return 'cobertura';
  if (/reforma|reformar|retocar|retoque|refazer|redesign|remodelar/.test(text)) return 'reforma';
  if (/tatuagem nova|nova tattoo|tattoo nova|fazer uma nova|uma nova tatuagem/.test(text)) return 'nova';
  return null;
}

// ── 2. Detect price in Beatriz's response ──

function detectPrice(text) {
  // Match R$ amounts in Beatriz's response
  const prices = [];
  const re = /r\$\s*([\d.]+)(?:[.,](\d{2}))?/g;
  let m;
  while ((m = re.exec(text)) !== null) {
    const reais = parseInt(m[1].replace(/\./g, ''), 10);
    const cents = m[2] ? parseInt(m[2], 10) : 0;
    prices.push(reais + cents / 100);
  }

  if (prices.length > 0) {
    // First price mentioned is typically the table/cash price
    // If there are two, the lower is the negotiated
    if (prices.length >= 2) {
      const sorted = [...prices].sort((a, b) => a - b);
      return { table_price: Math.max(...prices), negotiated_price: Math.min(...prices) };
    }
    return { table_price: prices[0], negotiated_price: prices[0] };
  }
  return null;
}

// ── 3. Detect handoff ──

function detectHandoff(beatrizText, userText) {
  if (/cobrir|cob[ei]rtura|tatuagem por cima|por cima de(?:sta| uma)/.test(userText)) {
    return { reason: 'cover_up' };
  }

  if (/reforma|reformar|retocar|retoque|refazer|redesign|remodelar/.test(userText)) {
    return { reason: 'reforma' };
  }

  if (/quero falar com o artista|quero falar direto|passa pro artista|fala com o artista/.test(userText)) {
    return { reason: 'lead_requested_artist' };
  }

  const handoffPatterns = [
    'passar direto pro', 'passar pro artista', 'falar com o artista',
    'vou te passar pro', 'vou passar pro', 'passar para o artista'
  ];

  for (const p of handoffPatterns) {
    if (beatrizText.includes(p)) return { reason: 'assistant_handoff' };
  }

  return null;
}

// ── 4. Detect deposit request ──

function detectDepositRequest(beatrizText) {
  if (/(?:pix|chave|sinal|depósito|deposito|transfer[êe]ncia|comprovante)/.test(beatrizText)
    && /(?:envi[ao]|manda|pass[ao]|encaminho)/.test(beatrizText)) {
    const re = /r\$\s*([\d.]+)(?:[.,](\d{2}))?/g;
    let m;
    let minPrice = null;
    while ((m = re.exec(beatrizText)) !== null) {
      const reais = parseInt(m[1].replace(/\./g, ''), 10);
      const cents = m[2] ? parseInt(m[2], 10) : 0;
      const val = reais + cents / 100;
      if (minPrice === null || val < minPrice) minPrice = val;
    }
    // Deposit amount is typically the smallest R$ value (30% of negotiated)
    return minPrice ? Math.round(minPrice * 100) : null;
  }
  return null;
}

// ── 5. Detect booking confirmation ──

function detectBooking(beatrizText) {
  if (/(?:agendado|agendamento|confirmado|marcado|marcamos|esperamos|te espero|data\s+\d|hor[aá]rio|dia\s+\d)/.test(beatrizText)
    && /(?:obrigad[ao]|valeu|confiança|confianca)/.test(beatrizText)) {
    const dateMatch = beatrizText.match(/dia\s+(\d{1,2}[\/\-]\d{1,2})/);
    return { scheduled: true, date_ref: dateMatch ? dateMatch[1] : null };
  }
  return null;
}

// ── 6. Run extraction ──

const placement = extractPlacement(userText);
const bodyZone = extractBodyZone(userText);
const style = extractStyle(userText);
const primeiraTatuagem = extractFirstTattoo(userText);
const significado = extractSignificado(userText);
const tipoTatuagem = extractTipoTatuagem(userText);
const priceDetected = detectPrice(beatrizText);
const handoffDetected = detectHandoff(beatrizText, userText);
const depositAmount = detectDepositRequest(beatrizText);
const bookingDetected = detectBooking(beatrizText);

// ── 7. Pipeline transitions ──

let newPipeline = lead.pipeline_status;
let eventType = null;
let priceUpdated = false;
let depositStatusVal = null;
let bookedDateVal = null;

if (lead.pipeline_status === 'novo' && (placement || style || bodyZone || primeiraTatuagem !== null)) {
  newPipeline = 'qualificando';
  eventType = 'pipeline_state_changed';
}

if (priceDetected && lead.pipeline_status === 'novo' || priceDetected && lead.pipeline_status === 'qualificando') {
  newPipeline = 'orcamento_enviado';
  eventType = 'quote_sent';
  priceUpdated = true;
}

if (depositAmount && (lead.pipeline_status === 'orcamento_enviado' || lead.pipeline_status === 'novo' || lead.pipeline_status === 'qualificando')) {
  newPipeline = 'aguardando_deposito';
  eventType = 'deposit_requested';
  depositStatusVal = 'aguardando_confirmacao';
}

if (bookingDetected && lead.pipeline_status !== 'agendado' && lead.pipeline_status !== 'fechado' && lead.pipeline_status !== 'perdido') {
  newPipeline = 'agendado';
  eventType = 'slot_booked';
  bookedDateVal = bookingDetected.date_ref;
}

if (handoffDetected && lead.pipeline_status !== 'aguardando_artista' && lead.pipeline_status !== 'fechado' && lead.pipeline_status !== 'perdido') {
  newPipeline = 'aguardando_artista';
  eventType = 'handoff_triggered';
}

// ── 8. Prices in cents for DB ──

const tablePriceCents = priceDetected ? Math.round(priceDetected.table_price * 100) : null;
const negotiatedPriceCents = priceDetected ? Math.round(priceDetected.negotiated_price * 100) : null;

return [{
  json: {
    lead_id: lead.id,
    pipeline_status: newPipeline,
    event_type: eventType,
    price_updated: priceUpdated,

    placement_val: placement || null,
    body_zone_val: bodyZone || null,
    style_val: style || null,
    primeira_tatuagem_val: primeiraTatuagem,
    significado_val: significado || null,
    tipo_tatuagem_val: tipoTatuagem || null,

    table_price_cents: tablePriceCents,
    negotiated_price_cents: negotiatedPriceCents,

    deposit_status_val: depositStatusVal,
    deposit_amount_cents: depositAmount,
    booked_date_val: bookedDateVal,
    session_duration_min_val: null,
    buffer_min_val: null,

    handoff_reason: handoffDetected ? handoffDetected.reason : null,

    agent_text: agentOutput.output || '',
    user_text: waInput.body?.payload?.body || '',

    price_extracted_raw: priceDetected,
    handoff_extracted_raw: handoffDetected,
  }
}];
