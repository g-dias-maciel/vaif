#!/usr/bin/env python3
"""Build the Beatriz WhatsApp workflow JSON definition.

Core:
  Resolve Artist → Debounce (buffer + wait for burst) → Start Turn
    → Load Offer → Load Pricing → Build System Prompt
    → AI Agent (GPT-4o-mini + Postgres Chat Memory + tools)
    → Build Classification Prompt → Classify (OpenRouter) → Parse Classification
    → Build Project Patch → Patch Project → Ensure Quote / Write Promotion Quote
    → Patch Chat State → reply → Build Handoff Message → Send Notification?

Every database interaction goes through the v2 RPC surface (`resolve_artist`,
`chat_buffer_*`, `start_turn`, `patch_project`, `patch_chat_state`, ...): the
agent role has no domain table grants. Events are written by the RPCs, so the
old `Log Event` / Notion mirror nodes are gone.

WhatsApp/WAHA differences (transport + multi-tenancy only):
  - Trigger: WAHA webhook (n8n webhook, path /waha-webhook), responseMode
    onReceived (instant 200 ack; WAHA must not wait for the debounce window).
  - artist_id resolved from the WAHA session slug and threaded into every
    query explicitly (Telegram hardcodes one artist).
  - Reply: WAHA sendText (wahaApi credential) instead of Telegram.
  - Notifications: plain-text WAHA message to the artist's number via the
    VAIF marketing session (WhatsApp has no inline buttons on WAHA).
  - No callback branch (Telegram-only inline keyboard confirmations).
  - Dynamic system prompt rendered from the artist's own pricing/PIX/piso
    (the Telegram testbed hardcodes Bruno's values).

WhatsApp-only behaviours (no Telegram equivalent):
  - Route Event: message events go through the agent; presence.update events
    feed the debounce buffer's typing state so the agent never answers while
    the lead is still composing.
  - Subscribe Presence: best-effort per-chat WAHA presence subscription so
    typing events actually arrive.
  - Debounce is a quiet-period loop: a burst is answered only after a few
    seconds with no new message AND no active typing (with a safety cap).
  - Humanize delay + start/stopTyping: a short, length-scaled pause before the
    reply so the agent does not answer instantly.
  - Price gate: the creative-process explanation and the lead's explicit
    "no more doubts" are persisted/guarded so a quote can't be sent early.
"""
import json, os

BASE = os.path.dirname(os.path.abspath(__file__))
FLOWS_ROOT = os.path.dirname(BASE)  # packages/flows/

SYSTEM_PROMPT_TEMPLATE = open(os.path.join(FLOWS_ROOT, "prompts/beatriz-system.md")).read()
CLASSIFY_PROMPT_TEMPLATE = open(os.path.join(FLOWS_ROOT, "prompts/classify-conversation.md")).read()

# ── Credentials (n8n instance) ──
POSTGRES = {"postgres": {"id": "Fw3dgkcXV7Bblry5", "name": "agent-db"}}
CRM_INTERNAL = {"httpHeaderAuth": {"id": "Y5cnEcS1lT3hwZrS", "name": "crm-internal"}}
OPENROUTER = {"openRouterApi": {"id": "ow26hPDMir1dMfz0", "name": "OpenRouter account"}}
WAHA = {"wahaApi": {"id": "uQsr20PaYqbQUAGD", "name": "WAHA account"}}
# Shared header secret for the public n8n webhooks. WAHA sends it as
# `X-VAIF-Webhook-Token` (see packages/flows/docs/safe-testing.md).
WEBHOOK_AUTH = {"httpHeaderAuth": {"id": "9NTpZcrSRN7spEbE", "name": "VAIF Webhook Auth"}}
# Dedicated ops bot — receives the deposit/handoff confirm buttons (callback_query)
# handled by the "Beatriz Ops Callback" workflow. Kept separate from "Telegram
# account 2" (VAIF Agent) because Telegram allows one webhook per bot.
TELEGRAM_OPS = {"telegramApi": {"id": "pL4lbcexwUidxd1r", "name": "Beatriz Ops"}}

# ── Resolve Artist: session slug -> artist row, plus an explicit `found`
#    boolean. n8n's `isNotEmpty` returns TRUE for null, so a null-uuid row
#    would otherwise take the "found" branch. Gate on `found` instead. ──
RESOLVE_ARTIST_QUERY = """WITH found AS (
  SELECT r FROM resolve_artist($1) r
)
SELECT (r->'artist'->>'id')::uuid                                        AS id,
       COALESCE(NULLIF(r->'settings'->>'display_name', ''),
                r->'artist'->>'name')                                    AS name,
       r->'artist'->>'wa_session_slug'                                   AS wa_session_slug,
       r->'artist'->>'status'                                            AS status,
       r->'artist'->>'timezone'                                          AS timezone,
       COALESCE(r->'settings'->'ai_active_hours', '{}'::jsonb)           AS ai_active_hours,
       COALESCE(r->'settings'->'specialties', '[]'::jsonb)               AS specialties,
       COALESCE(r->'settings'->'excluded_styles', '[]'::jsonb)           AS excluded_styles,
       (r->'settings'->>'floor_pct')::numeric                            AS floor_pct,
       COALESCE(r->'settings'->>'deposit_type', 'percent')               AS deposit_type,
       (r->'settings'->>'deposit_value')::integer                        AS deposit_value,
       r->'settings'->>'pix_key'                                         AS pix_key,
       r->'settings'->>'instagram_handle'                                AS instagram_handle,
       r->'settings'->>'whatsapp_number'                                 AS whatsapp_number,
       r->'settings'->>'telegram_group_id'                               AS telegram_group_id,
       true                                                              AS found
FROM found
UNION ALL
SELECT NULL::uuid, $1::text, NULL::text, NULL::text, NULL::text,
       '{}'::jsonb, '[]'::jsonb, '[]'::jsonb, NULL::numeric, 'percent',
       NULL::integer, NULL::text, NULL::text, NULL::text, NULL::text,
       false
WHERE NOT EXISTS (SELECT 1 FROM found)
LIMIT 1;"""

# ── Dynamic system prompt: render beatriz-system.md template with the artist's
#    own data (name, PIX, Instagram, sinal, piso, price table). ──
BUILD_SYSTEM_PROMPT_JS = (
    "const artist = $('Resolve Artist').first().json;\n"
    "const pricing = $input.all().map(i => i.json).filter(r => r && r.placement);\n"
    "\n"
    "const formatBRL = (cents) => (cents / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });\n"
    "\n"
    "let table = '_Nenhuma tabela de preços cadastrada. Consulte o artista antes de informar valores._';\n"
    "if (pricing.length > 0) {\n"
    "  const rows = pricing.map(p => `| ${p.placement} | ${p.body_zone} | ${formatBRL(p.base_price_cents)} |`).join('\\n');\n"
    "  table = '| Local | Tamanho | À Vista |\\n|---|---|---|\\n' + rows;\n"
    "}\n"
    "\n"
    "const sinal = artist.deposit_type === 'fixed'\n"
    "  ? (artist.deposit_value != null ? 'R$ ' + Number(artist.deposit_value) : '30%')\n"
    "  : (artist.deposit_value != null ? Number(artist.deposit_value) + '%' : '30%');\n"
    "const piso = artist.floor_pct != null ? Number(artist.floor_pct) + '%' : '80%';\n"
    "const descontoMax = artist.floor_pct != null ? (100 - Number(artist.floor_pct)) + '%' : '20%';\n"
    "\n"
    "// ── Active promotion + open-quote price protection (from Load Offer) ──\n"
    "const offer = $('Load Offer').first().json || {};\n"
    "const promoActive = offer.promo_active === true;\n"
    "const openQuote = offer.open_quote_valid === true;\n"
    "const fmtOfferBRL = (cents) => (Number(cents || 0) / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });\n"
    "let offerBlock = '_Nenhuma promoção ativa no momento. Apresente apenas o preço normal da tabela._';\n"
    "if (promoActive) {\n"
    "  offerBlock = 'PROMOÇÃO ATIVA: \"' + (offer.name || 'promoção') + '\". ' + (offer.description || '') + '\\n'\n"
    "    + 'O preço a ser apresentado no orçamento JÁ é o preço da promoção (multiplicador ' + (Number(offer.multiplier) || 1) + 'x sobre a tabela). Apresente esse valor como o preço normal e, em seguida, mencione a promoção: nesse valor o lead leva UMA SEGUNDA tatuagem de valor IGUAL OU MENOR, que pode ser de OUTRA PESSOA e em OUTRO LOCAL. A segunda é agendada depois, com o artista. Se o lead pedir uma segunda mais cara que a primeira, passe para o artista.';\n"
    "}\n"
    "if (openQuote) {\n"
    "  offerBlock += '\\n\\nJá existe uma COTAÇÃO aberta para este lead (proteção de preço): primeira tatuagem ' + fmtOfferBRL(offer.open_quote_offered_cents) + ', segunda de valor até ' + fmtOfferBRL(offer.open_quote_second_cap) + ', válida até ' + String(offer.open_quote_valid_until || '').slice(0, 10) + '. HONRE essa cotação mesmo que a promoção tenha sido pausada.';\n"
    "}\n"
    "\n"
    "const replacements = {\n"
    "  '{{NOME}}': artist.name || 'Artista',\n"
    "  '{{INSTAGRAM}}': artist.instagram_handle || '@artista',\n"
    "  '{{PIX}}': artist.pix_key || '(informe a chave PIX)',\n"
    "  '{{SINAL}}': sinal,\n"
    "  '{{PISO}}': piso,\n"
    "  '{{DESCONTO_MAX}}': descontoMax,\n"
    "  '{{TABELA_PRECOS}}': table,\n"
    "  '{{OFFER}}': offerBlock,\n"
    "};\n"
    "\n"
    "let prompt = " + json.dumps(SYSTEM_PROMPT_TEMPLATE) + ";\n"
    "for (const [k, v] of Object.entries(replacements)) {\n"
    "  prompt = prompt.split(k).join(v);\n"
    "}\n"
    "\n"
    "return [{ json: { system_message: prompt, pricing_rows: pricing.map(p => ({ placement: p.placement, body_zone: p.body_zone, table_price: Number(p.base_price_cents) })), offer: { promo_active: promoActive, name: offer.name || null, description: offer.description || null, multiplier: Number(offer.multiplier) || 0, open_quote: openQuote, open_quote_first_price: Number(offer.open_quote_offered_cents) || null, open_quote_second_cap: Number(offer.open_quote_second_cap) || null } } }];"
)

# ── Agent text: per-turn context + the debounced lead message. Same shape as
#    the Telegram testbed, with artist_id resolved at runtime. ──
AGENT_TEXT_EXPR = (
    "={{ '[Contexto: pipeline=' + $('Start Turn').first().json.stage_key "
    "+ ' nome=' + ($('Start Turn').first().json.name || 'desconhecido') "
    "+ ' deposit=' + ($('Start Turn').first().json.deposit_status || 'nao_solicitado') "
    "+ ' tipo=' + (function(){ var t = $('Start Turn').first().json.tattoo_type; return ({new: 'nova', cover_up: 'cobertura', rework: 'reforma'})[t] || '?'; })() "
    "+ ' lead_id=' + $('Start Turn').first().json.lead_id "
    "+ ' project_id=' + $('Start Turn').first().json.project_id "
    "+ ' placement=' + ($('Start Turn').first().json.placement || '?') "
    "+ ' zona=' + ($('Start Turn').first().json.body_zone || '?') "
    "+ ' estilo=' + ($('Start Turn').first().json.style || '?') "
    "+ ' primeira_tatuagem=' + ($('Start Turn').first().json.first_tattoo === true ? 'sim' : ($('Start Turn').first().json.first_tattoo === false ? 'nao' : '?')) "
    "+ ' significado=' + ($('Start Turn').first().json.meaning || '?') "
    "+ ' referencia_visual=' + (function(){ var r = $json.reference_analysis || $('Start Turn').first().json.reference_analysis; if (!r) return '?'; var d = Array.isArray(r) ? r[r.length-1] : r; if (!d) return '?'; var p=[]; var subject = d.assunto || (d.resumo ? String(d.resumo).split(/(?<=[.!?])\s+/)[0] : ''); if (subject) p.push(subject.slice(0, 120)); if (d.estilo) p.push('estilo:'+d.estilo); if (d.cores) p.push('cores:'+d.cores); if (d.complexidade) p.push('complexidade:'+d.complexidade); if (d.cobertura && d.cobertura !== 'indefinido') p.push('cobertura:'+d.cobertura); if (d.confianca) p.push('confianca:'+d.confianca); return p.join(' | ') || '?'; })() "
    "+ ' processo_explicado=' + ((($('Start Turn').first().json.agent_state || {}).processo_explicado) ? 'sim' : 'nao') "
    "+ ' preco_liberado=' + ((($('Start Turn').first().json.agent_state || {}).preco_liberado) ? 'sim' : 'nao') "
    "+ ' duvidas_eliminadas=' + ((($('Start Turn').first().json.agent_state || {}).duvidas_eliminadas) ? 'sim' : 'nao') "
    "+ ' aguardando_confirmacao_duvidas=' + ((($('Start Turn').first().json.agent_state || {}).aguardando_confirmacao_duvidas) ? 'sim' : 'nao') "
    "+ ' primeira_mensagem=' + ($('Start Turn').first().json.is_new ? 'sim' : 'nao') "
    "+ ' preco_tabela=' + ($('Start Turn').first().json.table_price_cents || '?') "
    "+ ' preco_negociado=' + ($('Start Turn').first().json.quoted_price_cents || '?') "
    "+ ' sinal_reais=' + (function(){ var a = $('Resolve Artist').first().json || {}; var l = $('Start Turn').first().json || {}; var dv = a.deposit_value; if (dv === null || dv === undefined) return '?'; if (a.deposit_type === 'fixed') return String(dv); var base = l.quoted_price_cents || l.table_price_cents; if (!base) return '?'; return String(Math.ceil(Number(base) * Number(dv) / 100) / 100); })() "
    "+ ' oferta=' + (function(){ var o = $('Load Offer').first().json || {}; if (!o.promo_active && !o.open_quote_valid) return 'nenhuma'; var p = []; if (o.promo_active) p.push('ativa:' + (o.name || 'promocao') + ' x' + (Number(o.multiplier) || 1)); if (o.open_quote_valid) p.push('cotada:R$' + (Number(o.open_quote_offered_cents || 0) / 100).toFixed(2) + ' ate ' + String(o.open_quote_valid_until || '').slice(0, 10)); return p.join(' '); })() "
    "+ ' data_hoje=' + (function(){ try { return new Date().toLocaleDateString('en-CA', { timeZone: $('Resolve Artist').first().json.timezone || 'America/Sao_Paulo' }); } catch (e) { return new Date().toISOString().slice(0, 10); } })() "
    "+ '] [artist_id=' + $('Resolve Artist').first().json.id + '] ' "
    "+ (($json.transcript || '').toString().trim() || ($('Debounce Resolve').first().json.combined_text || '')) }}"
)

