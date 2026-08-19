#!/usr/bin/env python3
"""Build the Beatriz Telegram workflow JSON definition."""
import json, os

BASE = os.path.dirname(os.path.abspath(__file__))
FLOWS_ROOT = os.path.dirname(BASE)  # packages/flows/

BUILD_UPDATE_QUERY_JS = r"""const f = $input.first().json;

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

const query = `UPDATE public.leads SET ${sets.join(', ')} WHERE id = '${esc(String(f.lead_id))}'::uuid AND artist_id = 'b0000000-0000-0000-0000-000000000001' RETURNING id, pipeline_status, deposit_status;`;

return { json: { ...f, query } };
"""

WF = {
    "name": "Beatriz Telegram + Postgres",
    "description": "Beatriz SDR agent on Telegram with Postgres persistence — lead CRUD, qualification extraction, pipeline transitions, pricing/booking tools, Chat Memory, and events log.",
    "nodes": [
        # 1. Telegram Trigger (message + callback_query)
        {
            "parameters": {"updates": ["message", "callback_query"], "additionalFields": {}},
            "type": "n8n-nodes-base.telegramTrigger", "typeVersion": 1.3,
            "position": [0, 0],
            "id": "8c02b733-18a0-4742-a568-6f3226cc702b",
            "name": "Telegram Trigger",
            "webhookId": "06e7e698-5f49-4d18-bfb8-503c25609e16",
            "credentials": {"telegramApi": {"id": "ddaVhX88IF54TVAS", "name": "Telegram account 2"}},
        },
        # 1a. Detect Update Type (Code node — clean boolean)
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": "const update = $input.first().json;\nconst isCallback = !!(update && update.callback_query);\nreturn [{ json: { isCallback } }];",
            },
            "type": "n8n-nodes-base.code", "typeVersion": 2,
            "position": [65, 0],
            "id": "detect-update-type-v2-0000-000000000001",
            "name": "Detect Update Type",
        },
        # 1b. IF — Is this a callback_query?
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
                            "id": "if-callback-cond-v2-0000",
                            "leftValue": "={{ $json.isCallback }}",
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
            "type": "n8n-nodes-base.if", "typeVersion": 2,
            "position": [130, 0],
            "id": "cb-if-v2-0000-0000-0000-000000000001",
            "name": "Is Callback?",
        },
        # 1d. Debounce Start — capture chat, message text, timestamp
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": "const msg = $('Telegram Trigger').first().json.message || {};\nconst chatId = String(msg.chat ? msg.chat.id : '');\nconst text = msg.text || (msg.photo ? '[FOTO RECEBIDA]' : (msg.voice ? '[ÁUDIO RECEBIDO]' : '[MÍDIA RECEBIDA]'));\nreturn [{ json: { chat_id: chatId, msg_text: text, msg_ts: Date.now() } }];",
            },
            "type": "n8n-nodes-base.code", "typeVersion": 2,
            "position": [390, 200],
            "id": "debounce-start-v2-0000-000000000001",
            "name": "Debounce Start",
        },
        # 1e. Debounce Accumulate — append message to buffer, mark time
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "INSERT INTO message_buffer (chat_id, pending, last_msg_at)\nVALUES ($1, $2, to_timestamp($3::float8 / 1000.0))\nON CONFLICT (chat_id) DO UPDATE SET\n  pending = COALESCE(message_buffer.pending, '') || E'\\n' || $2,\n  last_msg_at = to_timestamp($3::float8 / 1000.0)\nRETURNING pending;",
                "options": {
                    "queryReplacement": "={{ [$json.chat_id, $json.msg_text, $json.msg_ts] }}"
                }
            },
            "type": "n8n-nodes-base.postgres", "typeVersion": 2.6,
            "position": [520, 200],
            "id": "debounce-accumulate-v2-0000-000000000001",
            "name": "Debounce Accumulate",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
            "onError": "continueRegularOutput",
        },
        # 1f. Wait — give the user time to finish typing
        {
            "parameters": {
                "resume": "timeInterval",
                "amount": 3,
                "unit": "seconds",
            },
            "type": "n8n-nodes-base.wait", "typeVersion": 1.2,
            "position": [650, 200],
            "id": "debounce-wait-v2-0000-000000000001",
            "name": "Wait",
        },
        # 1g. Get Buffer State — read accumulated messages + last timestamp
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT last_msg_at, pending FROM message_buffer WHERE chat_id = $1;",
                "options": {
                    "queryReplacement": "={{ [$('Debounce Start').first().json.chat_id] }}"
                }
            },
            "type": "n8n-nodes-base.postgres", "typeVersion": 2.6,
            "position": [780, 200],
            "id": "debounce-getstate-v2-0000-000000000001",
            "name": "Get Buffer State",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
            "onError": "continueRegularOutput",
        },
        # 1h. Debounce Resolve — only the LAST message in a burst proceeds
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": "const state = $('Get Buffer State').first().json;\nconst msgTs = $('Debounce Start').first().json.msg_ts;\nconst chatId = $('Debounce Start').first().json.chat_id;\nconst lastTs = state && state.last_msg_at ? new Date(state.last_msg_at).getTime() : msgTs;\nconst isLast = lastTs <= msgTs;\nconst combined = (state && state.pending ? state.pending : '').trim();\nreturn [{ json: { is_last: isLast, combined_text: combined, chat_id: chatId } }];",
            },
            "type": "n8n-nodes-base.code", "typeVersion": 2,
            "position": [910, 200],
            "id": "debounce-resolve-v2-0000-000000000001",
            "name": "Debounce Resolve",
        },
        # 1i. IF Last? — gate: only process the final message of a burst
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
                            "id": "if-last-cond-v2-0000",
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
            "type": "n8n-nodes-base.if", "typeVersion": 2,
            "position": [1040, 200],
            "id": "if-last-v2-0000-0000-000000000001",
            "name": "IF Last?",
        },
        # 1j. Clear Buffer — reset pending before processing
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "UPDATE message_buffer SET pending = NULL WHERE chat_id = $1;",
                "options": {
                    "queryReplacement": "={{ [$json.chat_id] }}"
                }
            },
            "type": "n8n-nodes-base.postgres", "typeVersion": 2.6,
            "position": [1170, 200],
            "id": "debounce-clear-v2-0000-000000000001",
            "name": "Clear Buffer",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
            "onError": "continueRegularOutput",
        },
        # 1b. Parse Callback — extract action and lead_id from callback_data
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": """const cq = $('Telegram Trigger').first().json.callback_query;
const data = (cq.data || '').split(':');
const type = data[0];
const action = data[1] || null;
const leadId = data[2] || null;
const messageId = cq.message ? cq.message.message_id : null;
const chatId = cq.message ? cq.message.chat.id : null;
const callbackId = cq.id;

