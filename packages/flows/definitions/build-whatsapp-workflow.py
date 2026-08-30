#!/usr/bin/env python3
"""Build the Beatriz WhatsApp workflow JSON definition.

Mirrors the deployed Telegram core agent (debounce, dynamic classification,
guarded pipeline transitions, handoff/deposit notifications) exactly, and
differs ONLY in the transport layer: WAHA webhook/sendText instead of Telegram.

Core (identical to Telegram):
  Resolve Artist → Debounce (buffer + wait for burst) → Upsert Lead
    → AI Agent (GPT-4o-mini + Postgres Chat Memory + tools)
    → Build Classification Prompt → Classify (OpenRouter) → Parse Classification
    → Build Update Query → Update Lead → Log Event → Enqueue Notion Sync
    → reply → Build Handoff Message → Send Notification?

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
"""
import json, os

BASE = os.path.dirname(os.path.abspath(__file__))
FLOWS_ROOT = os.path.dirname(BASE)  # packages/flows/

SYSTEM_PROMPT_TEMPLATE = open(os.path.join(FLOWS_ROOT, "prompts/beatriz-system.md")).read()
CLASSIFY_PROMPT_TEMPLATE = open(os.path.join(FLOWS_ROOT, "prompts/classify-conversation.md")).read()

# ── Credentials (n8n instance) ──
POSTGRES = {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}}
OPENROUTER = {"openRouterApi": {"id": "ow26hPDMir1dMfz0", "name": "OpenRouter account"}}
WAHA = {"wahaApi": {"id": "uQsr20PaYqbQUAGD", "name": "WAHA account"}}