# ── Debounce Start: extract chat/phone/text from the WAHA message. chat_id is
#    keyed by session:phone so two artists never share a buffer row. ──
DEBOUNCE_START_JS = """const wa = $('WAHA Webhook').first().json.body || {};
const payload = wa.payload || {};
const session = wa.session || 'unknown';
const rawFrom = String(payload.from || '');
// WhatsApp's LID addressing sends `292...@lid`; the real phone is in
// `_data.key.remoteJidAlt`. Never store the LID as the phone number.
const key = (payload._data && payload._data.key) || {};
const altJid = String(key.remoteJidAlt || key.senderPn || '');
const phoneJid = rawFrom.endsWith('@lid') && altJid ? altJid : rawFrom;
const phone = phoneJid.replace(/@.*$/, '').replace(/\D/g, '');
const type = payload.type || 'chat';
const media = payload.media || {};
const message = (payload._data && payload._data.message) || {};
const rawText = String(payload.body || '');
const mediaMime = String(media.mimetype || payload.mimetype || '');
const isSticker = type === 'sticker' || payload.isSticker === true || media.isSticker === true
  || !!message.stickerMessage;
const isAudio = type === 'ptt' || type === 'audio' || !!message.audioMessage || !!message.pttMessage;
const isVideo = type === 'video' || !!message.videoMessage;
const isImage = !isSticker && (type === 'image' || !!message.imageMessage || mediaMime.startsWith('image/'));

let text = rawText;
if (!text) {
  if (isSticker) text = '[STICKER RECEBIDO]';
  else if (isAudio) text = '[ÁUDIO RECEBIDO]';
  else if (isVideo) text = '[VÍDEO RECEBIDO]';
  else if (isImage || payload.hasMedia) text = '[FOTO RECEBIDA]';
  else text = '[MÍDIA RECEBIDA]';
}

// Reference media (WAHA download URL) and links the lead sent. Audio, video
// and stickers are not references; only real images with a downloadable URL.
const mediaUrl = (isImage && payload.hasMedia && media.url) ? media.url : null;
const audioUrl = (isAudio && payload.hasMedia && media.url) ? media.url : null;

const links = Array.from(new Set((rawText.match(/https?:\\/\\/[^\\s]+/g) || [])
  .map(u => u.replace(/[.,;:!?)\\]]+$/, ''))))
  .slice(0, 5);

return [{ json: {
  chat_id: session + ':' + rawFrom,
  phone: phone,
  msg_text: text,
  msg_ts: Date.now(),
  media_url: mediaUrl,
  media_mime: mediaMime || null,
  audio_url: audioUrl,
  audio_mime: audioUrl ? (mediaMime || null) : null,
  links: links,
} }];"""

# Side branch after Start Turn: attach the inbound reference picture and any
# links to the Project. The CRM downloads WAHA media with its own API key.
BUILD_REFERENCES_JS = """const start = $('Debounce Start').first().json || {};
const turn = $('Start Turn').first().json || {};
const artist = $('Resolve Artist').first().json || {};

// One batch item, not one item per reference: the main path waits for the CRM
// to download/store the image before the vision step runs.
if (!turn.project_id || !artist.id) {
  return [{ json: { artist_id: artist.id || null, project_id: turn.project_id || null, references: [] } }];
}

const refs = [];
if (start.media_url) {
  refs.push({ url: start.media_url, mime: start.media_mime || null });
}
for (const link of (start.links || [])) {
  if (link && link !== start.media_url) {
    refs.push({ url: link, mime: null });
  }
}

return [{ json: {
  artist_id: artist.id,
  project_id: turn.project_id,
  references: refs.slice(0, 6),
} }];"""

# ── Vision: the customer's reference is downloaded by the CRM and exposed as a
#    public URL (`image_url`). OpenRouter analyzes it before the agent replies.
VISION_SYSTEM_PROMPT = """Você é um assistente de um estúdio de tatuagem. Analise a imagem que o cliente enviou como referência.
Responda SOMENTE com um objeto JSON válido, sem markdown, com estas chaves:
{
  "referencia_de_tatuagem": true ou false,
  "assunto": "2 a 5 palavras com o tema principal, sem detalhes (ex.: leão com bússola)",
  "resumo": "1 frase curta, no máximo 120 caracteres, sem descrever boca, dentes, olhos ou detalhes minuciosos",
  "estilo": "realismo | fineline | old school | blackwork | geométrico | aquarela | oriental | lettering | outro | indefinido",
  "cores": "preto e cinza | colorido | indefinido",
  "complexidade": "baixa | media | alta",
  "tipo": "desenho | foto_de_tatuagem | flash | outra",
  "cobertura": "sim | nao | talvez | indefinido",
  "confianca": "alta | media | baixa",
  "observacoes": "detalhes úteis, ou string vazia"
}
Não estime preço. Não invente detalhes que não dá para ver. Se a imagem não for uma tatuagem ou referência, marque referencia_de_tatuagem=false. Se for foto de uma tatuagem já existente, indique em observacoes se parece cobertura ou reforma."""

VISION_USER_PROMPT = """Analise esta imagem enviada pelo cliente e devolva apenas o JSON pedido. O cliente pode ter mandado um desenho, um flash, uma foto de tatuagem existente ou algo que não é referência."""

BUILD_VISION_REQUEST_JS = """const imageUrl = $('Save References').first().json.image_url;
const body = {
  model: 'openai/gpt-4o-mini',
  messages: [
    { role: 'system', content: __VISION_SYSTEM__ },
    { role: 'user', content: [
      { type: 'text', text: __VISION_USER__ },
      { type: 'image_url', image_url: { url: imageUrl } }
    ] }
  ],
  temperature: 0.2,
  max_tokens: 500
};
return [{ json: { body } }];"""
BUILD_VISION_REQUEST_JS = (BUILD_VISION_REQUEST_JS
    .replace('__VISION_SYSTEM__', json.dumps(VISION_SYSTEM_PROMPT))
    .replace('__VISION_USER__', json.dumps(VISION_USER_PROMPT)))

PARSE_REFERENCE_ANALYSIS_JS = """const response = $input.first().json || {};
const content = response.choices?.[0]?.message?.content || '';
let data = {};
try {
  data = JSON.parse(content);
} catch (e) {
  const match = String(content).match(/\{[\s\S]*\}/);
  if (match) {
    try { data = JSON.parse(match[0]); } catch (e2) {}
  }
}

const base = $('Build System Prompt').first().json.system_message;
const summary = String(data.assunto || data.resumo || '').split(/(?<=[.!?])\s+/)[0].trim();

if (!summary) {
  return [{ json: { system_message: base, reference_analysis: null } }];
}

const flag = (value) => value === false || value === 'nao' || value === 'não' ? 'não' : 'sim';
const lines = [
  '',
  '## Referência visual (análise automática)',
  'A imagem recebida foi analisada automaticamente. Use como apoio, não como verdade absoluta.',
  '- É referência de tatuagem: ' + flag(data.referencia_de_tatuagem),
  '- Referência: ' + summary,
];

if (data.estilo) lines.push('- Estilo provável: ' + data.estilo);
if (data.cores) lines.push('- Cores: ' + data.cores);
if (data.complexidade) lines.push('- Complexidade: ' + data.complexidade);
if (data.tipo) lines.push('- Tipo de imagem: ' + data.tipo);
if (data.cobertura) lines.push('- Cobertura/reforma: ' + data.cobertura);
if (data.confianca) lines.push('- Confiança da análise: ' + data.confianca);
if (data.observacoes) lines.push('- Observações: ' + data.observacoes);

lines.push('');
lines.push('Regras: na PRIMEIRA resposta após esta análise, reconheça a referência em uma frase curta usando o assunto (ex.: "um leão com bússola"); não descreva detalhes minuciosos como boca, dentes ou olhos; não aja como se não tivesse visto a imagem. Elogie apenas o que está na análise; se a confiança for baixa ou não for referência, peça outra foto; NUNCA use esta análise para calcular preço — o preço vem da tabela.');

const transcript = (() => {
  try { return $('No Audio').first().json.transcript || null; } catch (e) { return null; }
})();

return [{ json: {
  system_message: base + lines.join('\\n'),
  reference_analysis: data,
  transcript: transcript,
} }];"""

NO_REFERENCE_JS = """return [{ json: {
  system_message: $('Build System Prompt').first().json.system_message,
  reference_analysis: null,
  transcript: $json.transcript || null,
} }];"""

AUDIO_SYSTEM_PROMPT = """Você transcreve áudios de clientes de um estúdio de tatuagem. Responda SOMENTE com a transcrição literal em português brasileiro, sem comentários, sem markdown, sem aspas e sem rótulos. Se não houver fala inteligível, responda com uma string vazia."""

AUDIO_USER_PROMPT = """Transcreva este áudio."""

BUILD_AUDIO_REQUEST_JS = """const base = $('Build System Prompt').first().json.system_message;
const base64 = String($json.audio_base64 || '').trim();

if (!base64) {
  return [{ json: { body: null, system_message: base } }];
}

const mime = String($('Debounce Start').first().json.audio_mime || 'audio/ogg');
const format = mime.includes('mpeg') || mime.includes('mp3')
  ? 'mp3'
  : (mime.includes('mp4') || mime.includes('m4a') ? 'm4a' : (mime.includes('wav') ? 'wav' : 'ogg'));

const body = {
  model: 'google/gemini-2.5-flash',
  messages: [
    { role: 'system', content: __AUDIO_SYSTEM__ },
    { role: 'user', content: [
      { type: 'text', text: __AUDIO_USER__ },
      { type: 'input_audio', input_audio: { data: base64, format: format } }
    ] }
  ],
  temperature: 0,
  max_tokens: 800
};

return [{ json: { body, system_message: base } }];"""
BUILD_AUDIO_REQUEST_JS = (BUILD_AUDIO_REQUEST_JS
    .replace('__AUDIO_SYSTEM__', json.dumps(AUDIO_SYSTEM_PROMPT))
    .replace('__AUDIO_USER__', json.dumps(AUDIO_USER_PROMPT)))

PARSE_AUDIO_JS = """const base = $('Build System Prompt').first().json.system_message;
const response = $input.first().json || {};
let content = response.choices?.[0]?.message?.content || '';

if (Array.isArray(content)) {
  content = content.map((part) => (part && part.text) || '').join(' ');
}

const transcript = String(content)
  .replace(/^["'“”]+|["'“”]+$/g, '')
  .trim();

return [{ json: {
  system_message: base,
  transcript: transcript || null,
  reference_analysis: null,
} }];"""

NO_AUDIO_JS = """return [{ json: {
  system_message: $('Build System Prompt').first().json.system_message,
  transcript: null,
  reference_analysis: null,
} }];"""

DEBOUNCE_RESOLVE_JS = """const state = $('Get Buffer State').first().json || {};
const msgTs = $('Debounce Start').first().json.msg_ts;
const chatId = $('Debounce Start').first().json.chat_id;

const toMs = (v) => (v ? new Date(v).getTime() : 0);
const lastMsgAt = toMs(state.last_msg_at) || msgTs;
const lastTypingAt = toMs(state.last_typing_at);

// A newer message means another execution of this burst owns the reply.
const isStale = lastMsgAt > msgTs;

const now = Date.now();
// Answer only after this much silence (no new message, no typing).
const QUIET_MS = 6000;
// While WAHA reports the lead as actively typing, hold the reply. Cap the
// wait so a lost "paused" presence event can never stall the conversation.
const TYPING_GRACE_MS = 30000;

const typingActive = state.typing_state === 'typing'
  && (now - lastTypingAt) < TYPING_GRACE_MS;
const lastActivity = Math.max(lastMsgAt, lastTypingAt);
const quiet = (now - lastActivity) >= QUIET_MS;

const decision = isStale ? 'stop' : (typingActive || !quiet) ? 'wait' : 'process';
const combined = (state.pending || '').trim();

return [{ json: { decision: decision, is_stale: isStale, combined_text: combined, chat_id: chatId } }];"""

# ── Presence: WAHA sends presence.update with the lead's typing state. We only
#    record it against the debounce buffer so the resolve loop above can hold
#    the reply — no AI work happens on a presence event. ──
PRESENCE_START_JS = """const wa = $('WAHA Webhook').first().json.body || {};
const p = wa.payload || {};
const session = wa.session || 'unknown';
const chatId = String(p.id || '');
const presences = Array.isArray(p.presences) ? p.presences : [];

// Direct chats carry a single participant; take the last known presence.
let presence = null;
for (const item of presences) {
  if (item && item.lastKnownPresence) presence = item.lastKnownPresence;
}

const phone = chatId.replace(/@.*$/, '').replace(/\D/g, '');

return [{ json: {
  chat_id: session + ':' + chatId,
  presence: presence || 'paused',
  is_typing: presence === 'typing' || presence === 'recording',
  phone: phone,
  session: wa.session,
} }];"""

PRESENCE_UPSERT_QUERY = (
    "SELECT * FROM chat_buffer_typing($1::uuid, $2::text, $3::text);"
)

# ── Humanize: scale the pause before replying to the reply length, clamped so
#    it never feels instant nor sluggish. Deterministic (no Math.random) so the
#    behaviour is reproducible and testable. ──
HUMANIZE_DELAY_JS = """const out = ($('Parse Classification').first().json.final_reply || $('AI Agent').first().json.output || '').toString();
const words = out.trim().split(/\\s+/).filter(Boolean).length;

let secs = 0.8 + words / 3.2;              // ~3.2 palavras por segundo
secs = Math.max(1.5, Math.min(6, secs));   // nunca instantâneo, nunca arrastado
secs += (out.length % 7) / 10;             // variação leve e determinística

return [{ json: { delay_seconds: Math.round(secs * 10) / 10 } }];"""

# ── Split the reply into WhatsApp-sized paragraphs. Long messages get cut
#    off (and are hard to read); each paragraph becomes its own sendText call.
SPLIT_REPLY_JS = r"""const raw = ($('Parse Classification').first().json.final_reply || '').toString().trim();
const paragraphs = raw.split(/\n\s*\n+/).map(s => s.trim()).filter(Boolean);
const chunks = [];

for (const paragraph of paragraphs) {
  if (paragraph.length <= 700) {
    chunks.push(paragraph);
    continue;
  }

  // A very long paragraph is split at sentence boundaries, never mid-word.
  const sentences = paragraph.match(/[^.!?]+[.!?]+(?:\s+|$)/g) || [paragraph];
  let current = '';
  for (const sentence of sentences) {
    if (current && (current + ' ' + sentence).trim().length > 700) {
      chunks.push(current.trim());
      current = sentence;
    } else {
      current = (current + ' ' + sentence).trim();
    }
  }
  if (current) chunks.push(current.trim());
}

if (chunks.length === 0) {
  chunks.push('Desculpe, não consegui responder agora. Pode repetir?');
}

return chunks.map((text, index) => ({ json: { text, index, total: chunks.length } }));"""