let query = null;
let answerText = '';
let editText = '';

if (type === 'handoff') {
  if (action === 'confirm') {
    query = 'UPDATE public.leads SET pipeline_status = \\'fechado\\' WHERE id = \\'' + leadId + '\\'::uuid RETURNING id, pipeline_status;';
    answerText = '\\u2705 Handoff aceito! Lead movido para fechado.';
    editText = '<b>\\u2705 Handoff aceito</b>';
  } else if (action === 'cancel') {
    query = 'UPDATE public.leads SET pipeline_status = \\'perdido\\' WHERE id = \\'' + leadId + '\\'::uuid RETURNING id, pipeline_status;';
    answerText = '\\u274C Handoff recusado. Lead movido para perdido.';
    editText = '<b>\\u274C Handoff recusado</b>';
  }
} else if (type === 'deposit') {
  if (action === 'confirm') {
    query = 'UPDATE public.leads SET deposit_status = \\'confirmado\\' WHERE id = \\'' + leadId + '\\'::uuid RETURNING id, pipeline_status;';
    answerText = '\\u2705 Sinal confirmado! Prossiga com o agendamento.';
    editText = '<b>\\u2705 Sinal Recebido \\u2014 Pronto para agendamento</b>';
  } else if (action === 'cancel') {
    query = 'UPDATE public.leads SET pipeline_status = \\'perdido\\' WHERE id = \\'' + leadId + '\\'::uuid RETURNING id, pipeline_status;';
    answerText = '\\u274C Sinal n\\u00E3o recebido. Lead movido para perdido.';
    editText = '<b>\\u274C Sinal N\\u00E3o Recebido</b>';
  }
}