# ── Resolve Artist: session slug -> artist row, plus an explicit `found`
#    boolean. n8n's `isNotEmpty` returns TRUE for null, so a null-uuid row
#    would otherwise take the "found" branch. Gate on `found` instead. ──
RESOLVE_ARTIST_QUERY = """SELECT id, nome, wa_session_slug, status, ai_active_hours, timezone,
       specialties, nao_faco, floor_pct, deposit_type, deposit_value, pix_key,
       instagram_handle, whatsapp_number,
       (id IS NOT NULL) AS found
FROM resolve_artist_from_session($1)
UNION ALL
SELECT NULL::uuid                    AS id,
       NULL::text                    AS nome,
       $1::text                      AS wa_session_slug,
       NULL::text                    AS status,
       NULL::jsonb                   AS ai_active_hours,
       NULL::text                    AS timezone,
       NULL::text[]                  AS specialties,
       NULL::text[]                  AS nao_faco,
       NULL::numeric                 AS floor_pct,
       NULL::text                    AS deposit_type,
       NULL::integer                 AS deposit_value,
       NULL::text                    AS pix_key,
       NULL::text                    AS instagram_handle,
       NULL::text                    AS whatsapp_number,
       false                         AS found
WHERE NOT EXISTS (SELECT 1 FROM resolve_artist_from_session($1))
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
    "  const rows = pricing.map(p => `| ${p.placement} | ${p.body_zone} | ${formatBRL(p.table_price)} |`).join('\\n');\n"
    "  table = '| Local | Tamanho | À Vista |\\n|---|---|---|\\n' + rows;\n"
    "}\n"
    "\n"
    "const sinal = artist.deposit_type === 'fixed'\n"
    "  ? (artist.deposit_value != null ? 'R$ ' + Number(artist.deposit_value) : '30%')\n"
    "  : (artist.deposit_value != null ? Number(artist.deposit_value) + '%' : '30%');\n"
    "const piso = artist.floor_pct != null ? Number(artist.floor_pct) + '%' : '80%';\n"
    "const descontoMax = artist.floor_pct != null ? (100 - Number(artist.floor_pct)) + '%' : '20%';\n"
    "\n"
    "const replacements = {\n"
    "  '{{NOME}}': artist.nome || 'Artista',\n"
    "  '{{INSTAGRAM}}': artist.instagram_handle || '@artista',\n"
    "  '{{PIX}}': artist.pix_key || '(informe a chave PIX)',\n"
    "  '{{SINAL}}': sinal,\n"
    "  '{{PISO}}': piso,\n"
    "  '{{DESCONTO_MAX}}': descontoMax,\n"
    "  '{{TABELA_PRECOS}}': table,\n"
    "};\n"
    "\n"
    "let prompt = " + json.dumps(SYSTEM_PROMPT_TEMPLATE) + ";\n"
    "for (const [k, v] of Object.entries(replacements)) {\n"
    "  prompt = prompt.split(k).join(v);\n"
    "}\n"
    "\n"
    "return [{ json: { system_message: prompt } }];"
)

# ── Agent text: per-turn context + the debounced lead message. Same shape as
#    the Telegram testbed, with artist_id resolved at runtime. ──
AGENT_TEXT_EXPR = (
    "={{ '[Contexto: pipeline=' + $('Upsert Lead').first().json.pipeline_status "
    "+ ' nome=' + ($('Upsert Lead').first().json.nome || 'desconhecido') "
    "+ ' deposit=' + ($('Upsert Lead').first().json.deposit_status || 'nao_solicitado') "
    "+ ' tipo=' + ($('Upsert Lead').first().json.tipo_tatuagem || '?') "
    "+ ' lead_id=' + $('Upsert Lead').first().json.id "
    "+ ' placement=' + ($('Upsert Lead').first().json.placement || '?') "
    "+ ' zona=' + ($('Upsert Lead').first().json.body_zone || '?') "
    "+ ' estilo=' + ($('Upsert Lead').first().json.style || '?') "
    "+ ' primeira_tatuagem=' + ($('Upsert Lead').first().json.primeira_tatuagem === true ? 'sim' : ($('Upsert Lead').first().json.primeira_tatuagem === false ? 'nao' : '?')) "
    "+ ' significado=' + ($('Upsert Lead').first().json.significado || '?') "
    "+ ' preco_tabela=' + ($('Upsert Lead').first().json.table_price || '?') "
    "+ ' preco_negociado=' + ($('Upsert Lead').first().json.negotiated_price || '?') "
    "+ ' data_hoje=' + new Date().toISOString().slice(0, 10) "
    "+ '] [artist_id=' + $('Resolve Artist').first().json.id + '] ' "
    "+ ($('Debounce Resolve').first().json.combined_text || '') }}"
)

# ── Debounce Start: extract chat/phone/text from the WAHA message. chat_id is
#    keyed by session:phone so two artists never share a buffer row. ──
DEBOUNCE_START_JS = """const wa = $('WAHA Webhook').first().json.body || {};
const payload = wa.payload || {};
const session = wa.session || 'unknown';
const from = String(payload.from || '').replace('@c.us', '');
const type = payload.type || 'chat';

let text = payload.body || '';
if (!text) {
  if (type === 'image' || type === 'sticker' || payload.hasMedia) text = '[FOTO RECEBIDA]';
  else if (type === 'ptt' || type === 'audio' || type === 'video') text = '[ÁUDIO RECEBIDO]';
  else text = '[MÍDIA RECEBIDA]';
}