# ── Classification: same prompt + parse as the Telegram testbed. ──
BUILD_CLASSIFICATION_PROMPT_JS = (
    "const currentPipeline = $('Start Turn').first().json.stage_key || 'novo';\n"
    "let audioTranscript = null;\n"
    "try { audioTranscript = $('Parse Audio').first().json.transcript || null; } catch (e) {}\n"
    "const userMsg = audioTranscript || $('Debounce Resolve').first().json.combined_text || '';\n"
    "const agentOutput = $('AI Agent').first().json.output || '';\n"
    "\n"
    "const sysPrompt = " + json.dumps(CLASSIFY_PROMPT_TEMPLATE) + ";\n"
    "const filled = sysPrompt.split('{{pipeline}}').join(currentPipeline);\n"
    "\n"
    "return [{ json: {\n"
    "  body: {\n"
    "    model: 'openai/gpt-4o-mini',\n"
    "    temperature: 0,\n"
    "    messages: [\n"
    "      { role: 'system', content: filled },\n"
    "      { role: 'user', content: 'Lead: ' + userMsg + '\\n\\nBeatriz: ' + agentOutput }\n"
    "    ]\n"
    "  }\n"
    "} }];"
)

PARSE_CLASSIFICATION_JS = """const resp = $input.first().json;
const content = (resp.choices && resp.choices[0] && resp.choices[0].message && resp.choices[0].message.content) || '{}';
let parsed;
try { parsed = JSON.parse(content); } catch(e) { parsed = {}; }

const q = parsed.qualification || {};
const p = parsed.pricing || {};
const d = parsed.deposit || {};
const h = parsed.handoff || {};
const b = parsed.booking || {};
const g = parsed.price_gate || {};
const lead = $('Start Turn').first().json;
const state = lead.agent_state || {};
const agentOutput = $('AI Agent').first().json.output || '';

// ── Deterministic tipo_tatuagem guard ──
let audioTranscript = null;
try { audioTranscript = $('Parse Audio').first().json.transcript || null; } catch (e) {}
const rawLead = (audioTranscript || $('Debounce Resolve').first().json.combined_text || '').toLowerCase();
const saysNew = /tatuagem nova|nova tattoo|tattoo nova|fazer uma nova|uma nova tatuagem/.test(rawLead);
const negativeCover = /n[aã]o\s+(?:é|eh|e|quero|vou|ser)\s+(?:um\s+)?(?:cover|cobertura|cobrir)|n[aã]o\s+[^.]{0,30}\b(?:cover|cobertura)\b/.test(rawLead);
const negativeRework = /n[aã]o\s+(?:é|eh|e|quero|vou|ser)\s+(?:um\s+)?(?:reforma|reformar|retocar|retoque)|n[aã]o\s+[^.]{0,30}\b(?:reforma|retoque)\b/.test(rawLead);
const leadSaysCover = /cobertura|cobrir|cover[- ]?up|tatuagem por cima|por cima de(?:sta| uma)/.test(rawLead) && !negativeCover;
const leadSaysRework = /reforma|reformar|retocar|retoque|refazer|redesign|remodelar/.test(rawLead) && !negativeRework;
const leadRequestedArtist = /(?:falar|chamar|conversar|passar).{0,20}(?:artista|gabriel|tatuador)|(?:quero|prefiro).{0,20}(?:artista|tatuador)/i.test(rawLead);

function detectTipo(text) {
  if (/cobertura|cobrir|cover[- ]?up|tatuagem por cima|por cima de(?:sta| uma)/.test(text)) return 'cobertura';
  if (/reforma|reformar|retocar|retoque|refazer|redesign|remodelar/.test(text)) return 'reforma';
  if (/tatuagem nova|nova tattoo|tattoo nova|fazer uma nova|uma nova tatuagem/.test(text)) return 'nova';
  return null;
}
let tipoDeterministic = detectTipo(rawLead);
if (saysNew && (negativeCover || negativeRework)) {
  tipoDeterministic = 'nova';
}
// The schema stores English enum values; the prompt/classifier vocabulary is PT.
const TIPO_DB = { nova: 'new', cobertura: 'cover_up', reforma: 'rework' };

// ── Deterministic qualification extraction (fills classifier gaps so the
//    lead's answers are persisted reliably and never "forgotten"). ──
function normalizeText(t) {
  return String(t || '').toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .replace(/_/g, ' ');
}
function detectPlacement(t) {
  const text = normalizeText(t);
  const map = [
    ['braço externo', 'braco_externo'], ['braco externo', 'braco_externo'],
    ['braço interno', 'braco_interno'], ['braco interno', 'braco_interno'],
    ['antebraço', 'antebraco'], ['antebraco', 'antebraco'],
    ['panturrilha', 'panturrilha'], ['tornozelo', 'tornozelo'],
    ['pescoço', 'pescoco'], ['pescoco', 'pescoco'],
    ['costela', 'costela'], ['costas', 'costas'],
    ['barriga', 'barriga'], ['abdomen', 'barriga'], ['abdômen', 'barriga'],
    ['ombro', 'ombro'], ['pulso', 'pulso'],
    ['braço', 'braco'], ['braco', 'braco'],
    ['peito', 'peito'], ['perna', 'perna'], ['coxa', 'coxa'],
    ['dedo', 'dedo'], ['mão', 'mao'], ['mao', 'mao'], ['pé', 'pe'], ['pe', 'pe'],
  ];
  for (const [k, v] of map) {
    // Short words like "pe" (foot) must match a word, not "Pedro" or "peito".
    if (k.length <= 3 && !text.split(/[^a-z0-9]+/).includes(k)) continue;
    if (text.includes(k)) return v;
  }
  return null;
}
function detectZone(t) {
  const text = normalizeText(t);
  if (/fechamento|fechar|fechado|sleeve|inteir[oa]|complet[oa]|cobrir tudo|o .* todo/.test(text)) return 'fechamento';
  if (/quase tudo|a maior parte|maior parte|grande/.test(text)) return 'grande';
  if (/area media|metade|medi[oa]/.test(text)) return 'medio';
  if (/partinha|pedacinho|so um detalhe|pequen[oa]/.test(text)) return 'pequeno';
  return null;
}
function detectStyle(t) {
  const text = normalizeText(t);
  const map = [
    ['neo tradicional', 'neo_tradicional'], ['neo trad', 'neo_tradicional'],
    ['old school', 'old_school'], ['oldschool', 'old_school'],
    ['fine line', 'fine_line'], ['fineline', 'fine_line'],
    ['new school', 'new_school'],
    ['geométrico', 'geometrico'], ['geométrica', 'geometrico'], ['geometrico', 'geometrico'],
    ['pontilhismo', 'pontilhismo'], ['aquarela', 'aquarela'], ['aquarelado', 'aquarela'],
    ['realismo', 'realismo'], ['blackwork', 'blackwork'], ['tradicional', 'tradicional'],
    ['japonês', 'japones'], ['japones', 'japones'], ['oriental', 'oriental'],
    ['tribal', 'tribal'], ['floral', 'floral'],
  ];
  for (const [k, v] of map) if (text.includes(k)) return v;
  return null;
}
function detectFirstTattoo(t) {
  if (/minha primeira|é minha 1ª|é a primeira|primeira tattoo|primeira tatu|nunca fiz|nunca tatuei|vai ser a primeira/.test(t)) return true;
  if (/já tenho|já fiz|já tatuei|tenho várias|tenho algumas|não é a primeira|segunda tattoo|terceira tattoo/.test(t)) return false;
  return null;
}
function detectDuvidasZeradas(t) {
  if (!t) return false;
  const messages = t.split(/\\n+/).map(s => s.trim().toLowerCase()).filter(Boolean);
  if (!messages.length) return false;
  const clearAnswer = /^(?:n[aã]o|n|sem d[uú]vidas?|sem mais (?:d[uú]vidas?|perguntas?)|n[aã]o tenho (?:mais )?(?:d[uú]vidas?|perguntas?)|pode mandar|manda o (?:valor|pre[cç]o)|pode falar|[eé] isso|tranquilo|encerrou|s[oó] isso|nada mais|mais nada)[.!]?$/i;
  const bareNo = /^(?:n[aã]o|n)[.!]?$/i;
  const priceRequest = /^(?:(?:e\\s*)?quanto(?:\\s+que)?\\s+(?:fica|custa)|qual\\s+(?:[eé]\\s+)?(?:o\\s+)?(?:valor|pre[cç]o)|(?:me\\s+)?passa\\s+(?:o\\s+)?(?:valor|pre[cç]o)|(?:manda|me\\s+manda)\\s+(?:o\\s+)?(?:valor|pre[cç]o))[?!.,\\s]*$/i;
  const acknowledgement = /^(?:beleza|blz|ok|okay|perfeito|combinado|entendi|claro|tranquilo|tranquila|show|demais|pode ser|isso|sim)[.!]?$/i;
  const noDoubt = /^(?:n[aã]o|n|acho que n[aã]o|sem d[uú]vidas?|sem mais (?:d[uú]vidas?|perguntas?)|n[aã]o tenho (?:mais )?(?:d[uú]vidas?|perguntas?)|s[oó] o (?:pre[cç]o|valor)(?: mesmo)?|s[oó] isso|nada mais|mais nada)[.!]?$/i;
  const doubtTalk = /(?:tenho|ficou|fiquei|surgiu|apareceu)[^.\\n]{0,25}(?:d[uú]vida|pergunta)/i;

  // WhatsApp bursts often answer with several short messages: "beleza",
  // "não", "acho que não", "só o preço mesmo". Any clear confirmation plus
  // only acknowledgements/price requests (and no real doubt) clears the gate.
  if (messages.some(m => acknowledgement.test(m) || noDoubt.test(m))
      && messages.every(m => acknowledgement.test(m) || noDoubt.test(m) || priceRequest.test(m) || clearAnswer.test(m))
      && !t.includes('?')
      && !doubtTalk.test(t)) {
    return true;
  }
  if (clearAnswer.test(messages[0]) && (messages.length === 1 || messages.slice(1).every(m => priceRequest.test(m)))) return true;
  // WAHA debounce joins burst messages with newlines. A bare "não" followed
  // only by a price request is an explicit checkpoint answer plus a request,
  // not an unresolved doubt. Other follow-up questions do not qualify.
  if (bareNo.test(messages[0]) && messages.length > 1 && messages.slice(1).every(m => priceRequest.test(m))) return true;
  // Also accept the same two-part answer when sent as one WhatsApp message.
  return /^(?:n[aã]o|n)[,;:]?\\s+(?:(?:e\\s*)?quanto(?:\\s+que)?\\s+(?:fica|custa)|qual\\s+(?:[eé]\\s+)?(?:o\\s+)?(?:valor|pre[cç]o))[?!.,\\s]*$/i.test(messages.join(' '));
}
function discoveryFallback(lead) {
  if (!lead.tattoo_type) return 'Só mais uma coisinha pra eu te ajudar certinho: essa tatuagem que você quer é uma nova, uma cobertura ou uma reforma?';
  if (lead.first_tattoo == null) return 'Essa seria a sua primeira tatuagem?';
  if (!lead.placement) return 'E onde no corpo você quer fazer?';
  if (!lead.body_zone) return 'Nesse local, você quer cobrir só uma partinha, uma área média, ou o local inteiro?';
  if (!lead.style) return 'E qual estilo você tem em mente?';
  return 'Vou te explicar rapidinho como funciona o processo de criação, pode ser?';
}
function nextDiscoveryQuestion(lead) {
  const ref = Array.isArray(lead.reference_analysis)
    ? (lead.reference_analysis[lead.reference_analysis.length - 1] || {})
    : (lead.reference_analysis || {});
  if (!lead.placement) return 'Onde no corpo você quer fazer?';
  if (!lead.body_zone) return 'Nesse local, você quer uma tattoo pequena, uma área média ou o local inteiro?';
  if (!lead.style && !(ref.estilo && ref.estilo !== 'indefinido')) return 'Qual estilo você tem em mente?';
  if (lead.first_tattoo === null || lead.first_tattoo === undefined) return 'Essa seria a sua primeira tatuagem?';
  return 'Me conta mais sobre a referência que você mandou.';
}

// ── Deterministic price-gate state ──
// Only the persisted checkpoint state can authorize a first quote. The model
// classifier is deliberately not trusted to clear doubts or open the gate.
const agentLower = agentOutput.toLowerCase();
const processoDeterministic = ['processo de criação', 'projeto exclusivo', 'sentar junto com você', 'tirar as medidas', 'encaixe perfeito'].some(frase => agentLower.includes(frase));
const processoExplicado = processoDeterministic || state.processo_explicado === true;
const checkpointWasPending = state.aguardando_confirmacao_duvidas === true;
const explicitClearThisTurn = checkpointWasPending && detectDuvidasZeradas(rawLead);
const duvidasEliminadas = state.duvidas_eliminadas === true || explicitClearThisTurn;

const current = lead.stage_key || 'novo';
const precoLiberadoJa = state.preco_liberado === true || ['orcamento_enviado', 'aguardando_deposito', 'agendado'].includes(current);
// Clearing doubts authorizes a quote, but the price is not considered
// liberated until an actual quote has been sent.
const gateJustOpened = processoExplicado && duvidasEliminadas;
const priceGateOpen = precoLiberadoJa || gateJustOpened;

function deterministicQuote(centsOverride = null) {
  const rows = $('Build System Prompt').first().json.pricing_rows || [];
  const row = rows.find(p => p.placement === lead.placement && p.body_zone === lead.body_zone);
  const baseCents = Number(centsOverride || (row && row.table_price) || 0);
  if (!baseCents) return null;
  const offer = $('Build System Prompt').first().json.offer || {};
  const offerLive = offer.promo_active === true && Number(offer.multiplier) > 0;
  // `centsOverride` is a price that was already agreed with the lead (they are
  // asking again). Applying the promotion multiplier on top of it would double
  // the price, so the offer price is only derived when there is no override.
  const offerCents = offerLive && centsOverride == null ? Math.ceil(baseCents * Number(offer.multiplier)) : null;
  const cents = offerCents || baseCents;
  const fmt = value => (value / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const places = {
    braco: 'no braço', braco_externo: 'na parte externa do braço', braco_interno: 'na parte interna do braço',
    antebraco: 'no antebraço', peito: 'no peito', costas: 'nas costas', perna: 'na perna', coxa: 'na coxa',
    panturrilha: 'na panturrilha', tornozelo: 'no tornozelo', pescoco: 'no pescoço', costela: 'na costela',
    barriga: 'na barriga', ombro: 'no ombro', pulso: 'no pulso', dedo: 'no dedo', mao: 'na mão', pe: 'no pé',
  };
  const zones = { pequeno: 'uma tatuagem pequena', medio: 'uma tatuagem média', grande: 'uma tatuagem grande', fechamento: 'fechamento' };
  const local = places[lead.placement] || `em ${lead.placement}`;
  const zona = zones[lead.body_zone] || lead.body_zone;
  const description = `${zona} ${local}`;
  const artistName = $('Resolve Artist').first().json.name || 'artista';
  let reply = `Para ${description}, fica ${fmt(cents)} à vista ou em até 6x de ${fmt(Math.round(cents / 6))} sem juros.`;
  if (offerLive) {
    reply += ` E com a promoção "${offer.name || 'leve 2'}", nesse mesmo valor você leva uma segunda tatuagem de valor igual ou menor, que pode até ser para outra pessoa ou outro local. A segunda a gente agenda depois com o ${artistName}.`;
  }
  reply += ` Como fica esse valor para você?`;
  return { cents, reply, offer_active: offerLive, base_cents: baseCents };
}
// ── Transition guard — prevent invalid/repeated transitions ──
let proposed = parsed.pipeline || current;

const saidCutoff = /não posso continuar essa conversa/.test(agentOutput.toLowerCase());
const neverBlock = ['agendado', 'fechado', 'perdido'];
if (proposed === 'bloqueado' && (!saidCutoff || neverBlock.includes(current))) {
  proposed = current;
  parsed.event = null;
}

const terminal = ['fechado', 'perdido', 'bloqueado'];
const validFrom = {
  'novo': ['novo', 'qualificando', 'orcamento_enviado', 'aguardando_deposito', 'agendado', 'aguardando_artista', 'bloqueado'],
  'qualificando': ['qualificando', 'orcamento_enviado', 'aguardando_deposito', 'agendado', 'aguardando_artista', 'bloqueado'],
  'orcamento_enviado': ['orcamento_enviado', 'aguardando_deposito', 'agendado', 'aguardando_artista', 'bloqueado'],
  'aguardando_deposito': ['aguardando_deposito', 'agendado', 'aguardando_artista', 'bloqueado'],
  'agendado': ['agendado'],
  'aguardando_artista': ['aguardando_artista'],
  'fechado': ['fechado'],
  'perdido': ['perdido'],
  'bloqueado': ['bloqueado'],
};

let finalPipeline = current;
let finalEvent = parsed.event || null;

if (terminal.includes(current)) {
  finalPipeline = current;
  finalEvent = null;
} else if (validFrom[current] && validFrom[current].includes(proposed)) {
  finalPipeline = proposed;
  if (proposed === current) finalEvent = null;
} else {
  finalPipeline = current;
  finalEvent = null;
}

let finalHandoff = h.reason || null;
let handoffSuppressed = false;
let recoveredFromHandoff = false;

// A cover-up/rework handoff is only legitimate when the LEAD explicitly said
// it. "Queria no estilo da foto" is not a cover-up.
if ((finalHandoff === 'cover_up' && !leadSaysCover)
    || (finalHandoff === 'reforma' && !leadSaysRework)) {
  finalHandoff = null;
  handoffSuppressed = true;
  if (finalPipeline === 'aguardando_artista') {
    finalPipeline = current;
    finalEvent = null;
  }
}

// The classifier can mistake Beatriz's own "vou passar pro artista" for a
// request from the lead. Only trust it when the lead actually asked.
if (finalHandoff === 'lead_requested_artist' && !leadRequestedArtist) {
  finalHandoff = null;
  handoffSuppressed = true;
  if (finalPipeline === 'aguardando_artista') {
    finalPipeline = current;
    finalEvent = null;
  }
}

// If the lead explicitly corrected the type to "nova", bring the Project back
// from aguardando_artista and continue the qualification.
if (tipoDeterministic === 'nova' && current === 'aguardando_artista') {
  finalHandoff = null;
  finalPipeline = 'qualificando';
  finalEvent = null;
  recoveredFromHandoff = true;
}

// ── Price gate: a quote transition only counts once the gate is open.
//    Otherwise the pipeline stays where it was and any explicit price in the
//    reply is replaced by the safe doubts question (hard output gate). ──
if (!priceGateOpen && ['orcamento_enviado', 'aguardando_deposito', 'agendado'].includes(finalPipeline)) {
  finalPipeline = current;
  finalEvent = null;
}

const hasPrice = /R\\$\\s*\\d|\\b\\d+(?:[.,]\\d{2})?\\s*(?:reais|à vista|a vista)|\\b\\d+x\\s+de\\s+/i.test(agentOutput);
const hasDeposit = /sinal|pix/i.test(agentOutput);
const priceGateViolation = (hasPrice || hasDeposit) && !priceGateOpen;
let finalReply = priceGateViolation
  ? (processoExplicado ? 'Antes de falarmos de valores, ficou alguma dúvida?' : discoveryFallback(lead))
  : agentOutput;

const goingToArtist = finalPipeline === 'aguardando_artista' || finalPipeline === 'bloqueado' || !!finalHandoff;
// ── Deterministic quote state: the model may not have quoted at all, so the
//    price table decides whether a first/repeat quote goes out. ──
const firstQuote = !precoLiberadoJa && gateJustOpened && !goingToArtist
  ? deterministicQuote()
  : null;
const asksForPrice = /quanto(?:\\s+que)?\\s+(?:fica|custa)|qual\\s+(?:[eé]\\s+)?(?:o\\s+)?(?:valor|pre[cç]o)|(?:me\\s+)?passa\\s+(?:o\\s+)?(?:valor|pre[cç]o)/i.test(rawLead);
const repeatedQuote = !firstQuote && precoLiberadoJa && asksForPrice && !goingToArtist
  ? deterministicQuote(lead.quoted_price_cents || lead.table_price_cents)
  : null;
if (firstQuote) {
  // The first quote comes from the artist's pricing table, not generated model
  // output or classifier extraction. This prevents a cleared checkpoint from
  // falling into negotiation or asking the checkpoint again.
  finalReply = firstQuote.reply;
  if (['novo', 'qualificando'].includes(finalPipeline)) {
    finalPipeline = 'orcamento_enviado';
    finalEvent = 'quote_sent';
  }
} else if (repeatedQuote) {
  // If the first "não" and a follow-up price request were processed in two
  // separate turns, repeat the current authoritative quote instead of treating
  // the request as a negotiation objection.
  finalReply = repeatedQuote.reply;
} else if (!precoLiberadoJa && gateJustOpened && !goingToArtist) {
  // Do not re-open doubts if the checkpoint was answered but there is no exact
  // price row yet. Ask only for the missing placement detail or state that the
  // artist will confirm the price.
  const genericPlacement = ['braco', 'perna'].includes(lead.placement);
  const artistName = $('Resolve Artist').first().json.name || 'artista';
  finalReply = genericPlacement
    ? (lead.placement === 'braco'
      ? 'Só pra eu te passar o valor certinho: é a parte de fora do braço, a de dentro, ou o antebraço?'
      : 'Só pra eu te passar o valor certinho: é na coxa ou na panturrilha?')
    : `Vou confirmar o valor certinho com o ${artistName} e já te retorno.`;
}

// ── Handoff output guard: if the handoff was suppressed (false cover-up /
//    invented artist request) or the lead corrected the type to "nova", do
//    not send a "vou te passar" message. Continue the qualification instead.
const handoffPhrase = /vou (?:te )?passar|passar (?:as )?informa(?:ç|c)(?:õ|o)es|deixa eu te passar|um momento/i;
if ((handoffSuppressed || recoveredFromHandoff)
    && (handoffPhrase.test(finalReply)
        || (handoffSuppressed && /cover-?up|cobertura|reforma/i.test(finalReply)))) {
  const newKnown = tipoDeterministic === 'nova' || lead.tattoo_type === 'new';
  if (newKnown) {
    finalReply = 'Perfeito, então é uma tatuagem nova! ' + nextDiscoveryQuestion(lead);
    finalPipeline = 'qualificando';
  } else {
    finalReply = 'Só pra eu entender: você quer uma tatuagem nova ou cobrir/reformar uma tatuagem que já existe?';
    finalPipeline = current;
  }
  finalEvent = null;
  finalHandoff = null;
}

// Before the checkpoint is cleared, no model/classifier response may leak a
// quote. Even when the model only gives a generic sign-off, keep the checkpoint
// as the final question. Once cleared, never ask it again.
const preGateDoubtLoop = !priceGateOpen && processoExplicado && !duvidasEliminadas;
if (preGateDoubtLoop && !priceGateViolation && !hasPrice
    && !goingToArtist && finalReply.trim()) {
  let trimmed = finalReply.trim();
  trimmed = trimmed.replace(
    /(?:\\s*(?:se\\s+(?:voc[eê]\\s+)?(?:precisar|quiser|tiver\\s+(?:mais\\s+|qualquer\\s+)?alguma\\s+(?:d[uú]vida|pergunta|quest[aã]o|informa[cç][aã]o))\\s*,?\\s*(?:[eé]\\s*s[oó]|pode)\\s*(?:me\\s*)?(?:falar|fala|chamar|chama)|qualquer\\s+(?:coisa|d[uú]vida)\\s*[,.]?\\s*(?:[eé]\\s*s[oó]\\s*)?(?:me\\s*)?(?:falar|fala|chamar|chama)|(?:estou|estamos|tô|to)\\s*(?:sempre\\s*)?[àa]\\s*(?:sua\\s*)?disposi[cç][aã]o|fique?\\s*[àa]\\s*vontade)\\s*[.!]?\\s*)$/i,
    ''
  );
  trimmed = trimmed.replace(/[,;:]\\s*$/, '').trim();
  if (!/(?:mais alguma|ficou alguma|alguma (?:outra )?)(?:pergunta|d[uú]vida)\\s*\\?\\s*$/i.test(trimmed)) {
    // Don't append a second question when this turn is already asking the lead
    // for missing information. The checkpoint is asked on a later turn.
    if (!/\?\s*$/.test(trimmed)) trimmed = trimmed ? trimmed + '\\n\\nMais alguma dúvida?' : 'Mais alguma dúvida?';
  }
  // If the checkpoint is already the final question, keep it even when the
  // explanation contains a rhetorical question ("tudo bem?"). The old branch
  // here sliced at the checkpoint and truncated the reply to "Mais"/"ficou".
  finalReply = trimmed;
}

// ── Deposit gate: never mention sinal/PIX before the chosen slot is booked.
//    `Book Slot` runs as a tool before this node; if it succeeded this turn,
//    or the project is already agendado, the gate is open.
let bookedThisTurn = false;
try {
  bookedThisTurn = $('Book Slot').isExecuted === true && !!$('Book Slot').first().json.id;
} catch (e) {
  bookedThisTurn = false;
}
const depositGateOpen = bookedThisTurn || current === 'agendado' || finalPipeline === 'agendado';
if (hasDeposit && !depositGateOpen) {
  finalReply = 'Primeiro vamos escolher o horário da sua tatuagem. Depois eu te passo o valor do sinal e o PIX. Quer ver os horários disponíveis?';
  finalPipeline = current;
  finalEvent = null;
}

// ── Vision acknowledgement: the model has the image block in its system
//    prompt, but it sometimes ignores it and asks again. On the turn that
//    actually analyzed a photo, make sure the reply mentions the reference.
let visionTurn = false;
try {
  visionTurn = $('Parse Reference Analysis').isExecuted === true;
} catch (e) {
  visionTurn = false;
}
if (visionTurn && lead.is_new !== true) {
  const vision = $('Parse Reference Analysis').first().json.reference_analysis || {};
  const subject = String(vision.assunto || vision.resumo || '')
    .split(/(?<=[.!?])\s+/)[0]
    .replace(/^A imagem (?:mostra|apresenta)\s*/i, '')
    .replace(/[.!?]+$/, '')
    .trim()
    .slice(0, 90);
  const already = /refer[êe]ncia|curti|(?:vi|recebi).{0,20}(?:foto|imagem)/i.test(finalReply);
  if (subject && vision.referencia_de_tatuagem !== false && !already) {
    finalReply = 'Vi a referência que você mandou: ' + subject + '. Curti! ' + finalReply;
  }
}

const finalReplyAsksCheckpoint = /(?:mais alguma|ficou alguma|alguma (?:outra )?)(?:pergunta|d[uú]vida)\\s*\\?\\s*$/i.test(finalReply.trim());
const hasEarlierQuestion = finalReply.slice(0, finalReply.lastIndexOf('?')).includes('?');
const aguardandoConfirmacaoDuvidas = !duvidasEliminadas
  && !precoLiberadoJa
  && !goingToArtist
  && processoExplicado
  && finalReplyAsksCheckpoint
  && !hasEarlierQuestion;
const rawCombined = ($('Debounce Resolve').first().json.combined_text || '').trim();
const isAudio = rawCombined === '[ÁUDIO RECEBIDO]' && !audioTranscript;
const quoteActuallySent = !isAudio && (!!firstQuote || !!repeatedQuote || (priceGateOpen && !priceGateViolation && /R\\$\\s*\\d/.test(finalReply)));

// Pipeline state must reflect the message that will actually be sent, not a
// classifier's prediction about a reply that was replaced by the hard gate.
if (!quoteActuallySent && ['novo', 'qualificando'].includes(current)
    && ['orcamento_enviado', 'aguardando_deposito', 'agendado'].includes(finalPipeline)) {
  finalPipeline = current;
  finalEvent = null;
}
if (!/sinal|pix/i.test(finalReply) && finalPipeline === 'aguardando_deposito'
    && current !== 'aguardando_deposito') {
  finalPipeline = current;
  finalEvent = null;
}

// ── Audio handling (deterministic — the model never sees the raw placeholder
//    matter; we answer every audio and hand off on the second). ──
const audioCountNow = Number(state.audio_count || 0);
const audioCountNext = isAudio ? audioCountNow + 1 : audioCountNow;

let audioFinalReply = finalReply;
let audioPipeline = finalPipeline;
let audioEvent = finalEvent;
let audioHandoff = finalHandoff;
if (isAudio) {
  if (audioCountNow >= 1) {
    audioFinalReply = 'Deixa eu te passar pro artista, ele consegue te ouvir direitinho.';
    audioPipeline = ['fechado', 'perdido', 'agendado', 'bloqueado'].includes(finalPipeline)
      ? finalPipeline
      : 'aguardando_artista';
    audioEvent = 'handoff_triggered';
    audioHandoff = 'audio_2x';
  } else {
    audioFinalReply = 'Me manda por texto, por favor? Assim consigo te ajudar melhor.';
    audioPipeline = finalPipeline;
    audioEvent = null;
    audioHandoff = null;
  }
}

// Quote state to persist: the deterministic quote (when the model did not
// produce one) becomes a real `quotes` row via the Ensure Quote node.
const quoteBaseCents = !quoteActuallySent
  ? null
  : (firstQuote ? firstQuote.base_cents
     : (repeatedQuote ? (lead.table_price_cents || repeatedQuote.base_cents) : (p.table_cents || null)));
const quoteOfferedCents = !quoteActuallySent
  ? null
  : (firstQuote ? firstQuote.cents
     : (repeatedQuote ? (lead.quoted_price_cents || repeatedQuote.cents) : (p.nego_cents || p.table_cents || null)));

return [{ json: {
  lead_id: lead.lead_id,
  project_id: lead.project_id,
  offer_quoted: !!(firstQuote && firstQuote.offer_active),
  stage_key: audioPipeline,
  event_type: audioEvent,
  processo_explicado_val: processoDeterministic || state.processo_explicado === true,
  preco_liberado_val: precoLiberadoJa || quoteActuallySent,
  duvidas_eliminadas_val: duvidasEliminadas,
  aguardando_confirmacao_duvidas_val: isAudio ? checkpointWasPending : aguardandoConfirmacaoDuvidas,
  price_gate_violation: priceGateViolation,
  audio_count_val: audioCountNext,
  final_reply: audioFinalReply,

  placement_val: detectPlacement(q.placement) || detectPlacement(rawLead) || null,
  body_zone_val: detectZone(q.body_zone) || detectZone(rawLead) || null,
  style_val: detectStyle(q.style) || detectStyle(rawLead) || null,
  first_tattoo_val: (q.first_tattoo === true || q.first_tattoo === false)
    ? q.first_tattoo
    : (detectFirstTattoo(rawLead) !== null ? detectFirstTattoo(rawLead) : null),
  meaning_val: q.significado || null,
  tattoo_type_val: TIPO_DB[tipoDeterministic || q.tipo_tatuagem] || null,

  quote_base_cents: quoteBaseCents,
  quote_offered_cents: quoteOfferedCents,

  // Deposits are persisted by the Request Deposit tool; this is only the
  // amount the classifier saw, used by the ops notification.
  deposit_amount_cents: priceGateViolation || !/sinal|pix/i.test(audioFinalReply) ? null : (d.amount_cents || null),

  handoff_reason: audioHandoff,
  name_val: parsed.name || null,

  // SDR scratch flags: merged into chat_sessions.agent_state by Patch Chat State.
  chat_state: {
    processo_explicado: processoDeterministic || state.processo_explicado === true,
    preco_liberado: precoLiberadoJa || quoteActuallySent,
    duvidas_eliminadas: duvidasEliminadas,
    aguardando_confirmacao_duvidas: isAudio ? checkpointWasPending : aguardandoConfirmacaoDuvidas,
    audio_count: audioCountNext,
  },
} }];"""