return [{ json: {
  type,
  action,
  lead_id: leadId,
  answer_text: answerText,
  edit_text: editText,
  callback_id: callbackId,
  message_id: messageId,
  chat_id: chatId,
  query,
} }];""",
            },
            "type": "n8n-nodes-base.code", "typeVersion": 2,
            "position": [390, -200],
            "id": "cb-parse-0000-0000-0000-000000000001",
            "name": "Parse Callback",
        },
        # 1c. Update Lead (callback) — updates pipeline_status
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "={{ $json.query }}",
            },
            "type": "n8n-nodes-base.postgres", "typeVersion": 2.6,
            "position": [650, -200],
            "id": "cb-update-0000-0000-0000-000000000001",
            "name": "Update Lead (Callback)",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
            "onError": "continueRegularOutput",
        },
        # 1d. Answer Callback Query — acknowledge button press
        {
            "parameters": {
                "resource": "callback",
                "operation": "answerQuery",
                "queryId": "={{ $('Parse Callback').first().json.callback_id }}",
                "text": "={{ $('Parse Callback').first().json.answer_text }}",
                "additionalFields": {},
            },
            "type": "n8n-nodes-base.telegram", "typeVersion": 1.2,
            "position": [910, -200],
            "id": "cb-answer-0000-0000-0000-000000000001",
            "name": "Answer Callback",
            "credentials": {"telegramApi": {"id": "ddaVhX88IF54TVAS", "name": "Telegram account 2"}},
        },
        # 1e. Edit Message — remove buttons, show result
        {
            "parameters": {
                "resource": "message",
                "operation": "editMessageText",
                "messageType": "message",
                "chatId": "={{ $('Parse Callback').first().json.chat_id }}",
                "messageId": "={{ $('Parse Callback').first().json.message_id }}",
                "text": "={{ $('Parse Callback').first().json.edit_text }}",
                "additionalFields": {"parse_mode": "HTML"},
            },
            "type": "n8n-nodes-base.telegram", "typeVersion": 1.2,
            "position": [1170, -200],
            "id": "cb-edit-0000-0000-0000-000000000001",
            "name": "Edit Handoff Message",
            "credentials": {"telegramApi": {"id": "ddaVhX88IF54TVAS", "name": "Telegram account 2"}},
        },
        # 2. Upsert Lead
        {
            "parameters": {
                "operation": "executeQuery",
                "query": """WITH existing AS (
  SELECT id, pipeline_status, deposit_status, nome, placement, body_zone, style, primeira_tatuagem, significado, table_price, negotiated_price
  FROM public.leads
  WHERE artist_id = 'b0000000-0000-0000-0000-000000000001'
    AND telefone = $1
),
inserted AS (
  INSERT INTO public.leads (artist_id, nome, telefone, pipeline_status, conversation_started)
  SELECT 
    'b0000000-0000-0000-0000-000000000001',
    NULL,
    $1,
    'novo',
    NOW()
  WHERE NOT EXISTS (SELECT 1 FROM existing)
  RETURNING id, pipeline_status, deposit_status, nome, placement, body_zone, style, primeira_tatuagem, significado, table_price, negotiated_price
)
SELECT * FROM existing
UNION ALL
SELECT * FROM inserted;""",
                "options": {
                    "queryReplacement": "={{ [String($('Telegram Trigger').first().json.message.chat.id)] }}"
                }
            },
            "type": "n8n-nodes-base.postgres", "typeVersion": 2.6,
            "position": [260, 0],
            "id": "cca53318-4515-4d7e-aecd-a74bfff6a9a3",
            "name": "Upsert Lead",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 4. OpenRouter Chat Model (sub-node)
        {
            "parameters": {"model": "openai/gpt-4o-mini", "options": {}},
            "type": "@n8n/n8n-nodes-langchain.lmChatOpenRouter", "typeVersion": 1,
            "position": [640, 208],
            "id": "77685fee-9485-4f52-b5de-a802538e6535",
            "name": "OpenRouter Chat Model",
            "credentials": {"openRouterApi": {"id": "ow26hPDMir1dMfz0", "name": "OpenRouter account"}},
        },
        # 5. Postgres Chat Memory (sub-node)
        {
            "parameters": {
                "sessionIdType": "customKey",
                "sessionKey": "={{ $('Upsert Lead').first().json.id }}",
                "tableName": "chat_memory",
                "contextWindowLength": 20,
            },
            "type": "@n8n/n8n-nodes-langchain.memoryPostgresChat", "typeVersion": 1.4,
            "position": [800, 208],
            "id": "8d65f170-9308-40a5-8cc9-ee9dc6b63a2c",
            "name": "Postgres Chat Memory",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 6. AI Agent (conversational — calendar tools)
        {
            "parameters": {
                "promptType": "define",
                "text": "={{ '[Contexto: pipeline=' + $('Upsert Lead').first().json.pipeline_status + ' nome=' + ($('Upsert Lead').first().json.nome || 'desconhecido') + ' deposit=' + ($('Upsert Lead').first().json.deposit_status || 'nao_solicitado') + ' lead_id=' + $('Upsert Lead').first().json.id + ' placement=' + ($('Upsert Lead').first().json.placement || '?') + ' zona=' + ($('Upsert Lead').first().json.body_zone || '?') + ' estilo=' + ($('Upsert Lead').first().json.style || '?') + ' primeira_tatuagem=' + ($('Upsert Lead').first().json.primeira_tatuagem === true ? 'sim' : ($('Upsert Lead').first().json.primeira_tatuagem === false ? 'nao' : '?')) + ' significado=' + ($('Upsert Lead').first().json.significado || '?') + ' preco_tabela=' + ($('Upsert Lead').first().json.table_price || '?') + ' preco_negociado=' + ($('Upsert Lead').first().json.negotiated_price || '?') + ' data_hoje=' + new Date().toISOString().slice(0, 10) + '] [artist_id=b0000000-0000-0000-0000-000000000001] ' + ($('Debounce Resolve').first().json.combined_text || '') }}",
                "options": {
                    "systemMessage": open(os.path.join(FLOWS_ROOT, "prompts/beatriz-system.md")).read()
                }
            },
            "type": "@n8n/n8n-nodes-langchain.agent", "typeVersion": 3.1,
            "position": [780, 0],
            "id": "59824855-22b2-4a55-8bcc-22959cec9723",
            "name": "AI Agent",
        },
        # 6b. Check Availability Tool — next open slots from calendar
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT id, start_at, end_at, type FROM check_availability('b0000000-0000-0000-0000-000000000001'::uuid, date_trunc('day', now())::timestamptz, now() + interval '60 days', {{ $fromAI('duration_min', 'Duração mínima da sessão em minutos — padrão 120', 'number') }}) ORDER BY start_at LIMIT 10",
                "options": {},
            },
            "type": "n8n-nodes-base.postgresTool", "typeVersion": 2.6,
            "position": [780, 340],
            "id": "tg-tool-avail-0000-0000-000000000001",
            "name": "Check Availability",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 6c. Book Slot Tool — reserves the chosen slot
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM book_slot('{{ $fromAI('lead_id', 'UUID do lead — está no contexto da mensagem no formato [Contexto: lead_id=UUID]') }}'::uuid, '{{ $fromAI('start_at', 'Data/horário ISO 8601 exato do slot escolhido, ex: 2026-08-15T09:00:00-03:00') }}'::timestamptz, {{ $fromAI('duration_min', 'Duração da sessão em minutos — padrão 120', 'number') }}, {{ $fromAI('buffer_min', 'Buffer em minutos — padrão 30', 'number') }})",
                "options": {},
            },
            "type": "n8n-nodes-base.postgresTool", "typeVersion": 2.6,
            "position": [780, 460],
            "id": "tg-tool-book-0000-0000-000000000002",
            "name": "Book Slot",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 7. Build Classification Prompt (Code node — feeds LLM classifier)
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": """const currentPipeline = $('Upsert Lead').first().json.pipeline_status || 'novo';
const userMsg = $('Debounce Resolve').first().json.combined_text || '';
const agentOutput = $('AI Agent').first().json.output || '';

