#!/usr/bin/env python3
"""Build the Beatriz WhatsApp workflow JSON definition with pricing/booking tools.

Mirrors the Telegram v2 architecture — same Postgres pipeline, parse-extract
logic, and system prompt. Swaps only the transport layer:
  Telegram Trigger → WAHA Webhook (native n8n webhook node)
  Telegram Reply   → WAHA HTTP Request (sendText API)

Multi-tenant: resolves artist_id from WAHA session slug at runtime.
The Resolve Artist query uses UNION ALL to guarantee one output row even
when lookup finds nothing (n8n Postgres nodes skip downstream on empty).

v3 adds 5 AI Agent tools (lookup_price, write_quote, request_deposit,
check_availability, book_slot) for the pricing/booking engine (#4).
"""
import json, os

BASE = os.path.dirname(os.path.abspath(__file__))
FLOWS_ROOT = os.path.dirname(BASE)  # packages/flows/

SYSTEM_PROMPT = open(os.path.join(FLOWS_ROOT, "prompts/beatriz-system.md")).read()
PARSE_EXTRACT_CODE = open(os.path.join(BASE, "code/parse-extract-whatsapp.js")).read()

# ── Agent text with injected context: lead_id, artista, pipeline ──
AGENT_TEXT_EXPR = (
    "={{ '[Contexto: lead_id=' + $('Upsert Lead').first().json.id "
    "+ ', artista=' + ($('Resolve Artist').first().json.nome || 'artista') "
    "+ ', pipeline=' + $('Upsert Lead').first().json.pipeline_status + '] '"
    "+ ($('WAHA Webhook').first().json.payload?.body || '') }}"
)