# ── Build Project Patch: the deltas the agent learned this turn. The RPC
#    applies only non-null fields, so empty values are dropped here. ──
BUILD_PROJECT_PATCH_JS = """const f = $input.first().json;
const artist = $('Resolve Artist').first().json;
const turn = $('Start Turn').first().json;

const text = (v) => (v === undefined || v === null || v === '' || v === 'null') ? null : String(v);

return [{ json: {
  ...f,
  args: [
    artist.id,
    turn.project_id,
    text(f.stage_key),
    text(f.placement_val),
    text(f.body_zone_val),
    text(f.style_val),
    (f.first_tattoo_val === true || f.first_tattoo_val === false) ? f.first_tattoo_val : null,
    text(f.meaning_val),
    text(f.tattoo_type_val),
    null,                    // reference_pics: the agent never writes them
    text(f.handoff_reason),
    'agent',
    text(turn.stage_key),    // expected stage: never override a tool's transition
  ],
} }];"""

# ── Handoff / deposit notification → Telegram ops group with inline buttons.
#    The Telegram workflow's callback branch handles confirm/cancel (handoff
#    → fechado/perdido, deposit → confirmado/perdido). Same output shape as
#    the Telegram testbed; artist first-name variants keep the handoff
#    fallback working in multi-tenant mode. ──
BUILD_HANDOFF_MESSAGE_JS = r"""const parseData = $('Parse Classification').first().json;
const updateData = $('Patch Project').first().json;
const leadData = $('Start Turn').first().json;
const artistData = $('Resolve Artist').first().json;
const agentOutput = (($('Parse Classification').first().json.final_reply) || ($('AI Agent').first().json.output) || '').toLowerCase();

const event = (parseData && parseData.event_type) || null;
const status = (updateData && updateData.stage_key)
  || (parseData && parseData.stage_key)
  || '';

// Per-artist ops Telegram group (handoff/deposit confirm buttons). Required —
// if the artist has none configured, skip the notification so it never goes
// to the wrong group.
const opsChatId = (artistData && artistData.telegram_group_id) || '';

const leadNome = leadData && leadData.name ? leadData.name : 'Desconhecido';
const telefone = leadData && leadData.phone_e164 ? String(leadData.phone_e164).replace(/\D/g, '') : '';
const whatsappLink = telefone && /^\d{8,15}$/.test(telefone)
  ? 'https://wa.me/' + telefone
  : null;

// Human-friendly BR formatting: +55 11 99999-0001
const formatPhone = (d) => {
  if (!d) return '';
  if (d.length === 13) return '+' + d.slice(0, 2) + ' ' + d.slice(2, 4) + ' ' + d.slice(4, 9) + '-' + d.slice(9);
  if (d.length === 12) return '+' + d.slice(0, 2) + ' ' + d.slice(2, 4) + ' ' + d.slice(4, 8) + '-' + d.slice(8);
  return '+' + d;
};
const telefoneDisplay = formatPhone(telefone);
const telefoneLine = telefoneDisplay
  ? (whatsappLink
      ? '<b>Telefone:</b> <a href="' + whatsappLink + '">' + telefoneDisplay + '</a>'
      : '<b>Telefone:</b> ' + telefoneDisplay)
  : null;

// Fallback: detect a handoff directly from Beatriz's message if the
// classifier did not emit event=handoff_triggered. Include the artist's own
// first name (multi-tenant) plus generic phrases.
const artistNome = artistData && artistData.name ? String(artistData.name).toLowerCase() : '';
const handoffPhrases = [
  'vou te passar pro artista', 'vou passar pro artista', 'te passar pro artista',
  'deixa eu te passar', 'vou te passar pro', 'passar o lead pro artista'
];
if (artistNome) {
  handoffPhrases.push('vou te passar pro ' + artistNome, 'vou passar pro ' + artistNome, 'passar o lead pro ' + artistNome);
}
const saidHandoff = handoffPhrases.some(p => agentOutput.includes(p));

// Fallback: detect a deposit request directly from Beatriz's message.
const depositPhrases = ['sinal de', 'sinal é de', 'sinal no valor de', 'trabalhamos com um sinal', 'chave pix', 'pix é', 'pix:', '% do valor'];
const saidDeposit = depositPhrases.some(p => agentOutput.includes(p));

// Only notify on an actual transition — event_type is set only when state changed
if (!event && !saidHandoff && !saidDeposit) {
  return [{ json: { send: false } }];
}

// Artist must have a Telegram ops group configured to receive the buttons.
if (!opsChatId) {
  console.error('[Beatriz-WhatsApp] Artist sem telegram_group_id configurado:', artistData && artistData.name);
  return [{ json: { send: false } }];
}

// ── Handoff ──
if (event === 'handoff_triggered' || saidHandoff) {
  const projectId = (parseData && parseData.project_id) || '';
  const reasonMap = {
    cover_up: 'Cover-up',
    reforma: 'Reforma de tatuagem',
    lead_requested_artist: 'Lead pediu o artista',
    below_piso: 'Contraproposta abaixo do piso',
    vague: 'Descrição vaga',
    audio_2x: 'Áudio/sticker repetido',
    no_availability: 'Sem horário disponível no calendário',
  };
  const reason = reasonMap[parseData.handoff_reason] || parseData.handoff_reason || 'não especificado';

  const msg = [
    '<b>\u{1F514} Handoff: Lead precisa de atenção</b>',
    '<b>Lead:</b> ' + leadNome,
    '<b>Motivo:</b> ' + reason,
  ];
  if (telefoneLine) msg.push(telefoneLine);
  if (whatsappLink) msg.push('\u{1F449} <a href="' + whatsappLink + '">Abrir no WhatsApp</a>');

  return [{ json: {
    send: true,
    chatId: opsChatId,
    text: msg.join('\n'),
    btn1_text: '\u2705 Confirmar',
    btn1_cb: 'handoff:confirm:' + projectId,
    btn2_text: '\u274C Cancelar',
    btn2_cb: 'handoff:cancel:' + projectId,
  } }];
}

// ── Deposit requested ──
if (event === 'deposit_requested' || saidDeposit) {
  const projectId = parseData.project_id || '';
  const amount = parseData.deposit_amount_cents
    ? 'R$ ' + (parseData.deposit_amount_cents / 100).toFixed(2).replace('.', ',')
    : 'valor pendente';
  const dm = [
    '<b>\u{1F4B0} Sinal solicitado</b>',
    '<b>Lead:</b> ' + leadNome,
  ];
  if (telefoneLine) dm.push(telefoneLine);
  dm.push('<b>Valor:</b> ' + amount);
  if (whatsappLink) dm.push('\u{1F449} <a href="' + whatsappLink + '">Abrir no WhatsApp</a>');
  dm.push('<i>Confirme o recebimento do PIX</i>');
  const msg = dm.join('\n');

  return [{ json: {
    send: true,
    chatId: opsChatId,
    text: msg,
    btn1_text: '\u2705 Sinal Recebido',
    btn1_cb: 'deposit:confirm:' + projectId,
    btn2_text: '\u274C Não Pago',
    btn2_cb: 'deposit:cancel:' + projectId,
  } }];
}

// ── No match — no notification ──
return [{ json: { send: false } }];"""