const sysPrompt = `""" + open(os.path.join(FLOWS_ROOT, "prompts/classify-conversation.md")).read().replace('{{pipeline}}', '${currentPipeline}') + """`;

return [{ json: {
  body: {
    model: 'openai/gpt-4o-mini',
    temperature: 0,
    messages: [
      { role: 'system', content: sysPrompt },
      { role: 'user', content: 'Lead: ' + userMsg + '\\n\\nBeatriz: ' + agentOutput }
    ]
  }
} }];""",
            },
            "type": "n8n-nodes-base.code", "typeVersion": 2,
            "position": [1040, 0],
            "id": "cf-build-prompt-0000-0000-0000-000000001",
            "name": "Build Classification Prompt",
        },
        # 7b. Classify Conversation (HTTP to OpenRouter)
        {
            "parameters": {
                "method": "POST",
                "url": "https://openrouter.ai/api/v1/chat/completions",
                "authentication": "none",
                "sendHeaders": True,
                "headerParameters": {
                    "parameters": [
                        {"name": "Authorization", "value": "={{ 'Bearer ' + $vars.OPENROUTER_API_KEY }}"},
                        {"name": "Content-Type", "value": "application/json"},
                    ]
                },
                "sendBody": True,
                "specifyBody": "json",
                "jsonBody": "={{ JSON.stringify($json.body) }}",
                "options": {"timeout": 10000},
            },
            "type": "n8n-nodes-base.httpRequest", "typeVersion": 4.2,
            "position": [1300, 0],
            "id": "cf-http-classify-0000-0000-0000-000000001",
            "name": "Classify Conversation",
        },
        # 7c. Parse Classification Response (Code node)
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": """const resp = $input.first().json;
const content = (resp.choices && resp.choices[0] && resp.choices[0].message && resp.choices[0].message.content) || '{}';
let parsed;
try { parsed = JSON.parse(content); } catch(e) { parsed = {}; }