return [{ json: { chat_id: session + ':' + from, phone: from, msg_text: text, msg_ts: Date.now() } }];"""

DEBOUNCE_RESOLVE_JS = """const state = $('Get Buffer State').first().json;
const msgTs = $('Debounce Start').first().json.msg_ts;
const chatId = $('Debounce Start').first().json.chat_id;
const lastTs = state && state.last_msg_at ? new Date(state.last_msg_at).getTime() : msgTs;
const isLast = lastTs <= msgTs;
const combined = (state && state.pending ? state.pending : '').trim();
return [{ json: { is_last: isLast, combined_text: combined, chat_id: chatId } }];"""

# ── Classification: same prompt + parse as the Telegram testbed. ──
BUILD_CLASSIFICATION_PROMPT_JS = (
    "const currentPipeline = $('Upsert Lead').first().json.pipeline_status || 'novo';\n"
    "const userMsg = $('Debounce Resolve').first().json.combined_text || '';\n"
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
const lead = $('Upsert Lead').first().json;
const agentOutput = $('AI Agent').first().json.output || '';

// ── Deterministic tipo_tatuagem guard ──
const rawLead = ($('Debounce Resolve').first().json.combined_text || '').toLowerCase();
function detectTipo(text) {
  if (/cobertura|cobrir|cover[- ]?up|tatuagem por cima|por cima de(?:sta| uma)/.test(text)) return 'cobertura';
  if (/reforma|reformar|retocar|retoque|refazer|redesign|remodelar/.test(text)) return 'reforma';
  if (/tatuagem nova|nova tattoo|tattoo nova|fazer uma nova|uma nova tatuagem/.test(text)) return 'nova';
  return null;
}
const tipoDeterministic = detectTipo(rawLead);

// ── Transition guard — prevent invalid/repeated transitions ──
const current = lead.pipeline_status || 'novo';
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
if (tipoDeterministic === 'nova' && (finalHandoff === 'cover_up' || finalHandoff === 'reforma')) {
  finalHandoff = null;
  if (finalPipeline === 'aguardando_artista') {
    finalPipeline = current;
    finalEvent = null;
  }
}

return [{ json: {
  lead_id: lead.id,
  pipeline_status: finalPipeline,
  event_type: finalEvent,
  price_updated: !!(p.table_cents),

  placement_val: q.placement || null,
  body_zone_val: q.body_zone || null,
  style_val: q.style || null,
  primeira_tatuagem_val: q.first_tattoo || null,
  significado_val: q.significado || null,
  tipo_tatuagem_val: tipoDeterministic || q.tipo_tatuagem || null,

  table_price_cents: p.table_cents || null,
  negotiated_price_cents: p.nego_cents || null,

  deposit_status_val: d.amount_cents ? 'aguardando_confirmacao' : null,
  deposit_amount_cents: d.amount_cents || null,
  booked_date_val: b.date ? (b.date + 'T' + (b.time || '12:00') + ':00') : null,
  session_duration_min_val: null,
  buffer_min_val: null,

  handoff_reason: finalHandoff,
  nome_val: parsed.name || null,
} }];"""