WF = {
    "name": "Beatriz WhatsApp Agent",
    "description": "Beatriz SDR agent on WhatsApp via WAHA — mirrors the Telegram core (debounce + classification) with WAHA transport and multi-tenant artist resolution.",
    "nodes": [
        # 1. WAHA Webhook — instant ack so WAHA never waits for debounce/AI time
        {
            "parameters": {
                "httpMethod": "POST",
                "path": "waha-webhook",
                "responseMode": "onReceived",
                "options": {},
                "authentication": "headerAuth",
            },
            "type": "n8n-nodes-base.webhook",
            "typeVersion": 2,
            "position": [0, 0],
            "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
            "name": "WAHA Webhook",
            "webhookId": "ef3df1a1-7f34-47a1-aadb-4fb9d1de5e39",
            "credentials": WEBHOOK_AUTH,
        },
        # 2. Resolve Artist
        {
            "parameters": {
                "operation": "executeQuery",
                "query": RESOLVE_ARTIST_QUERY,
                "options": {
                    "queryReplacement": "={{ [$json.body.session] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [260, 0],
            "id": "b2c3d4e5-6f7a-8b9c-0d1e-2f3a4b5c6d7e",
            "name": "Resolve Artist",
            "credentials": POSTGRES,
        },
        # 3. Artist Found?
        {
            "parameters": {
                "conditions": {
                    "options": {
                        "caseSensitive": True,
                        "leftValue": "",
                        "typeValidation": "strict",
                        "version": 3,
                    },
                    "conditions": [
                        {
                            "id": "wa-artist-found-cond-0000",
                            "leftValue": "={{ $json.found }}",
                            "rightValue": True,
                            "operator": {
                                "type": "boolean",
                                "operation": "equals",
                            },
                        }
                    ],
                    "combinator": "and",
                },
                "options": {},
            },
            "type": "n8n-nodes-base.if",
            "typeVersion": 2.2,
            "position": [520, 0],
            "id": "c3d4e5f6-7a8b-9c0d-1e2f-3a4b5c6d7e8f",
            "name": "Artist Found?",
        },
        # 3a2. Group Chat? — ignore WhatsApp groups completely. The agent only
        #      serves one-to-one conversations; group messages/presence never
        #      create a Lead, a Project or a reply.
        {
            "parameters": {
                "conditions": {
                    "options": {
                        "caseSensitive": True,
                        "leftValue": "",
                        "typeValidation": "strict",
                        "version": 3,
                    },
                    "conditions": [
                        {
                            "id": "group-chat-cond-0000",
                            "leftValue": "={{ (function(){ var b = $('WAHA Webhook').first().json.body || {}; var p = b.payload || {}; var k = (p._data && p._data.key) || {}; var from = String(p.from || k.remoteJid || ''); return from.endsWith('@g.us') || p.isGroup === true; })() }}",
                            "rightValue": True,
                            "operator": {"type": "boolean", "operation": "equals"},
                        }
                    ],
                    "combinator": "and",
                },
                "options": {},
            },
            "type": "n8n-nodes-base.if",
            "typeVersion": 2.2,
            "position": [780, -180],
            "id": "group-chat-0000-0000-0000-000000000001",
            "name": "Group Chat?",
        },
        # 3b. Route Event — presence updates only feed the debounce buffer;
        #     messages continue through the AI window gate. Anything else
        #     (session.status, acks) is dropped on the unconnected 3rd output.
        {
            "parameters": {
                "mode": "expression",
                "numberOutputs": 3,
                "output": "={{ $('WAHA Webhook').first().json.body.event === 'presence.update' ? 0 : ($('WAHA Webhook').first().json.body.event === 'message' ? 1 : 2) }}",
                "options": {},
            },
            "type": "n8n-nodes-base.switch",
            "typeVersion": 3.2,
            "position": [780, 0],
            "id": "rt-event-0000-0000-0000-000000000001",
            "name": "Route Event",
        },
        # 3c. Presence Start — normalize WAHA presence.update into a buffer key.
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": PRESENCE_START_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1040, 240],
            "id": "pr-start-0000-0000-0000-000000000001",
            "name": "Presence Start",
        },
        # 3d. Presence Upsert — record typing state for the debounce loop.
        {
            "parameters": {
                "operation": "executeQuery",
                "query": PRESENCE_UPSERT_QUERY,
                "options": {
                    "queryReplacement": "={{ [$('Resolve Artist').first().json.id, $json.chat_id, $json.presence] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1300, 240],
            "id": "pr-upsert-0000-0000-0000-000000000001",
            "name": "Presence Upsert",
            "credentials": POSTGRES,
        },
        # 4. Missing Session Log (false branch)
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": """const wa = $('WAHA Webhook').first().json.body || {};
const slug = wa.session || 'unknown';
console.error('[Beatriz-WhatsApp] WAHA session not found in artists table:', slug);
return [{ json: { error: true, reason: 'session_not_found', session_slug: slug } }];""",
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [780, 240],
            "id": "d4e5f6a7-8b9c-0d1e-2f3b-4a5b6c7d8e9f",
            "name": "Missing Session Log",
        },
        # 5. Check AI Window — silent outside the artist's AI active window.
        #    Also flags from_me (the ARTIST replying in WhatsApp) so a separate
        #    branch can record the per-chat takeover instead of answering.
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": """const wa = $('WAHA Webhook').first().json.body || {};
const fromMe = !!(wa.payload && wa.payload.fromMe === true);
const session = wa.session || 'unknown';
const from = String(wa.payload && wa.payload.from || '').replace('@c.us', '');
const chatId = session + ':' + from;

// The artist replying in WhatsApp is the takeover signal — never answer it.
if (fromMe) {
  return [{ json: { in_window: false, from_me: true, chat_id: chatId } }];
}

const artist = $('Resolve Artist').first().json;
const hours = artist.ai_active_hours;

if (!hours || !hours.start || !hours.end) {
  return [{ json: { in_window: true, from_me: false, chat_id: chatId } }];
}

const toMin = (t) => {
  const [h, m] = String(t).split(':');
  return parseInt(h, 10) * 60 + parseInt(m, 10);
};
const tz = artist.timezone || 'America/Sao_Paulo';
const nowTotal = toMin(new Date().toLocaleTimeString('en-GB', {
  timeZone: tz, hour: '2-digit', minute: '2-digit', hour12: false,
}));
const start = toMin(hours.start);
const end = toMin(hours.end);

const inWindow = start < end
  ? (nowTotal >= start && nowTotal < end)
  : (nowTotal >= start || nowTotal < end);

return [{ json: { in_window: inWindow, from_me: false, chat_id: chatId } }];""",
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [780, -240],
            "id": "wa-gate-code-0000-0000-0000-000000000001",
            "name": "Check AI Window",
        },
        # 5b. Artist Replied? — an outgoing message from the artist's own number
        #     (from_me) means the artist is taking over this chat by hand.
        {
            "parameters": {
                "conditions": {
                    "options": {
                        "caseSensitive": True,
                        "leftValue": "",
                        "typeValidation": "strict",
                        "version": 3,
                    },
                    "conditions": [
                        {
                            "id": "wa-artist-replied-cond-0000",
                            "leftValue": "={{ $json.from_me }}",
                            "rightValue": True,
                            "operator": {
                                "type": "boolean",
                                "operation": "equals",
                            },
                        }
                    ],
                    "combinator": "and",
                },
                "options": {},
            },
            "type": "n8n-nodes-base.if",
            "typeVersion": 2.2,
            "position": [1040, -420],
            "id": "wa-artist-replied-0000-0000-0000-000000000001",
            "name": "Artist Replied?",
        },
        # 5c. Take Chat Control — persist owner='artist' for this chat so
        #     Beatriz stays silent until the artist returns it (dashboard).
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM take_chat_control($1::uuid, $2::text);",
                "options": {
                    "queryReplacement": "={{ [$('Resolve Artist').first().json.id, $json.chat_id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1300, -420],
            "id": "wa-take-chat-0000-0000-0000-000000000001",
            "name": "Take Chat Control",
            "credentials": POSTGRES,
        },
        # 6. In AI Window?
        {
            "parameters": {
                "conditions": {
                    "options": {
                        "caseSensitive": True,
                        "leftValue": "",
                        "typeValidation": "strict",
                        "version": 3,
                    },
                    "conditions": [
                        {
                            "id": "wa-gate-if-cond-0000",
                            "leftValue": "={{ $json.in_window }}",
                            "rightValue": True,
                            "operator": {
                                "type": "boolean",
                                "operation": "equals",
                            },
                        }
                    ],
                    "combinator": "and",
                },
                "options": {},
            },
            "type": "n8n-nodes-base.if",
            "typeVersion": 2,
            "position": [1040, -240],
            "id": "wa-gate-if-0000-0000-0000-000000000002",
            "name": "In AI Window?",
        },
        # 7. Debounce Start
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": DEBOUNCE_START_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1040, 0],
            "id": "db-start-0000-0000-0000-000000000001",
            "name": "Debounce Start",
        },
        # 7b. Subscribe Presence — best-effort per-chat subscription so WAHA
        #     emits presence.update (typing) for the lead. Runs as a fan-out
        #     branch so it never delays the debounce/AI path.
        {
            "parameters": {
                "method": "POST",
                "url": "={{ 'https://waha.vaif.com.br/api/' + $('Resolve Artist').first().json.wa_session_slug + '/presence/' + $('WAHA Webhook').first().json.body.payload.from + '/subscribe' }}",
                "authentication": "predefinedCredentialType",
                "nodeCredentialType": "wahaApi",
                "options": {"timeout": 15000},
            },
            "type": "n8n-nodes-base.httpRequest",
            "typeVersion": 4.2,
            "position": [1300, 200],
            "id": "db-subscribe-0000-0000-0000-000000000001",
            "name": "Subscribe Presence",
            "credentials": WAHA,
            "onError": "continueRegularOutput",
        },
        # 8. Debounce Accumulate
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM chat_buffer_accumulate($1::uuid, $2::text, $3::text, to_timestamp($4::float8 / 1000.0));",
                "options": {
                    "queryReplacement": "={{ [$('Resolve Artist').first().json.id, $json.chat_id, $json.msg_text, $json.msg_ts] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1300, 0],
            "id": "db-acc-0000-0000-0000-000000000001",
            "name": "Debounce Accumulate",
            "credentials": POSTGRES,
        },
        # 9. Wait — hold for the burst to finish
        {
            "parameters": {
                "resume": "timeInterval",
                "amount": 2,
                "unit": "seconds",
            },
            "type": "n8n-nodes-base.wait",
            "typeVersion": 1.2,
            "position": [1560, 0],
            "id": "db-wait-0000-0000-0000-000000000001",
            "name": "Wait",
        },
        # 10. Get Buffer State
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM chat_buffer_state($1::uuid, $2::text);",
                "options": {
                    "queryReplacement": "={{ [$('Resolve Artist').first().json.id, $('Debounce Start').first().json.chat_id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1820, 0],
            "id": "db-state-0000-0000-0000-000000000001",
            "name": "Get Buffer State",
            "credentials": POSTGRES,
        },
        # 11. Debounce Resolve
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": DEBOUNCE_RESOLVE_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [2080, 0],
            "id": "db-resolve-0000-0000-0000-000000000001",
            "name": "Debounce Resolve",
        },
        # 12. Debounce Decision — process the burst, keep waiting (typing / new
        #     activity), or stop (a newer message execution owns the reply).
        {
            "parameters": {
                "mode": "expression",
                "numberOutputs": 3,
                "output": "={{ $json.decision === 'process' ? 0 : ($json.decision === 'wait' ? 1 : 2) }}",
                "options": {},
            },
            "type": "n8n-nodes-base.switch",
            "typeVersion": 3.2,
            "position": [2340, 0],
            "id": "db-decision-0000-0000-0000-000000000001",
            "name": "Debounce Decision",
        },
        # 13. Clear Buffer (process branch)
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM chat_buffer_clear($1::uuid, $2::text);",
                "options": {
                    "queryReplacement": "={{ [$('Resolve Artist').first().json.id, $json.chat_id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [2600, 0],
            "id": "db-clear-0000-0000-0000-000000000001",
            "name": "Clear Buffer",
            "credentials": POSTGRES,
        },
        # 13b. Check Chat Owner — is the artist currently handling this chat?
        #      (persistent per-chat ownership; the artist takes over by replying
        #      in WhatsApp and returns it from the dashboard.)
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT chat_owner($1::uuid, $2::text) AS owner;",
                "options": {
                    "queryReplacement": "={{ [$('Resolve Artist').first().json.id, $('Debounce Start').first().json.chat_id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [2860, 120],
            "id": "co-check-0000-0000-0000-000000000001",
            "name": "Check Chat Owner",
            "credentials": POSTGRES,
        },
        # 13c. Chat Owned by Artist? — if the artist owns this chat, Beatriz
        #      stays silent (no AI, no reply) until the artist returns it.
        {
            "parameters": {
                "conditions": {
                    "options": {
                        "caseSensitive": True,
                        "leftValue": "",
                        "typeValidation": "strict",
                        "version": 3,
                    },
                    "conditions": [
                        {
                            "id": "co-if-cond-0000",
                            "leftValue": "={{ $json.owner }}",
                            "rightValue": "artist",
                            "operator": {
                                "type": "string",
                                "operation": "equals",
                            },
                        }
                    ],
                    "combinator": "and",
                },
                "options": {},
            },
            "type": "n8n-nodes-base.if",
            "typeVersion": 2.2,
            "position": [3120, 120],
            "id": "co-if-0000-0000-0000-000000000001",
            "name": "Chat Owned by Artist?",
        },
        # 13d. Load Offer — active promotion + open-quote price protection for
        #      this lead (always returns one sentinel row).
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM active_offer_for_project($1::uuid, $2::uuid);",
                "options": {
                    "queryReplacement": "={{ [$('Resolve Artist').first().json.id, $('Start Turn').first().json.project_id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [3120, 360],
            "id": "of-load-0000-0000-0000-000000000001",
            "name": "Load Offer",
            "credentials": POSTGRES,
        },
        # 14. Load Pricing — artist price table for the dynamic prompt
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM list_prices($1::uuid);",
                "options": {
                    "queryReplacement": "={{ [$('Resolve Artist').first().json.id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [2860, -260],
            "id": "lp-pricing-0000-0000-0000-000000000001",
            "name": "Load Pricing",
            "credentials": POSTGRES,
            "alwaysOutputData": True,
        },
        # 15. Build System Prompt — render beatriz-system.md with artist data
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": BUILD_SYSTEM_PROMPT_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [2860, -60],
            "id": "lp-build-prompt-0000-0000-0000-000000000001",
            "name": "Build System Prompt",
        },
        # 16. Start Turn — one round trip per message: the Lead (created on
        #     first contact), the open Project, the chat session, the price
        #     context and the SDR scratch state.
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM start_turn($1::uuid, $2::text, $3::text, NULLIF($4, ''), 'whatsapp');",
                "options": {
                    "queryReplacement": "={{ [$('Resolve Artist').first().json.id, $('Debounce Start').first().json.chat_id, $('Debounce Start').first().json.phone, ($('WAHA Webhook').first().json.body.payload._data?.notifyName || '')] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [2600, 260],
            "id": "start-turn-0000-0000-0000-000000000001",
            "name": "Start Turn",
            "credentials": POSTGRES,
        },
        # 16a. Agent Blocked? — the artist silenced the SDR for this Lead.
        {
            "parameters": {
                "conditions": {
                    "options": {
                        "caseSensitive": True,
                        "leftValue": "",
                        "typeValidation": "strict",
                        "version": 3,
                    },
                    "conditions": [
                        {
                            "id": "agent-blocked-cond-0000",
                            "leftValue": "={{ $json.agent_blocked }}",
                            "rightValue": True,
                            "operator": {"type": "boolean", "operation": "equals"},
                        }
                    ],
                    "combinator": "and",
                },
                "options": {},
            },
            "type": "n8n-nodes-base.if",
            "typeVersion": 2.2,
            "position": [2600, 640],
            "id": "agent-blocked-0000-0000-0000-000000000001",
            "name": "Agent Blocked?",
        },
        # 16b. Build References — the inbound picture/links, once the Project exists.
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": BUILD_REFERENCES_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [2600, 440],
            "id": "ref-build-0000-0000-0000-000000000001",
            "name": "Build References",
        },
        # 16c. Save References — POST to the CRM internal endpoint (the CRM
        #      downloads WAHA media with its own key and stores it).
        {
            "parameters": {
                "method": "POST",
                "url": "https://crm.vaif.com.br/api/references",
                "authentication": "genericCredentialType",
                "genericAuthType": "httpHeaderAuth",
                "sendBody": True,
                "specifyBody": "json",
                "jsonBody": "={{ JSON.stringify($json) }}",
                "options": {"timeout": 30000},
            },
            "type": "n8n-nodes-base.httpRequest",
            "typeVersion": 4.2,
            "position": [2860, 440],
            "id": "ref-save-0000-0000-0000-000000000001",
            "name": "Save References",
            "credentials": CRM_INTERNAL,
            "onError": "continueRegularOutput",
        },
        # 16c2. Analyze Audio? — voice notes are transcribed before the AI.
        {
            "parameters": {
                "conditions": {
                    "options": {
                        "caseSensitive": True,
                        "leftValue": "",
                        "typeValidation": "strict",
                        "version": 3,
                    },
                    "conditions": [
                        {
                            "id": "audio-turn-cond-0000",
                            "leftValue": "={{ !!($('Debounce Start').first().json.audio_url) }}",
                            "rightValue": True,
                            "operator": {"type": "boolean", "operation": "equals"},
                        }
                    ],
                    "combinator": "and",
                },
                "options": {},
            },
            "type": "n8n-nodes-base.if",
            "typeVersion": 2.2,
            "position": [3120, -300],
            "id": "audio-turn-0000-0000-0000-000000000001",
            "name": "Analyze Audio?",
        },
        # 16c3. Fetch Audio — WAHA media (use the configured host, not the
        #       potentially stale public URL).
        {
            "parameters": {
                "method": "GET",
                "url": "={{ (function(){ var raw = $('Debounce Start').first().json.audio_url || ''; return raw ? raw.replace(/^https?:\\/\\/[^/]+/, 'https://waha.vaif.com.br') : raw; })() }}",
                "authentication": "predefinedCredentialType",
                "nodeCredentialType": "wahaApi",
                "options": {
                    "timeout": 30000,
                    "response": {
                        "response": {
                            "responseFormat": "file",
                            "outputPropertyName": "data"
                        }
                    },
                },
            },
            "type": "n8n-nodes-base.httpRequest",
            "typeVersion": 4.2,
            "position": [3380, -420],
            "id": "audio-fetch-0000-0000-0000-000000000001",
            "name": "Fetch Audio",
            "credentials": WAHA,
            "onError": "continueRegularOutput",
        },
        # 16c3b. Extract Audio Base64 — n8n's binary store is filesystem-backed,
        #        so convert the audio binary to a base64 JSON field here.
        {
            "parameters": {
                "operation": "binaryToPropery",
                "binaryPropertyName": "data",
                "destinationKey": "audio_base64",
                "options": {"keepSource": "both"},
            },
            "type": "n8n-nodes-base.extractFromFile",
            "typeVersion": 1.2,
            "position": [3510, -420],
            "id": "audio-base64-0000-0000-0000-000000000001",
            "name": "Extract Audio Base64",
            # A failed download must still let the agent ask for text.
            "onError": "continueRegularOutput",
        },
        # 16c4. Build Audio Request — base64 + input_audio body.
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": BUILD_AUDIO_REQUEST_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [3640, -420],
            "id": "audio-build-0000-0000-0000-000000000001",
            "name": "Build Audio Request",
        },
        # 16c4b. Audio Ready? — skip Gemini when the download or base64 step
        #        failed; the agent then asks the lead to send text instead.
        {
            "parameters": {
                "conditions": {
                    "options": {
                        "caseSensitive": True,
                        "leftValue": "",
                        "typeValidation": "strict",
                        "version": 3,
                    },
                    "conditions": [
                        {
                            "id": "audio-ready-cond-0000",
                            "leftValue": "={{ !!$json.body }}",
                            "rightValue": True,
                            "operator": {"type": "boolean", "operation": "equals"},
                        }
                    ],
                    "combinator": "and",
                },
                "options": {},
            },
            "type": "n8n-nodes-base.if",
            "typeVersion": 2.2,
            "position": [3770, -420],
            "id": "audio-ready-0000-0000-0000-000000000001",
            "name": "Audio Ready?",
        },
        # 16c5. Transcribe Audio — Gemini audio input through the existing
        #       OpenRouter credential.
        {
            "parameters": {
                "method": "POST",
                "url": "https://openrouter.ai/api/v1/chat/completions",
                "authentication": "predefinedCredentialType",
                "nodeCredentialType": "openRouterApi",
                "sendBody": True,
                "specifyBody": "json",
                "jsonBody": "={{ JSON.stringify($json.body) }}",
                "options": {"timeout": 60000},
            },
            "type": "n8n-nodes-base.httpRequest",
            "typeVersion": 4.2,
            "position": [3900, -420],
            "id": "audio-transcribe-0000-0000-0000-000000000001",
            "name": "Transcribe Audio",
            "credentials": OPENROUTER,
            "onError": "continueRegularOutput",
        },
        # 16c6. Parse Audio — extract the transcript.
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": PARSE_AUDIO_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [4160, -420],
            "id": "audio-parse-0000-0000-0000-000000000001",
            "name": "Parse Audio",
        },
        # 16c7. No Audio — text/image turns pass through unchanged.
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": NO_AUDIO_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [3380, -200],
            "id": "audio-none-0000-0000-0000-000000000001",
            "name": "No Audio",
        },
        # 16d. Analyze Reference? — only when the CRM stored an image URL.
        {
            "parameters": {
                "conditions": {
                    "options": {
                        "caseSensitive": True,
                        "leftValue": "",
                        "typeValidation": "strict",
                        "version": 3,
                    },
                    "conditions": [
                        {
                            "id": "analyze-ref-cond-0000",
                            "leftValue": "={{ !!($('Save References').first().json.image_url) }}",
                            "rightValue": True,
                            "operator": {"type": "boolean", "operation": "equals"},
                        }
                    ],
                    "combinator": "and",
                },
                "options": {},
            },
            "type": "n8n-nodes-base.if",
            "typeVersion": 2.2,
            "position": [3120, -60],
            "id": "vision-if-0000-0000-0000-000000000001",
            "name": "Analyze Reference?",
        },
        # 16e. Build Vision Request — OpenRouter multimodal body (public image URL).
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": BUILD_VISION_REQUEST_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [3380, -180],
            "id": "vision-build-0000-0000-0000-000000000001",
            "name": "Build Vision Request",
        },
        # 16f. Analyze Reference — gpt-4o-mini sees the customer's image.
        {
            "parameters": {
                "method": "POST",
                "url": "https://openrouter.ai/api/v1/chat/completions",
                "authentication": "predefinedCredentialType",
                "nodeCredentialType": "openRouterApi",
                "sendBody": True,
                "specifyBody": "json",
                "jsonBody": "={{ JSON.stringify($json.body) }}",
                "options": {"timeout": 30000},
            },
            "type": "n8n-nodes-base.httpRequest",
            "typeVersion": 4.2,
            "position": [3640, -180],
            "id": "vision-http-0000-0000-0000-000000000001",
            "name": "Analyze Reference",
            "credentials": OPENROUTER,
            "onError": "continueRegularOutput",
        },
        # 16g. Parse Reference Analysis — append the vision summary to the prompt.
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": PARSE_REFERENCE_ANALYSIS_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [3900, -180],
            "id": "vision-parse-0000-0000-0000-000000000001",
            "name": "Parse Reference Analysis",
        },
        # 16g2. Save Reference Analysis — persist the vision result so
        #      `start_turn` can expose it on the following turns.
        {
            "parameters": {
                "method": "POST",
                "url": "https://crm.vaif.com.br/api/references/analysis",
                "authentication": "genericCredentialType",
                "genericAuthType": "httpHeaderAuth",
                "sendBody": True,
                "specifyBody": "json",
                "jsonBody": "={{ JSON.stringify({ artist_id: $('Resolve Artist').first().json.id, project_id: $('Start Turn').first().json.project_id, image_url: $('Save References').first().json.image_url, analysis: $json.reference_analysis }) }}",
                "options": {"timeout": 15000},
            },
            "type": "n8n-nodes-base.httpRequest",
            "typeVersion": 4.2,
            "position": [4160, -180],
            "id": "vision-save-0000-0000-0000-000000000001",
            "name": "Save Reference Analysis",
            "credentials": CRM_INTERNAL,
            "onError": "continueRegularOutput",
        },
        # 16h. No Reference — text-only turn: pass the prompt through unchanged.
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": NO_REFERENCE_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [3380, 60],
            "id": "vision-none-0000-0000-0000-000000000001",
            "name": "No Reference",
        },
        # 17. OpenRouter Chat Model (sub-node)
        {
            "parameters": {"model": "openai/gpt-4o-mini", "options": {"maxTokens": 2000}},
            "type": "@n8n/n8n-nodes-langchain.lmChatOpenRouter",
            "typeVersion": 1,
            "position": [2600, -520],
            "id": "b8c9d0e1-2f3a-4b5c-6d7e-8f9a0b1c2d3e",
            "name": "OpenRouter Chat Model",
            "credentials": OPENROUTER,
        },
        # 18. Postgres Chat Memory (sub-node)
        {
            "parameters": {
                "sessionIdType": "customKey",
                "sessionKey": "={{ $('Start Turn').first().json.lead_id }}",
                "tableName": "chat_memory",
                "contextWindowLength": 20,
            },
            "type": "@n8n/n8n-nodes-langchain.memoryPostgresChat",
            "typeVersion": 1.4,
            "position": [2600, -360],
            "id": "c9d0e1f2-3a4b-5c6d-7e8f-9a0b1c2d3e4f",
            "name": "Postgres Chat Memory",
            "credentials": POSTGRES,
        },
        # 19. AI Agent
        {
            "parameters": {
                "promptType": "define",
                "text": AGENT_TEXT_EXPR,
                "options": {
                    "systemMessage": "={{ $json.system_message }}"
                }
            },
            "type": "@n8n/n8n-nodes-langchain.agent",
            "typeVersion": 3.1,
            "position": [4160, 260],
            "id": "d0e1f2a3-4b5c-6d7e-8f9a-0b1c2d3e4f5a",
            "name": "AI Agent",
        },
        # 21. Write Quote Tool
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM write_quote('{{ $('Resolve Artist').first().json.id }}'::uuid, '{{ $('Start Turn').first().json.project_id }}'::uuid, NULLIF('{{ $fromAI('base_price', 'Preço de tabela em centavos de real', 'number') }}', 'undefined')::integer, NULLIF('{{ $fromAI('offered_price', 'Preço final negociado em centavos de real. Sem desconto, use o mesmo valor de base_price', 'number') }}', 'undefined')::integer)",
                "options": {},
            },
            "type": "n8n-nodes-base.postgresTool",
            "typeVersion": 2.6,
            "position": [2600, -840],
            "id": "p2a3b4c5-6d7e-8a9b-0c1d-2e3f4a5b6c7d",
            "name": "Write Quote",
            "credentials": POSTGRES,
        },
        # 22. Request Deposit Tool
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM request_deposit('{{ $('Resolve Artist').first().json.id }}'::uuid, '{{ $('Start Turn').first().json.project_id }}'::uuid, NULLIF('{{ $fromAI('amount', 'Valor do sinal em centavos de real (ex: R$180 = 18000)', 'number') }}', 'undefined')::integer)",
                "options": {},
            },
            "type": "n8n-nodes-base.postgresTool",
            "typeVersion": 2.6,
            "position": [2600, -960],
            "id": "p3a4b5c6-7d8e-9a0b-1c2d-3e4f5a6b7c8d",
            "name": "Request Deposit",
            "credentials": POSTGRES,
        },
        # 23. Check Availability Tool
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT start_at, end_at, label FROM booking_options('{{ $('Resolve Artist').first().json.id }}'::uuid, now(), now() + interval '60 days', COALESCE(NULLIF('{{ $fromAI('duration_min', 'Duração mínima em minutos — padrão 120', 'number') }}', 'undefined')::integer, 120), NULLIF('{{ ($('Debounce Resolve').first().json.combined_text || '').split(String.fromCharCode(39)).join(String.fromCharCode(39)+String.fromCharCode(39)) }}', ''))",
                "options": {},
            },
            "type": "n8n-nodes-base.postgresTool",
            "typeVersion": 2.6,
            "position": [2600, -1080],
            "id": "p4a5b6c7-8d9e-0a1b-2c3d-4e5f6a7b8c9d",
            "name": "Check Availability",
            "credentials": POSTGRES,
        },
        # 24. Book Slot Tool
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM book_slot('{{ $('Resolve Artist').first().json.id }}'::uuid, '{{ $('Start Turn').first().json.project_id }}'::uuid, '{{ $fromAI('start_at', 'Data/horário de início no formato ISO 8601 COM ANO, ex: 2026-08-15T14:00:00-03:00') }}'::timestamptz, COALESCE(NULLIF('{{ $fromAI('duration_min', 'Duração em minutos usada no Check Availability — padrão 120', 'number') }}', 'undefined')::integer, 120))",
                "options": {},
            },
            "type": "n8n-nodes-base.postgresTool",
            "typeVersion": 2.6,
            "position": [2600, -1200],
            "id": "p5a6b7c8-9d0e-1a2b-3c4d-5e6f7a8b9c0d",
            "name": "Book Slot",
            "credentials": POSTGRES,
        },
        # 25. Build Classification Prompt
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": BUILD_CLASSIFICATION_PROMPT_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [3120, 260],
            "id": "cl-build-0000-0000-0000-000000000001",
            "name": "Build Classification Prompt",
        },
        # 26. Classify Conversation (OpenRouter HTTP)
        {
            "parameters": {
                "method": "POST",
                "url": "https://openrouter.ai/api/v1/chat/completions",
                "authentication": "predefinedCredentialType",
                "nodeCredentialType": "openRouterApi",
                "sendBody": True,
                "specifyBody": "json",
                "jsonBody": "={{ JSON.stringify($json.body) }}",
                "options": {"timeout": 15000},
            },
            "type": "n8n-nodes-base.httpRequest",
            "typeVersion": 4.2,
            "position": [3380, 260],
            "id": "cl-http-0000-0000-0000-000000000001",
            "name": "Classify Conversation",
            "credentials": OPENROUTER,
        },
        # 27. Parse Classification
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": PARSE_CLASSIFICATION_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [3640, 260],
            "id": "cl-parse-0000-0000-0000-000000000001",
            "name": "Parse Classification",
        },
        # 27b. Sync Chat Memory — n8n's memory node saves the model output,
        #      but the final reply may have been gated/prefixed/split. Store
        #      the exact text the lead receives so the CRM panel matches.
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "UPDATE agent_memory.chat_memory SET message = jsonb_set(message, '{content}', to_jsonb($2::text), true) WHERE id = (SELECT id FROM agent_memory.chat_memory WHERE session_id = $1::text AND message->>'type' = 'ai' ORDER BY id DESC LIMIT 1)",
                "options": {
                    "queryReplacement": "={{ [$('Start Turn').first().json.lead_id, $json.final_reply] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [3900, 420],
            "id": "chat-sync-0000-0000-0000-000000000001",
            "name": "Sync Chat Memory",
            "credentials": POSTGRES,
            "onError": "continueRegularOutput",
        },
        # 28. Build Project Patch — the deltas learned this turn, as RPC args.
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": BUILD_PROJECT_PATCH_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [3900, 260],
            "id": "uq-build-0000-0000-0000-000000000001",
            "name": "Build Project Patch",
        },
        # 29. Patch Project — applies the deltas and moves the stage. The
        #     expected stage makes the update optimistic: a tool that already
        #     advanced the Project this turn is never rolled back.
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM patch_project($1::uuid, $2::uuid, $3::text, $4::text, $5::text, $6::text, $7::boolean, $8::text, $9::text, $10::text[], $11::text, $12::text, $13::text);",
                "options": {
                    "queryReplacement": "={{ $json.args }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [4160, 260],
            "id": "uq-update-0000-0000-0000-000000000001",
            "name": "Patch Project",
            "credentials": POSTGRES,
        },
        # 29a. Ensure Quote — persist the deterministic quote when the model did
        #      not write one. Promotion quotes take the branch below instead.
        #      `alwaysOutputData` keeps the main path alive when no quote is due.
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM write_quote($1::uuid, $2::uuid, $3::integer, $4::integer) WHERE $5::boolean IS NOT TRUE AND $3::integer IS NOT NULL AND $4::integer IS NOT NULL;",
                "options": {
                    "queryReplacement": "={{ [$('Resolve Artist').first().json.id, $('Start Turn').first().json.project_id, $('Parse Classification').first().json.quote_base_cents, $('Parse Classification').first().json.quote_offered_cents, $('Parse Classification').first().json.offer_quoted] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [4420, 260],
            "id": "en-quote-0000-0000-0000-000000000001",
            "name": "Ensure Quote",
            "credentials": POSTGRES,
            "alwaysOutputData": True,
        },
        # 29b. Write Promotion Quote — price-protection snapshot for the offer.
        #      Fires only when an offer was actually quoted this turn.
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM write_promotion_quote($1::uuid, $2::uuid, $3::uuid, $4::text, $5::text) WHERE $3::uuid IS NOT NULL AND $4::text IS NOT NULL AND $5::text IS NOT NULL AND $6::boolean IS TRUE;",
                "options": {
                    "queryReplacement": "={{ [$('Resolve Artist').first().json.id, $('Start Turn').first().json.project_id, $('Load Offer').first().json.promotion_id, $('Patch Project').first().json.placement, $('Patch Project').first().json.body_zone, $('Parse Classification').first().json.offer_quoted] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [4420, 480],
            "id": "pq-write-0000-0000-0000-000000000001",
            "name": "Write Promotion Quote",
            "credentials": POSTGRES,
        },
        # 29c. Patch Lead — the classifier may learn the lead's name mid-conversation.
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM upsert_lead($1::uuid, $2::text, $3::text, 'whatsapp') WHERE NULLIF($3, '') IS NOT NULL;",
                "options": {
                    "queryReplacement": "={{ [$('Resolve Artist').first().json.id, $('Start Turn').first().json.phone_e164, $('Parse Classification').first().json.name_val] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [4420, 700],
            "id": "pl-name-0000-0000-0000-000000000001",
            "name": "Patch Lead",
            "credentials": POSTGRES,
        },
        # 29d. Patch Chat State — merge the SDR gate flags into agent_state.
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT patch_chat_state($1::uuid, $2::text, $3::jsonb) AS agent_state;",
                "options": {
                    "queryReplacement": "={{ [$('Resolve Artist').first().json.id, $('Debounce Start').first().json.chat_id, JSON.stringify($('Parse Classification').first().json.chat_state || {})] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [4680, 260],
            "id": "cs-patch-0000-0000-0000-000000000001",
            "name": "Patch Chat State",
            "credentials": POSTGRES,
        },
        # 32b. Build Humanize Delay — reply-time proportional to reply length.
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": HUMANIZE_DELAY_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [5200, 420],
            "id": "hu-build-0000-0000-0000-000000000001",
            "name": "Build Humanize Delay",
        },
        # 32c. Send Typing — show "typing…" while the agent composes.
        # WAHA presence endpoint: startTyping/stopTyping aliases 404 on the
        # deployed version, so use the canonical POST /api/{session}/presence.
        {
            "parameters": {
                "method": "POST",
                "url": "={{ 'https://waha.vaif.com.br/api/' + $('Resolve Artist').first().json.wa_session_slug + '/presence' }}",
                "authentication": "predefinedCredentialType",
                "nodeCredentialType": "wahaApi",
                "sendBody": True,
                "specifyBody": "json",
                "jsonBody": "={{ JSON.stringify({ chatId: $('WAHA Webhook').first().json.body.payload.from, presence: 'typing' }) }}",
                "options": {"timeout": 15000},
            },
            "type": "n8n-nodes-base.httpRequest",
            "typeVersion": 4.2,
            "position": [5460, 420],
            "id": "hu-typing-0000-0000-0000-000000000001",
            "name": "Send Typing",
            "credentials": WAHA,
            "onError": "continueRegularOutput",
        },
        # 32d. Humanize Delay — short, length-scaled pause before replying.
        {
            "parameters": {
                "resume": "timeInterval",
                "amount": "={{ $('Build Humanize Delay').first().json.delay_seconds }}",
                "unit": "seconds",
            },
            "type": "n8n-nodes-base.wait",
            "typeVersion": 1.2,
            "position": [5200, 560],
            "id": "hu-wait-0000-0000-0000-000000000001",
            "name": "Humanize Delay",
        },
        # 32e. Split Reply — one sentence/paragraph per WhatsApp message.
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": SPLIT_REPLY_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [5200, 360],
            "id": "hu-split-0000-0000-0000-000000000001",
            "name": "Split Reply",
        },
        # 33. Send WAHA Message — reply to the lead
        {
            "parameters": {
                "method": "POST",
                "url": "https://waha.vaif.com.br/api/sendText",
                "authentication": "predefinedCredentialType",
                "nodeCredentialType": "wahaApi",
                "sendBody": True,
                "bodyParameters": {
                    "parameters": [
                        {
                            "name": "session",
                            "value": "={{ $('Resolve Artist').first().json.wa_session_slug }}",
                        },
                        {
                            "name": "chatId",
                            "value": "={{ $('WAHA Webhook').first().json.body.payload.from }}",
                        },
                        {
                            "name": "text",
                            "value": "={{ $json.text }}",
                        },
                    ]
                },
                "options": {
                    "timeout": 30000,
                    "batching": {
                        "batch": {
                            "batchSize": 1,
                            "batchInterval": 1200,
                        }
                    },
                },
            },
            "type": "n8n-nodes-base.httpRequest",
            "typeVersion": 4.2,
            "position": [5200, 260],
            "id": "wa-send-0000-0000-0000-000000000001",
            "name": "Send WAHA Message",
            "credentials": WAHA,
            "onError": "continueRegularOutput",
        },
        # 33a2. Aggregate Sent — collapse the per-paragraph HTTP items back to
        #       one so Stop Typing / the notification run exactly once.
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": "return [{ json: { sent: $input.all().length } }];",
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [5460, 260],
            "id": "wa-sent-0000-0000-0000-000000000001",
            "name": "Aggregate Sent",
        },
        # 33b. Stop Typing — clear the composing indicator after the send.
        {
            "parameters": {
                "method": "POST",
                "url": "={{ 'https://waha.vaif.com.br/api/' + $('Resolve Artist').first().json.wa_session_slug + '/presence' }}",
                "authentication": "predefinedCredentialType",
                "nodeCredentialType": "wahaApi",
                "sendBody": True,
                "specifyBody": "json",
                "jsonBody": "={{ JSON.stringify({ chatId: $('WAHA Webhook').first().json.body.payload.from, presence: 'paused' }) }}",
                "options": {"timeout": 15000},
            },
            "type": "n8n-nodes-base.httpRequest",
            "typeVersion": 4.2,
            "position": [5460, 500],
            "id": "hu-stoptyping-0000-0000-0000-000000000001",
            "name": "Stop Typing",
            "credentials": WAHA,
            "onError": "continueRegularOutput",
        },
        # 34. Build Handoff Message
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": BUILD_HANDOFF_MESSAGE_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [5460, 260],
            "id": "hf-build-0000-0000-0000-000000000001",
            "name": "Build Handoff Message",
        },
        # 35. Send Notification?
        {
            "parameters": {
                "conditions": {
                    "options": {
                        "caseSensitive": True,
                        "leftValue": "",
                        "typeValidation": "strict",
                        "version": 3,
                    },
                    "conditions": [
                        {
                            "id": "hf-if-cond-0000",
                            "leftValue": "={{ $json.send }}",
                            "rightValue": True,
                            "operator": {
                                "type": "boolean",
                                "operation": "equals",
                            },
                        }
                    ],
                    "combinator": "and",
                },
                "options": {},
            },
            "type": "n8n-nodes-base.if",
            "typeVersion": 2,
            "position": [5720, 260],
            "id": "hf-if-0000-0000-0000-000000000001",
            "name": "Send Notification?",
        },
        # 36. Send Notification to Group — Telegram ops group with inline
        #     buttons. The Telegram workflow's callback branch handles the
        #     confirm/cancel clicks (handoff → fechado/perdido, deposit → confirmado).
        {
            "parameters": {
                "chatId": "={{ $json.chatId }}",
                "text": "={{ $json.text }}",
                "replyMarkup": "inlineKeyboard",
                "inlineKeyboard": {
                    "rows": [
                        {
                            "row": {
                                "buttons": [
                                    {
                                        "text": "={{ $json.btn1_text }}",
                                        "additionalFields": {
                                            "callback_data": "={{ $json.btn1_cb }}"
                                        },
                                    },
                                    {
                                        "text": "={{ $json.btn2_text }}",
                                        "additionalFields": {
                                            "callback_data": "={{ $json.btn2_cb }}"
                                        },
                                    },
                                ]
                            }
                        }
                    ]
                },
                "additionalFields": {
                    "appendAttribution": False,
                    "parse_mode": "HTML",
                },
            },
            "type": "n8n-nodes-base.telegram",
            "typeVersion": 1.2,
            "position": [5980, 260],
            "id": "tg-notify-0000-0000-0000-000000000001",
            "name": "Send Notification to Group",
            "credentials": TELEGRAM_OPS,
            "onError": "continueRegularOutput",
        },
    ],
    "connections": {
        "WAHA Webhook": {
            "main": [[{"node": "Resolve Artist", "type": "main", "index": 0}]]
        },
        "Resolve Artist": {
            "main": [[{"node": "Artist Found?", "type": "main", "index": 0}]]
        },
        "Artist Found?": {
            "main": [
                [{"node": "Group Chat?", "type": "main", "index": 0}],
                [{"node": "Missing Session Log", "type": "main", "index": 0}],
            ]
        },
        "Group Chat?": {
            "main": [
                [],
                [{"node": "Route Event", "type": "main", "index": 0}],
            ]
        },
        "Route Event": {
            "main": [
                [{"node": "Presence Start", "type": "main", "index": 0}],
                [{"node": "Check AI Window", "type": "main", "index": 0}],
                [],
            ]
        },
        "Presence Start": {
            "main": [[{"node": "Presence Upsert", "type": "main", "index": 0}]]
        },
        "Check AI Window": {
            "main": [[{"node": "Artist Replied?", "type": "main", "index": 0}]]
        },
        "Artist Replied?": {
            "main": [
                [{"node": "Take Chat Control", "type": "main", "index": 0}],
                [{"node": "In AI Window?", "type": "main", "index": 0}],
            ]
        },
        "In AI Window?": {
            "main": [
                [{"node": "Debounce Start", "type": "main", "index": 0}],
                [],
            ]
        },
        "Debounce Start": {
            "main": [[
                {"node": "Debounce Accumulate", "type": "main", "index": 0},
                {"node": "Subscribe Presence", "type": "main", "index": 0},
            ]]
        },
        "Debounce Accumulate": {
            "main": [[{"node": "Wait", "type": "main", "index": 0}]]
        },
        "Wait": {
            "main": [[{"node": "Get Buffer State", "type": "main", "index": 0}]]
        },
        "Get Buffer State": {
            "main": [[{"node": "Debounce Resolve", "type": "main", "index": 0}]]
        },
        "Debounce Resolve": {
            "main": [[{"node": "Debounce Decision", "type": "main", "index": 0}]]
        },
        "Debounce Decision": {
            "main": [
                [{"node": "Clear Buffer", "type": "main", "index": 0}],
                [{"node": "Wait", "type": "main", "index": 0}],
                [],
            ]
        },
        "Clear Buffer": {
            "main": [[{"node": "Check Chat Owner", "type": "main", "index": 0}]]
        },
        "Check Chat Owner": {
            "main": [[{"node": "Chat Owned by Artist?", "type": "main", "index": 0}]]
        },
        "Chat Owned by Artist?": {
            "main": [
                [],
                [{"node": "Start Turn", "type": "main", "index": 0}],
            ]
        },
        "Start Turn": {
            "main": [[{"node": "Agent Blocked?", "type": "main", "index": 0}]]
        },
        "Agent Blocked?": {
            "main": [
                [],
                [{"node": "Build References", "type": "main", "index": 0}],
            ]
        },
        "Build References": {
            "main": [[{"node": "Save References", "type": "main", "index": 0}]]
        },
        "Save References": {
            "main": [[{"node": "Load Offer", "type": "main", "index": 0}]]
        },
        "Load Offer": {
            "main": [[{"node": "Load Pricing", "type": "main", "index": 0}]]
        },
        "Load Pricing": {
            "main": [[{"node": "Build System Prompt", "type": "main", "index": 0}]]
        },
        "Build System Prompt": {
            "main": [[{"node": "Analyze Audio?", "type": "main", "index": 0}]]
        },
        "Analyze Audio?": {
            "main": [
                [{"node": "Fetch Audio", "type": "main", "index": 0}],
                [{"node": "No Audio", "type": "main", "index": 0}],
            ]
        },
        "Fetch Audio": {
            "main": [[{"node": "Extract Audio Base64", "type": "main", "index": 0}]]
        },
        "Extract Audio Base64": {
            "main": [[{"node": "Build Audio Request", "type": "main", "index": 0}]]
        },
        "Build Audio Request": {
            "main": [[{"node": "Audio Ready?", "type": "main", "index": 0}]]
        },
        "Audio Ready?": {
            "main": [
                [{"node": "Transcribe Audio", "type": "main", "index": 0}],
                [{"node": "Parse Audio", "type": "main", "index": 0}],
            ]
        },
        "Transcribe Audio": {
            "main": [[{"node": "Parse Audio", "type": "main", "index": 0}]]
        },
        "Parse Audio": {
            "main": [[{"node": "Analyze Reference?", "type": "main", "index": 0}]]
        },
        "No Audio": {
            "main": [[{"node": "Analyze Reference?", "type": "main", "index": 0}]]
        },
        "Analyze Reference?": {
            "main": [
                [{"node": "Build Vision Request", "type": "main", "index": 0}],
                [{"node": "No Reference", "type": "main", "index": 0}],
            ]
        },
        "Build Vision Request": {
            "main": [[{"node": "Analyze Reference", "type": "main", "index": 0}]]
        },
        "Analyze Reference": {
            "main": [[{"node": "Parse Reference Analysis", "type": "main", "index": 0}]]
        },
        "Parse Reference Analysis": {
            "main": [[
                {"node": "AI Agent", "type": "main", "index": 0},
                {"node": "Save Reference Analysis", "type": "main", "index": 0},
            ]]
        },
        "No Reference": {
            "main": [[{"node": "AI Agent", "type": "main", "index": 0}]]
        },
        "OpenRouter Chat Model": {
            "ai_languageModel": [[{"node": "AI Agent", "type": "ai_languageModel", "index": 0}]]
        },
        "Postgres Chat Memory": {
            "ai_memory": [[{"node": "AI Agent", "type": "ai_memory", "index": 0}]]
        },
        "Write Quote": {
            "ai_tool": [[{"node": "AI Agent", "type": "ai_tool", "index": 0}]]
        },
        "Request Deposit": {
            "ai_tool": [[{"node": "AI Agent", "type": "ai_tool", "index": 0}]]
        },
        "Check Availability": {
            "ai_tool": [[{"node": "AI Agent", "type": "ai_tool", "index": 0}]]
        },
        "Book Slot": {
            "ai_tool": [[{"node": "AI Agent", "type": "ai_tool", "index": 0}]]
        },
        "AI Agent": {
            "main": [[{"node": "Build Classification Prompt", "type": "main", "index": 0}]]
        },
        "Build Classification Prompt": {
            "main": [[{"node": "Classify Conversation", "type": "main", "index": 0}]]
        },
        "Classify Conversation": {
            "main": [[{"node": "Parse Classification", "type": "main", "index": 0}]]
        },
        "Parse Classification": {
            "main": [[
                {"node": "Build Project Patch", "type": "main", "index": 0},
                {"node": "Sync Chat Memory", "type": "main", "index": 0},
            ]]
        },
        "Build Project Patch": {
            "main": [[{"node": "Patch Project", "type": "main", "index": 0}]]
        },
        "Patch Project": {
            "main": [[
                {"node": "Ensure Quote", "type": "main", "index": 0},
                {"node": "Write Promotion Quote", "type": "main", "index": 0},
                {"node": "Patch Lead", "type": "main", "index": 0},
            ]]
        },
        "Ensure Quote": {
            "main": [[{"node": "Patch Chat State", "type": "main", "index": 0}]]
        },
        "Patch Chat State": {
            "main": [[{"node": "Build Humanize Delay", "type": "main", "index": 0}]]
        },
        "Build Humanize Delay": {
            "main": [[{"node": "Send Typing", "type": "main", "index": 0}]]
        },
        "Send Typing": {
            "main": [[{"node": "Humanize Delay", "type": "main", "index": 0}]]
        },
        "Humanize Delay": {
            "main": [[{"node": "Split Reply", "type": "main", "index": 0}]]
        },
        "Split Reply": {
            "main": [[{"node": "Send WAHA Message", "type": "main", "index": 0}]]
        },
        "Send WAHA Message": {
            "main": [[{"node": "Aggregate Sent", "type": "main", "index": 0}]]
        },
        "Aggregate Sent": {
            "main": [[{"node": "Stop Typing", "type": "main", "index": 0}]]
        },
        "Stop Typing": {
            "main": [[{"node": "Build Handoff Message", "type": "main", "index": 0}]]
        },
        "Build Handoff Message": {
            "main": [[{"node": "Send Notification?", "type": "main", "index": 0}]]
        },
        "Send Notification?": {
            "main": [
                [{"node": "Send Notification to Group", "type": "main", "index": 0}],
                [],
            ]
        },
    },
    "settings": {
        "executionOrder": "v1",
    },
}

out_path = os.path.join(BASE, "beatriz-whatsapp-agent.json")
with open(out_path, "w", encoding="utf-8") as f:
    json.dump(WF, f, indent=2, ensure_ascii=False)
print(f"Wrote {out_path}")