const q = parsed.qualification || {};
const p = parsed.pricing || {};
const d = parsed.deposit || {};
const h = parsed.handoff || {};
const b = parsed.booking || {};
const lead = $('Upsert Lead').first().json;

// ── Transition guard — prevent invalid/repeated transitions ──
const current = lead.pipeline_status || 'novo';
const proposed = parsed.pipeline || current;

// Terminal states never change
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
  // Terminal — no changes, no events
  finalPipeline = current;
  finalEvent = null;
} else if (validFrom[current] && validFrom[current].includes(proposed)) {
  finalPipeline = proposed;
  // If no actual transition, suppress event (prevents repeated notifications)
  if (proposed === current) finalEvent = null;
} else {
  // Invalid transition — keep current, suppress event
  finalPipeline = current;
  finalEvent = null;
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

  table_price_cents: p.table_cents || null,
  negotiated_price_cents: p.nego_cents || null,

  deposit_status_val: d.amount_cents ? 'aguardando_confirmacao' : null,
  deposit_amount_cents: d.amount_cents || null,
  booked_date_val: b.date ? (b.date + 'T' + (b.time || '12:00') + ':00') : null,
  session_duration_min_val: null,
  buffer_min_val: null,

  handoff_reason: h.reason || null,

  nome_val: parsed.name || null,
} }];""",
            },
            "type": "n8n-nodes-base.code", "typeVersion": 2,
            "position": [1560, 0],
            "id": "cf-parse-classify-0000-0000-0000-000000001",
            "name": "Parse Classification",
        },
        # 8. Build Update Query (Code node — constructs SQL without queryReplacement)
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": BUILD_UPDATE_QUERY_JS,
            },
            "type": "n8n-nodes-base.code", "typeVersion": 2,
            "position": [1300, 0],
            "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
            "name": "Build Update Query",
        },
        # 9. Update Lead (executes the query built by Build Update Query)
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "={{ $json.query }}",
            },
            "type": "n8n-nodes-base.postgres", "typeVersion": 2.6,
            "position": [1560, 420],
            "id": "e3c4d5e6-7f8a-9b0c-1d2e-3f4a5b6c7d8e",
            "name": "Update Lead",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
            "onError": "continueRegularOutput",
        },
        # 10. Log Event
        {
            "parameters": {
                "operation": "executeQuery",
                "query": """INSERT INTO public.events (lead_id, artist_id, event_type, payload)