# ── Build Update Query: dynamic SQL. Artist_id threaded for multi-tenancy. ──
BUILD_UPDATE_QUERY_JS = """const f = $input.first().json;
const artistId = $('Resolve Artist').first().json.id;

function esc(s) { return String(s || '').replace(/'/g, "''"); }

const sets = [];

const ps = esc(f.pipeline_status);
sets.push(`pipeline_status = CASE
    WHEN '${ps}' = 'qualificando' AND pipeline_status = 'novo' THEN 'qualificando'::text
    WHEN '${ps}' = 'orcamento_enviado' AND pipeline_status IN ('novo','qualificando') THEN 'orcamento_enviado'::text
    WHEN '${ps}' = 'aguardando_deposito' AND pipeline_status IN ('novo','qualificando','orcamento_enviado') THEN 'aguardando_deposito'::text
    WHEN '${ps}' = 'agendado' AND pipeline_status IN ('novo','qualificando','orcamento_enviado','aguardando_deposito') THEN 'agendado'::text
    WHEN '${ps}' = 'aguardando_artista' AND pipeline_status NOT IN ('fechado','perdido','agendado') THEN 'aguardando_artista'::text
    WHEN '${ps}' = 'bloqueado' AND pipeline_status NOT IN ('fechado','perdido') THEN 'bloqueado'::text
    ELSE pipeline_status END`);

if (f.placement_val && f.placement_val !== 'null') sets.push(`placement = '${esc(f.placement_val)}'`);
if (f.body_zone_val && f.body_zone_val !== 'null') sets.push(`body_zone = '${esc(f.body_zone_val)}'`);
if (f.style_val && f.style_val !== 'null') sets.push(`style = '${esc(f.style_val)}'`);
if (f.significado_val && f.significado_val !== 'null') sets.push(`significado = '${esc(f.significado_val)}'`);
if (f.tipo_tatuagem_val && f.tipo_tatuagem_val !== 'null') sets.push(`tipo_tatuagem = '${esc(f.tipo_tatuagem_val)}'`);
if (f.deposit_status_val && f.deposit_status_val !== 'null') sets.push(`deposit_status = '${esc(f.deposit_status_val)}'`);

if (f.nome_val && f.nome_val !== 'null') sets.push(`nome = '${esc(f.nome_val)}'`);

if (f.table_price_cents !== null && f.table_price_cents !== undefined && f.table_price_cents !== 'null')
  sets.push(`table_price = ${f.table_price_cents}`);
if (f.negotiated_price_cents !== null && f.negotiated_price_cents !== undefined && f.negotiated_price_cents !== 'null')
  sets.push(`negotiated_price = ${f.negotiated_price_cents}`);
if (f.deposit_amount_cents !== null && f.deposit_amount_cents !== undefined && f.deposit_amount_cents !== 'null')
  sets.push(`deposit_amount = ${f.deposit_amount_cents}`);
if (f.session_duration_min_val !== null && f.session_duration_min_val !== undefined && f.session_duration_min_val !== 'null')
  sets.push(`session_duration_min = ${f.session_duration_min_val}`);
if (f.buffer_min_val !== null && f.buffer_min_val !== undefined && f.buffer_min_val !== 'null')
  sets.push(`buffer_min = ${f.buffer_min_val}`);

if (f.primeira_tatuagem_val === true) sets.push(`primeira_tatuagem = true`);
else if (f.primeira_tatuagem_val === false) sets.push(`primeira_tatuagem = false`);

if (f.booked_date_val && f.booked_date_val !== 'null')
  sets.push(`booked_date = '${esc(f.booked_date_val)}'::timestamptz`);

sets.push(`last_message_at = NOW()`);
sets.push(`updated_at = NOW()`);

const query = `UPDATE public.leads SET ${sets.join(', ')} WHERE id = '${esc(String(f.lead_id))}'::uuid AND artist_id = '${artistId}' RETURNING id, pipeline_status, deposit_status;`;

return [{ json: { ...f, query } }];"""