WF = {
    "name": "Beatriz WhatsApp Agent",
    "description": "Beatriz SDR agent on WhatsApp via WAHA with Postgres persistence — multi-tenant session routing, lead CRUD, qualification extraction, pricing/booking tools, Chat Memory, and events log.",
    "nodes": [
        # 1. WAHA Webhook
        {
            "parameters": {
                "httpMethod": "POST",
                "path": "waha-webhook",
                "responseMode": "responseNode",
                "options": {},
            },
            "type": "n8n-nodes-base.webhook",
            "typeVersion": 2,
            "position": [0, 0],
            "id": "a1b2c3d4-5e6f-7a8b-9c0d-1e2f3a4b5c6d",
            "name": "WAHA Webhook",
            "webhookId": "ef3df1a1-7f34-47a1-aadb-4fb9d1de5e39",
        },
        # 2. Resolve Artist — UNION ALL always returns 1 row
        {
            "parameters": {
                "operation": "executeQuery",
                "query": """SELECT id, nome, wa_session_slug, status
FROM resolve_artist_from_session($1)
UNION ALL
SELECT NULL::uuid                    AS id,
       NULL::text                    AS nome,
       $1::text                      AS wa_session_slug,
       NULL::text                    AS status
WHERE NOT EXISTS (SELECT 1 FROM resolve_artist_from_session($1))
LIMIT 1;""",
                "options": {
                    "queryReplacement": "={{ [$json.session] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [260, 0],
            "id": "b2c3d4e5-6f7a-8b9c-0d1e-2f3a4b5c6d7e",
            "name": "Resolve Artist",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 3. Artist Found?
        {
            "parameters": {
                "conditions": {
                    "string": [
                        {
                            "value1": "={{ $json.id }}",
                            "operation": "isNotEmpty",
                        }
                    ]
                }
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
                "jsCode": """const wa = $('WAHA Webhook').first().json;
const slug = wa.session || 'unknown';

console.error('[Beatriz-WhatsApp] WAHA session not found in artists table:', slug);

return [{
  json: {
    error: true,
    reason: 'session_not_found',
    session_slug: slug,
  }
}];""",
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [780, 200],
            "id": "d4e5f6a7-8b9c-0d1e-2f3b-4a5b6c7d8e9f",
            "name": "Missing Session Log",
        },
        # 5. Session Missing Response (false branch)
        {
            "parameters": {
                "respondWith": "json",
                "responseBody": "={{ JSON.stringify({ status: 'session_not_found' }) }}",
            },
            "type": "n8n-nodes-base.respondToWebhook",
            "typeVersion": 1.2,
            "position": [1040, 200],
            "id": "e5f6a7b8-9c0d-1e2f-3a4b-5b6c7d8e9f0b",
            "name": "Session Missing Response",
        },
        # 6. Set Artist Context (true branch)
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT set_artist_context($1::uuid);",
                "options": {
                    "queryReplacement": "={{ [$json.id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [780, -200],
            "id": "f6a7b8c9-0d1e-2f3b-4a5b-6c7d8e9f0a1b",
            "name": "Set Artist Context",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 7. Upsert Lead
        {
            "parameters": {
                "operation": "executeQuery",
                "query": """WITH existing AS (
  SELECT id, pipeline_status, nome, placement, body_zone, style, primeira_tatuagem, significado, table_price, negotiated_price
  FROM public.leads
  WHERE artist_id = current_setting('app.artist_id')::uuid
    AND telefone = $1
),
inserted AS (
  INSERT INTO public.leads (artist_id, nome, telefone, pipeline_status, conversation_started)
  SELECT 
    current_setting('app.artist_id')::uuid,
    $2,
    $1,
    'novo',
    NOW()
  WHERE NOT EXISTS (SELECT 1 FROM existing)
  RETURNING id, pipeline_status, nome, placement, body_zone, style, primeira_tatuagem, significado, table_price, negotiated_price
)
SELECT * FROM existing
UNION ALL
SELECT * FROM inserted;""",
                "options": {
                    "queryReplacement": "={{ [$('WAHA Webhook').first().json.payload.from.replace('@c.us', ''), ($('WAHA Webhook').first().json.payload._data?.notifyName || '')] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1040, -200],
            "id": "a7b8c9d0-1e2f-3a4b-5c6d-7e8f9a0b1c2d",
            "name": "Upsert Lead",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 8. OpenRouter Chat Model (sub-node)
        {
            "parameters": {"model": "openai/gpt-4o-mini", "options": {}},
            "type": "@n8n/n8n-nodes-langchain.lmChatOpenRouter",
            "typeVersion": 1,
            "position": [1160, -40],
            "id": "b8c9d0e1-2f3a-4b5c-6d7e-8f9a0b1c2d3e",
            "name": "OpenRouter Chat Model",
            "credentials": {"openRouterApi": {"id": "ow26hPDMir1dMfz0", "name": "OpenRouter account"}},
        },
        # 9. Postgres Chat Memory (sub-node)
        {
            "parameters": {
                "sessionIdType": "customKey",
                "sessionKey": "={{ $('Upsert Lead').first().json.id }}",
                "tableName": "chat_memory",
                "contextOutputLength": 10,
            },
            "type": "@n8n/n8n-nodes-langchain.memoryPostgresChat",
            "typeVersion": 1.4,
            "position": [1320, -40],
            "id": "c9d0e1f2-3a4b-5c6d-7e8f-9a0b1c2d3e4f",
            "name": "Postgres Chat Memory",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 10. AI Agent
        {
            "parameters": {
                "promptType": "define",
                "text": AGENT_TEXT_EXPR,
                "options": {
                    "systemMessage": SYSTEM_PROMPT
                }
            },
            "type": "@n8n/n8n-nodes-langchain.agent",
            "typeVersion": 3.1,
            "position": [1300, -200],
            "id": "d0e1f2a3-4b5c-6d7e-8f9a-0b1c2d3e4f5a",
            "name": "AI Agent",
        },

        # ── Pricing/Booking Tool Nodes (#4) ──

        # 11. Lookup Price Tool
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT placement, body_zone, table_price, session_duration_min, buffer_min FROM lookup_price($fromAI(placement, 'Placement da tatuagem, ex: braco, costas, perna'), $fromAI(body_zone, 'Zona corporal, ex: pequeno, medio, grande, fechamento'), current_setting('app.artist_id')::uuid)",
                "options": {},
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1560, -60],
            "id": "p1a2b3c4-5d6e-7f8a-9b0c-1d2e3f4a5b6c",
            "name": "Lookup Price",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 12. Write Quote Tool
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM write_quote($fromAI(lead_id, 'UUID do lead — está no contexto da mensagem no formato [Contexto: lead_id=UUID]'), $fromAI(table_price, 'Preço de tabela em centavos de real'), $fromAI(negotiated_price, 'Preço negociado em centavos de real. Se não houve desconto, use o mesmo valor de table_price'))",
                "options": {},
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1560, 60],
            "id": "p2a3b4c5-6d7e-8a9b-0c1d-2e3f4a5b6c7d",
            "name": "Write Quote",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 13. Request Deposit Tool
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM request_deposit($fromAI(lead_id, 'UUID do lead — está no contexto da mensagem'), $fromAI(amount, 'Valor do sinal em centavos de real (ex: R$180 = 18000)'))",
                "options": {},
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1560, 180],
            "id": "p3a4b5c6-7d8e-9a0b-1c2d-3e4f5a6b7c8d",
            "name": "Request Deposit",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 14. Check Availability Tool
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT id, start_at, end_at, type FROM check_availability(current_setting('app.artist_id')::uuid, $fromAI(from_date, 'Data inicial no formato ISO 8601, ex: 2026-08-01T00:00:00-03:00')::timestamptz, $fromAI(to_date, 'Data final no formato ISO 8601, ex: 2026-08-30T23:59:59-03:00')::timestamptz, $fromAI(duration_min, 'Duração mínima em minutos'))",
                "options": {},
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1560, 300],
            "id": "p4a5b6c7-8d9e-0a1b-2c3d-4e5f6a7b8c9d",
            "name": "Check Availability",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 15. Book Slot Tool
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM book_slot($fromAI(lead_id, 'UUID do lead — está no contexto da mensagem'), $fromAI(start_at, 'Data/horário de início no formato ISO 8601, ex: 2026-08-15T14:00:00-03:00')::timestamptz, $fromAI(duration_min, 'Duração da sessão em minutos (do lookup_price)'), $fromAI(buffer_min, 'Buffer em minutos (do lookup_price, padrão 30)'))",
                "options": {},
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1560, 420],
            "id": "p5a6b7c8-9d0e-1a2b-3c4d-5e6f7a8b9c0d",
            "name": "Book Slot",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },

        # 16. Parse & Extract Fields (Code node)
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": PARSE_EXTRACT_CODE,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1820, -200],
            "id": "e1f2a3b4-5c6d-7e8f-9a0b-1c2d3e4f5a6b",
            "name": "Parse & Extract Fields",
            "onError": "continueRegularOutput",
        },
        # 17. Update Lead
        {
            "parameters": {
                "operation": "executeQuery",
                "query": """UPDATE public.leads SET
  pipeline_status = CASE
    WHEN $2 = 'qualificando' AND pipeline_status = 'novo' THEN 'qualificando'::text
    WHEN $2 = 'orcamento_enviado' AND pipeline_status IN ('novo','qualificando') THEN 'orcamento_enviado'::text
    WHEN $2 = 'aguardando_deposito' AND pipeline_status IN ('novo','qualificando','orcamento_enviado') THEN 'aguardando_deposito'::text
    WHEN $2 = 'agendado' AND pipeline_status IN ('novo','qualificando','orcamento_enviado','aguardando_deposito') THEN 'agendado'::text
    WHEN $2 = 'aguardando_artista' AND pipeline_status NOT IN ('fechado','perdido','agendado') THEN 'aguardando_artista'::text
    ELSE pipeline_status
  END,
  deposit_status = COALESCE(NULLIF($10, ''), deposit_status),
  deposit_amount = COALESCE($11::integer, deposit_amount),
  booked_date = COALESCE($12::timestamptz, booked_date),
  session_duration_min = COALESCE($13::integer, session_duration_min),
  buffer_min = COALESCE($14::integer, buffer_min),
  placement = COALESCE(NULLIF($3, ''), placement),
  body_zone = COALESCE(NULLIF($4, ''), body_zone),
  style = COALESCE(NULLIF($5, ''), style),
  primeira_tatuagem = CASE WHEN $6::text IS NULL THEN primeira_tatuagem ELSE $6::boolean END,
  significado = COALESCE(NULLIF($7, ''), significado),
  table_price = COALESCE($8::integer, table_price),
  negotiated_price = COALESCE($9::integer, negotiated_price),
  last_message_at = NOW(),
  updated_at = NOW()
WHERE id = $1::uuid
  AND artist_id = current_setting('app.artist_id')::uuid
RETURNING id, pipeline_status;""",
                "options": {
                    "queryReplacement": "={{ [$json.lead_id, $json.pipeline_status, $json.placement_val, $json.body_zone_val, $json.style_val, String($json.primeira_tatuagem_val), $json.significado_val, $json.table_price_cents, $json.negotiated_price_cents, $json.deposit_status_val, $json.deposit_amount_cents, $json.booked_date_val, $json.session_duration_min_val, $json.buffer_min_val] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [2080, -200],
            "id": "f2a3b4c5-6d7e-8f9a-0b1c-2d3e4f5a6b7c",
            "name": "Update Lead",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
            "onError": "continueRegularOutput",
        },
        # 18. Log Event
        {
            "parameters": {
                "operation": "executeQuery",
                "query": """INSERT INTO public.events (lead_id, artist_id, event_type, payload)
SELECT $1::uuid, current_setting('app.artist_id')::uuid, $2, $3::jsonb
WHERE $2 IS NOT NULL AND $2 != '';""",
                "options": {
                    "queryReplacement": "={{ [$json.lead_id, $json.event_type, JSON.stringify({ pipeline_status: $json.pipeline_status, price_updated: $json.price_updated, placement: $json.placement_val, body_zone: $json.body_zone_val, style: $json.style_val, handoff_reason: $json.handoff_reason, deposit_status: $json.deposit_status_val, booked_date: $json.booked_date_val }) ] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [2340, -200],
            "id": "a3b4c5d6-7e8f-9a0b-1c2d-3e4f5a6b7c8d",
            "name": "Log Event",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
            "onError": "continueRegularOutput",
        },
        # 19. Send WAHA Message
        {
            "parameters": {
                "method": "POST",
                "url": "={{ 'http://localhost:3000/api/sendText' }}",
                "authentication": "none",
                "sendBody": True,
                "bodyParameters": {
                    "parameters": [
                        {
                            "name": "session",
                            "value": "={{ $('Resolve Artist').first().json.wa_session_slug }}",
                        },
                        {
                            "name": "chatId",
                            "value": "={{ $('WAHA Webhook').first().json.payload.from }}",
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
            "position": [2600, -200],
            "id": "b4c5d6e7-8f9a-0b1c-2d3e-4f5a6b7c8d9e",
            "name": "Send WAHA Message",
        },
        # 20. Webhook Response (200 OK — true branch)
        {
            "parameters": {
                "respondWith": "json",
                "responseBody": "={{ JSON.stringify({ status: 'ok' }) }}",
            },
            "type": "n8n-nodes-base.respondToWebhook",
            "typeVersion": 1.2,
            "position": [2860, -200],
            "id": "c5d6e7f8-9a0b-1c2d-3e4f-5a6b7c8d9e0f",
            "name": "Webhook Response",
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
                [{"node": "Set Artist Context", "type": "main", "index": 0}],
                [{"node": "Missing Session Log", "type": "main", "index": 0}],
            ]
        },
        "Missing Session Log": {
            "main": [[{"node": "Session Missing Response", "type": "main", "index": 0}]]
        },
        "Set Artist Context": {
            "main": [[{"node": "Upsert Lead", "type": "main", "index": 0}]]
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
            "main": [[{"node": "Parse & Extract Fields", "type": "main", "index": 0}]]
        },
        "Parse & Extract Fields": {
            "main": [[{"node": "Update Lead", "type": "main", "index": 0}]]
        },
        "Update Lead": {
            "main": [[{"node": "Log Event", "type": "main", "index": 0}]]
        },
        "Log Event": {
            "main": [[{"node": "Send WAHA Message", "type": "main", "index": 0}]]
        },
        "Send WAHA Message": {
            "main": [[{"node": "Webhook Response", "type": "main", "index": 0}]]
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