SELECT $1::uuid, 'b0000000-0000-0000-0000-000000000001', $2, $3::jsonb
WHERE $2 IS NOT NULL AND $2 != '';""",
                "options": {
                    "queryReplacement": "={{ [$json.lead_id, $json.event_type, JSON.stringify({ pipeline_status: $json.pipeline_status, price_updated: $json.price_updated, placement: $json.placement_val, body_zone: $json.body_zone_val, style: $json.style_val, handoff_reason: $json.handoff_reason, deposit_status: $json.deposit_status_val, booked_date: $json.booked_date_val }) ] }}"
                }
            },
            "type": "n8n-nodes-base.postgres", "typeVersion": 2.6,
            "position": [1820, 420],
            "id": "f4d5e6f7-8a9b-0c1d-2e3f-4a5b6c7d8e9f",
            "name": "Log Event",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
            "onError": "continueRegularOutput",
        },
        # 10a. Build Notion Sync Payload (Code)
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": """const lead = $('Upsert Lead').first().json;
const parsed = $('Parse Classification').first().json;

return [{
  json: {
    lead_id: lead.id,
    artist_id: 'b0000000-0000-0000-0000-000000000001',
    nome: lead.nome,
    telefone: lead.telefone,
    pipeline_status: parsed.pipeline_status,
    placement: parsed.placement_val,
    body_zone: parsed.body_zone_val,
    style: parsed.style_val,
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
            "type": "n8n-nodes-base.code", "typeVersion": 2,
            "position": [2080, 0],
            "id": "n0t1i2o3-4444-5555-6666-77778888aaaa",
            "name": "Build Notion Sync Payload",
        },
        # 10b. Enqueue Notion Sync (outbox — fast local insert, guaranteed delivery)
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT enqueue_notion_sync($1::uuid, $2::uuid, $3::jsonb);",
                "options": {
                    "queryReplacement": "={{ [$json.lead_id, $json.artist_id, JSON.stringify($json)] }}"
                },
            },
            "type": "n8n-nodes-base.postgres", "typeVersion": 2.6,
            "position": [2340, 0],
            "id": "n0t1i2o3-5555-6666-7777-8888aaaabbbb",
            "name": "Enqueue Notion Sync",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
            "onError": "continueRegularOutput",
        },
        # 11. Send Telegram Reply
        {
            "parameters": {
                "chatId": "={{ $('Telegram Trigger').first().json.message.chat.id }}",
                "text": "={{ $('AI Agent').first().json.output }}",
                "additionalFields": {},
            },
            "type": "n8n-nodes-base.telegram", "typeVersion": 1.2,
            "position": [2080, 420],
            "id": "980d11b3-4057-45b5-bdd3-1259e43ae685",
            "name": "Send a text message",
            "webhookId": "9520c6f6-ea33-4705-ae68-c269c8582ec8",
            "credentials": {"telegramApi": {"id": "ddaVhX88IF54TVAS", "name": "Telegram account 2"}},
        },
        # 12. Notification — sends alerts to artist group for handoff, deposit, booking
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": """const parseData = $('Parse Classification').first().json;
const updateData = $('Update Lead').first().json;
const leadData = $('Upsert Lead').first().json;
const agentOutput = ($('AI Agent').first().json.output || '').toLowerCase();