# ── Handoff / deposit notification → Telegram ops group with inline buttons.
#    The Telegram workflow's callback branch handles confirm/cancel (handoff
#    → fechado/perdido, deposit → confirmado/perdido). Same output shape as
#    the Telegram testbed; artist first-name variants keep the handoff
#    fallback working in multi-tenant mode. ──
BUILD_HANDOFF_MESSAGE_JS = r"""const parseData = $('Parse Classification').first().json;
const updateData = $('Update Lead').first().json;
const leadData = $('Upsert Lead').first().json;
const artistData = $('Resolve Artist').first().json;
const agentOutput = ($('AI Agent').first().json.output || '').toLowerCase();

const event = (parseData && parseData.event_type) || null;
const status = (updateData && updateData.pipeline_status)
  || (parseData && parseData.pipeline_status)
  || '';

const leadNome = leadData && leadData.nome ? leadData.nome : 'Desconhecido';
const telefone = leadData && leadData.telefone ? leadData.telefone : '';
const whatsappLink = telefone && /^\d{8,15}$/.test(telefone)
  ? 'https://wa.me/' + telefone
  : null;

// Fallback: detect a handoff directly from Beatriz's message if the
// classifier did not emit event=handoff_triggered. Include the artist's own
// first name (multi-tenant) plus generic phrases.
const artistNome = artistData && artistData.nome ? String(artistData.nome).toLowerCase() : '';
const handoffPhrases = [
  'vou te passar pro artista', 'vou passar pro artista', 'te passar pro artista',
  'deixa eu te passar', 'vou te passar pro', 'passar o lead pro artista'
];
if (artistNome) {
  handoffPhrases.push('vou te passar pro ' + artistNome, 'vou passar pro ' + artistNome, 'passar o lead pro ' + artistNome);
}
const saidHandoff = handoffPhrases.some(p => agentOutput.includes(p));

// Fallback: detect a deposit request directly from Beatriz's message.
const depositPhrases = ['sinal de', 'sinal é de', 'chave pix', 'pix:', '% do valor'];
const saidDeposit = depositPhrases.some(p => agentOutput.includes(p));

// Only notify on an actual transition — event_type is set only when state changed
if (!event && !saidHandoff && !saidDeposit) {
  return [{ json: { send: false } }];
}

// ── Handoff ──
if (event === 'handoff_triggered' || saidHandoff) {
  const leadId = (parseData && parseData.lead_id) || '';
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
  if (telefone) msg.push('<b>Telefone:</b> ' + telefone);
  if (whatsappLink) msg.push('<a href="' + whatsappLink + '">Abrir no WhatsApp</a>');

  return [{ json: {
    send: true,
    chatId: '-5195870017',
    text: msg.join('\n'),
    btn1_text: '\u2705 Confirmar',
    btn1_cb: 'handoff:confirm:' + leadId,
    btn2_text: '\u274C Cancelar',
    btn2_cb: 'handoff:cancel:' + leadId,
  } }];
}

// ── Deposit requested ──
if (event === 'deposit_requested' || saidDeposit) {
  const leadId = parseData.lead_id || '';
  const amount = parseData.deposit_amount_cents
    ? 'R$ ' + (parseData.deposit_amount_cents / 100).toFixed(2).replace('.', ',')
    : 'valor pendente';
  const msg = [
    '<b>\u{1F4B0} Sinal solicitado</b>',
    '<b>Lead:</b> ' + leadNome,
    '<b>Valor:</b> ' + amount,
    '<i>Confirme o recebimento do PIX</i>',
  ].join('\n');

  return [{ json: {
    send: true,
    chatId: '-5195870017',
    text: msg,
    btn1_text: '\u2705 Sinal Recebido',
    btn1_cb: 'deposit:confirm:' + leadId,
    btn2_text: '\u274C Não Pago',
    btn2_cb: 'deposit:cancel:' + leadId,
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
            },
            "type": "n8n-nodes-base.webhook",
            "typeVersion": 2,
            "position": [0, 0],
            "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
            "name": "WAHA Webhook",
            "webhookId": "ef3df1a1-7f34-47a1-aadb-4fb9d1de5e39",
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
        # 5. Check AI Window — silent outside the artist's AI active window
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": """const artist = $('Resolve Artist').first().json;
const hours = artist.ai_active_hours;

if (!hours || !hours.start || !hours.end) {
  return [{ json: { in_window: true } }];
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

return [{ json: { in_window: inWindow } }];""",
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [780, -240],
            "id": "wa-gate-code-0000-0000-0000-000000000001",
            "name": "Check AI Window",
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
        # 8. Debounce Accumulate
        {
            "parameters": {
                "operation": "executeQuery",
                "query": """INSERT INTO message_buffer (chat_id, pending, last_msg_at)
VALUES ($1, $2, to_timestamp($3::float8 / 1000.0))
ON CONFLICT (chat_id) DO UPDATE SET
  pending = COALESCE(message_buffer.pending, '') || E'\n' || $2,
  last_msg_at = to_timestamp($3::float8 / 1000.0)
RETURNING pending;""",
                "options": {
                    "queryReplacement": "={{ [$json.chat_id, $json.msg_text, $json.msg_ts] }}"
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
                "query": "SELECT last_msg_at, pending FROM message_buffer WHERE chat_id = $1;",
                "options": {
                    "queryReplacement": "={{ [$('Debounce Start').first().json.chat_id] }}"
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
        # 12. IF Last?
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
                            "id": "db-if-last-cond-0000",
                            "leftValue": "={{ $json.is_last }}",
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
            "position": [2340, 0],
            "id": "db-if-last-0000-0000-0000-000000000001",
            "name": "IF Last?",
        },
        # 13. Clear Buffer (true branch)
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "UPDATE message_buffer SET pending = NULL WHERE chat_id = $1;",
                "options": {
                    "queryReplacement": "={{ [$json.chat_id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [2600, 0],
            "id": "db-clear-0000-0000-0000-000000000001",
            "name": "Clear Buffer",
            "credentials": POSTGRES,
        },
        # 14. Load Pricing — artist price table for the dynamic prompt
        {
            "parameters": {
                "operation": "executeQuery",
                "query": """SELECT placement, body_zone, table_price, session_duration_min, buffer_min
FROM pricing
WHERE artist_id = $1::uuid
UNION ALL
SELECT NULL::text, NULL::text, NULL::integer, NULL::integer, NULL::integer
WHERE NOT EXISTS (SELECT 1 FROM pricing WHERE artist_id = $1::uuid)
ORDER BY placement, body_zone;""",
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
        # 16. Upsert Lead — create or load the lead for this phone+artist
        {
            "parameters": {
                "operation": "executeQuery",
                "query": """WITH existing AS (
  SELECT id, pipeline_status, deposit_status, nome, placement, body_zone, style, primeira_tatuagem, significado, tipo_tatuagem, table_price, negotiated_price
  FROM public.leads
  WHERE artist_id = $3::uuid
    AND telefone = $1
),
inserted AS (
  INSERT INTO public.leads (artist_id, nome, telefone, pipeline_status, conversation_started)
  SELECT
    $3::uuid,
    NULLIF($2, ''),
    $1,
    'novo',
    NOW()
  WHERE NOT EXISTS (SELECT 1 FROM existing)
  RETURNING id, pipeline_status, deposit_status, nome, placement, body_zone, style, primeira_tatuagem, significado, tipo_tatuagem, table_price, negotiated_price
)
SELECT * FROM existing
UNION ALL
SELECT * FROM inserted;""",
                "options": {
                    "queryReplacement": "={{ [$('Debounce Start').first().json.phone, ($('WAHA Webhook').first().json.body.payload._data?.notifyName || ''), $('Resolve Artist').first().json.id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [2600, 260],
            "id": "upsert-lead-0000-0000-0000-000000000001",
            "name": "Upsert Lead",
            "credentials": POSTGRES,
        },
        # 17. OpenRouter Chat Model (sub-node)
        {
            "parameters": {"model": "openai/gpt-4o-mini", "options": {}},
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
                "sessionKey": "={{ $('Upsert Lead').first().json.id }}",
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
                    "systemMessage": "={{ $('Build System Prompt').first().json.system_message }}"
                }
            },
            "type": "@n8n/n8n-nodes-langchain.agent",
            "typeVersion": 3.1,
            "position": [2860, 260],
            "id": "d0e1f2a3-4b5c-6d7e-8f9a-0b1c2d3e4f5a",
            "name": "AI Agent",
        },
        # 20. Lookup Price Tool
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT placement, body_zone, table_price, session_duration_min, buffer_min FROM lookup_price('{{ $fromAI('placement', 'Placement da tatuagem, ex: braco, costas, perna') }}', '{{ $fromAI('body_zone', 'Zona corporal, ex: pequeno, medio, grande, fechamento') }}', '{{ $('Resolve Artist').first().json.id }}'::uuid)",
                "options": {},
            },
            "type": "n8n-nodes-base.postgresTool",
            "typeVersion": 2.6,
            "position": [2600, -720],
            "id": "p1a2b3c4-5d6e-7f8a-9b0c-1d2e3f4a5b6c",
            "name": "Lookup Price",
            "credentials": POSTGRES,
        },
        # 21. Write Quote Tool
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM write_quote('{{ $fromAI('lead_id', 'UUID do lead — está no contexto da mensagem no formato [Contexto: lead_id=UUID]') }}'::uuid, {{ $fromAI('table_price', 'Preço de tabela em centavos de real', 'number') }}, {{ $fromAI('negotiated_price', 'Preço negociado em centavos de real. Se não houve desconto, use o mesmo valor de table_price', 'number') }})",
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
                "query": "SELECT * FROM request_deposit('{{ $fromAI('lead_id', 'UUID do lead — está no contexto da mensagem') }}'::uuid, {{ $fromAI('amount', 'Valor do sinal em centavos de real (ex: R$180 = 18000)', 'number') }})",
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
                "query": "SELECT id, start_at, end_at, type FROM check_availability('{{ $('Resolve Artist').first().json.id }}'::uuid, date_trunc('day', now())::timestamptz, now() + interval '60 days', {{ $fromAI('duration_min', 'Duração mínima em minutos — padrão 120', 'number') }}) ORDER BY start_at LIMIT 10",
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
                "query": "SELECT * FROM book_slot('{{ $fromAI('lead_id', 'UUID do lead — está no contexto da mensagem') }}'::uuid, '{{ $fromAI('start_at', 'Data/horário de início no formato ISO 8601, ex: 2026-08-15T14:00:00-03:00') }}'::timestamptz, {{ $fromAI('duration_min', 'Duração da sessão em minutos (do lookup_price)', 'number') }}, {{ $fromAI('buffer_min', 'Buffer em minutos (do lookup_price, padrão 30)', 'number') }})",
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
        # 28. Build Update Query
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": BUILD_UPDATE_QUERY_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [3900, 260],
            "id": "uq-build-0000-0000-0000-000000000001",
            "name": "Build Update Query",
        },
        # 29. Update Lead
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "={{ $json.query }}",
                "options": {},
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [4160, 260],
            "id": "uq-update-0000-0000-0000-000000000001",
            "name": "Update Lead",
            "credentials": POSTGRES,
        },
        # 30. Log Event — reads the parse output (Build Update Query carries it)
        {
            "parameters": {
                "operation": "executeQuery",
                "query": """INSERT INTO public.events (lead_id, artist_id, event_type, payload)
SELECT $1::uuid, $4::uuid, $2, $3::jsonb
WHERE $2 IS NOT NULL AND $2 != '';""",
                "options": {
                    "queryReplacement": "={{ [$('Build Update Query').first().json.lead_id, $('Build Update Query').first().json.event_type, JSON.stringify({ pipeline_status: $('Build Update Query').first().json.pipeline_status, price_updated: $('Build Update Query').first().json.price_updated, placement: $('Build Update Query').first().json.placement_val, body_zone: $('Build Update Query').first().json.body_zone_val, style: $('Build Update Query').first().json.style_val, handoff_reason: $('Build Update Query').first().json.handoff_reason, deposit_status: $('Build Update Query').first().json.deposit_status_val, booked_date: $('Build Update Query').first().json.booked_date_val }), $('Resolve Artist').first().json.id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [4420, 260],
            "id": "ev-log-0000-0000-0000-000000000001",
            "name": "Log Event",
            "credentials": POSTGRES,
            "onError": "continueRegularOutput",
        },
        # 31. Build Notion Sync Payload
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": """const lead = $('Upsert Lead').first().json;
const parsed = $('Parse Classification').first().json;
const artist = $('Resolve Artist').first().json;

return [{
  json: {
    lead_id: lead.id,
    artist_id: artist.id,
    nome: lead.nome,
    telefone: lead.telefone,
    pipeline_status: parsed.pipeline_status,
    placement: parsed.placement_val,
    body_zone: parsed.body_zone_val,
    style: parsed.style_val,
    tipo_tatuagem: parsed.tipo_tatuagem_val,
    table_price: parsed.table_price_cents,
    negotiated_price: parsed.negotiated_price_cents,
    deposit_status: parsed.deposit_status_val,
    deposit_amount: parsed.deposit_amount_cents,
    booked_date: parsed.booked_date_val,
    session_duration_min: parsed.session_duration_min_val,
    handoff_reason: parsed.handoff_reason,
  }
}];""",
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [4680, 260],
            "id": "ns-payload-0000-0000-0000-000000000001",
            "name": "Build Notion Sync Payload",
        },
        # 32. Enqueue Notion Sync
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT enqueue_notion_sync($1::uuid, $2::uuid, $3::jsonb);",
                "options": {
                    "queryReplacement": "={{ [$json.lead_id, $json.artist_id, JSON.stringify($json)] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [4940, 260],
            "id": "ns-enqueue-0000-0000-0000-000000000001",
            "name": "Enqueue Notion Sync",
            "credentials": POSTGRES,
            "onError": "continueRegularOutput",
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
                            "value": "={{ $('AI Agent').first().json.output }}",
                        },
                    ]
                },
                "options": {
                    "timeout": 30000,
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
            "credentials": {"telegramApi": {"id": "ddaVhX88IF54TVAS", "name": "Telegram account 2"}},
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
                [{"node": "Check AI Window", "type": "main", "index": 0}],
                [{"node": "Missing Session Log", "type": "main", "index": 0}],
            ]
        },
        "Check AI Window": {
            "main": [[{"node": "In AI Window?", "type": "main", "index": 0}]]
        },
        "In AI Window?": {
            "main": [
                [{"node": "Debounce Start", "type": "main", "index": 0}],
                [],
            ]
        },
        "Debounce Start": {
            "main": [[{"node": "Debounce Accumulate", "type": "main", "index": 0}]]
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
            "main": [[{"node": "IF Last?", "type": "main", "index": 0}]]
        },
        "IF Last?": {
            "main": [
                [{"node": "Clear Buffer", "type": "main", "index": 0}],
                [],
            ]
        },
        "Clear Buffer": {
            "main": [[{"node": "Upsert Lead", "type": "main", "index": 0}]]
        },
        "Upsert Lead": {
            "main": [[{"node": "Load Pricing", "type": "main", "index": 0}]]
        },
        "Load Pricing": {
            "main": [[{"node": "Build System Prompt", "type": "main", "index": 0}]]
        },
        "Build System Prompt": {
            "main": [[{"node": "AI Agent", "type": "main", "index": 0}]]
        },
        "OpenRouter Chat Model": {
            "ai_languageModel": [[{"node": "AI Agent", "type": "ai_languageModel", "index": 0}]]
        },
        "Postgres Chat Memory": {
            "ai_memory": [[{"node": "AI Agent", "type": "ai_memory", "index": 0}]]
        },
        "Lookup Price": {
            "ai_tool": [[{"node": "AI Agent", "type": "ai_tool", "index": 0}]]
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
            "main": [[{"node": "Build Update Query", "type": "main", "index": 0}]]
        },
        "Build Update Query": {
            "main": [[{"node": "Update Lead", "type": "main", "index": 0}]]
        },
        "Update Lead": {
            "main": [[{"node": "Log Event", "type": "main", "index": 0}]]
        },
        "Log Event": {
            "main": [[{"node": "Build Notion Sync Payload", "type": "main", "index": 0}]]
        },
        "Build Notion Sync Payload": {
            "main": [[{"node": "Enqueue Notion Sync", "type": "main", "index": 0}]]
        },
        "Enqueue Notion Sync": {
            "main": [[{"node": "Send WAHA Message", "type": "main", "index": 0}]]
        },
        "Send WAHA Message": {
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