const event = (parseData && parseData.event_type) || null;
const status = (updateData && updateData.pipeline_status)
  || (parseData && parseData.pipeline_status)
  || '';

const leadNome = leadData && leadData.nome ? leadData.nome : 'Desconhecido';
const telefone = leadData && leadData.telefone ? leadData.telefone : '';
const whatsappLink = telefone && /^\\d{8,15}$/.test(telefone)
  ? 'https://wa.me/' + telefone
  : null;

// Fallback: detect a handoff directly from Beatriz's message if the
// classifier did not emit event=handoff_triggered (e.g. no slots available).
const handoffPhrases = ['vou te passar pro bruno', 'vou passar pro bruno', 'te passar pro artista', 'deixa eu te passar', 'vou te passar pro artista', 'passar o lead pro bruno'];
const saidHandoff = handoffPhrases.some(p => agentOutput.includes(p));

// Fallback: detect a deposit request directly from Beatriz's message if the
// classifier did not emit event=deposit_requested.
const depositPhrases = ['sinal de', 'sinal é de', 'chave pix', 'pix:', 'bruno.tattoo@pix.com.br', '30% do valor'];
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
    lead_requested_artist: 'Lead pediu o artista',
    below_piso: 'Contraproposta abaixo do piso',
    vague: 'Descrição vaga',
    audio_2x: 'Áudio/sticker repetido',
    no_availability: 'Sem horário disponível no calendário',
  };
  const reason = reasonMap[parseData.handoff_reason] || parseData.handoff_reason || 'n\\u00E3o especificado';

  const msg = [
    '<b>\\u{1F514} Handoff: Lead precisa de aten\\u00E7\\u00E3o</b>',
    '<b>Lead:</b> ' + leadNome,
    '<b>Motivo:</b> ' + reason,
  ];
  if (telefone) msg.push('<b>Telefone:</b> ' + telefone);
  if (whatsappLink) msg.push('<a href=\\"' + whatsappLink + '\\">Abrir no WhatsApp</a>');

  return [{ json: {
    send: true,
    chatId: '-5195870017',
    text: msg.join('\\n'),
    btn1_text: '\\u2705 Confirmar',
    btn1_cb: 'handoff:confirm:' + leadId,
    btn2_text: '\\u274C Cancelar',
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
    '<b>\\u{1F4B0} Sinal solicitado</b>',
    '<b>Lead:</b> ' + leadNome,
    '<b>Valor:</b> ' + amount,
    '<i>Confirme o recebimento do PIX</i>',
  ].join('\\n');

  return [{ json: {
    send: true,
    chatId: '-5195870017',
    text: msg,
    btn1_text: '\\u2705 Sinal Recebido',
    btn1_cb: 'deposit:confirm:' + leadId,
    btn2_text: '\\u274C N\\u00E3o Pago',
    btn2_cb: 'deposit:cancel:' + leadId,
  } }];
}

// ── No match — no notification ──
return [{ json: { send: false } }];""",
            },
            "type": "n8n-nodes-base.code", "typeVersion": 2,
            "position": [2340, 420],
            "id": "a0b1c2d3-6666-7777-8888-99990000aaaa",
            "name": "Build Handoff Message",
        },
        # 13. Send Notification? — gate to avoid sending empty messages
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
                            "id": "send-notif-cond-v2-0000",
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
            "type": "n8n-nodes-base.if", "typeVersion": 2,
            "position": [2600, 300],
            "id": "send-notif-if-v2-0000-0000-000000000001",
            "name": "Send Notification?",
        },
        # 14. Send Handoff to Artist Group (with inline keyboard buttons)
        {
            "parameters": {
                "resource": "message",
                "operation": "sendMessage",
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
                                        "additionalFields": {"callback_data": "={{ $json.btn1_cb }}"}
                                    },
                                    {
                                        "text": "={{ $json.btn2_text }}",
                                        "additionalFields": {"callback_data": "={{ $json.btn2_cb }}"}
                                    }
                                ]
                            }
                        }
                    ]
                },
                "additionalFields": {"parse_mode": "HTML", "appendAttribution": False},
            },
            "type": "n8n-nodes-base.telegram", "typeVersion": 1.2,
            "position": [2600, 420],
            "id": "b1c2d3e4-7777-8888-9999-aaaabbbbcccc",
            "name": "Send Handoff to Group",
            "credentials": {"telegramApi": {"id": "ddaVhX88IF54TVAS", "name": "Telegram account 2"}},
        },
    ],
    "connections": {
        "Telegram Trigger": {
            "main": [[{"node": "Detect Update Type", "type": "main", "index": 0}]]
        },
        "Detect Update Type": {
            "main": [[{"node": "Is Callback?", "type": "main", "index": 0}]]
        },
        "Is Callback?": {
            "main": [
                [{"node": "Parse Callback", "type": "main", "index": 0}],
                [{"node": "Debounce Start", "type": "main", "index": 0}],
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
        "Parse Callback": {
            "main": [[{"node": "Update Lead (Callback)", "type": "main", "index": 0}]]
        },
        "Update Lead (Callback)": {
            "main": [[{"node": "Answer Callback", "type": "main", "index": 0}]]
        },
        "Answer Callback": {
            "main": [[{"node": "Edit Handoff Message", "type": "main", "index": 0}]]
        },
        "Upsert Lead": {
            "main": [[{"node": "AI Agent", "type": "main", "index": 0}]]
        },
        "OpenRouter Chat Model": {
            "ai_languageModel": [[{"node": "AI Agent", "type": "ai_languageModel", "index": 0}]]
        },
        "Postgres Chat Memory": {
            "ai_memory": [[{"node": "AI Agent", "type": "ai_memory", "index": 0}]]
        },
        "Check Availability": {
            "ai_tool": [[{"node": "AI Agent", "type": "ai_tool", "index": 0}]]
        },
        "Book Slot": {
            "ai_tool": [[{"node": "AI Agent", "type": "ai_tool", "index": 0}]]
        },
        "AI Agent": {
            "main": [[
                {"node": "Build Classification Prompt", "type": "main", "index": 0},
            ]]
        },
        "Parse Classification": {
            "main": [[{"node": "Build Update Query", "type": "main", "index": 0}]]
        },
        "Build Classification Prompt": {
            "main": [[{"node": "Classify Conversation", "type": "main", "index": 0}]]
        },
        "Classify Conversation": {
            "main": [[{"node": "Parse Classification", "type": "main", "index": 0}]]
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
            "main": [[{"node": "Send a text message", "type": "main", "index": 0}]]
        },

        "Send a text message": {
            "main": [[{"node": "Build Handoff Message", "type": "main", "index": 0}]]
        },
        "Build Handoff Message": {
            "main": [[{"node": "Send Notification?", "type": "main", "index": 0}]]
        },
        "Send Notification?": {
            "main": [
                [{"node": "Send Handoff to Group", "type": "main", "index": 0}],
                [],
            ]
        },
    },
    "settings": {
        "executionOrder": "v1",
    },
}

out_path = os.path.join(BASE, "beatriz-telegram.json")
with open(out_path, "w", encoding="utf-8") as f:
    json.dump(WF, f, indent=2, ensure_ascii=False)
print(f"Wrote {out_path}")
