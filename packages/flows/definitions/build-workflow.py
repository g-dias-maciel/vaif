#!/usr/bin/env python3
"""Build the Beatriz Telegram workflow JSON definition."""
import json, os

BASE = os.path.dirname(os.path.abspath(__file__))
FLOWS_ROOT = os.path.dirname(BASE)  # packages/flows/

WF = {
    "name": "Beatriz Telegram + Postgres",
    "description": "Beatriz SDR agent on Telegram with Postgres persistence — lead CRUD, qualification extraction, pipeline transitions, Chat Memory, and events log.",
    "nodes": [
        # 1. Telegram Trigger
        {
            "parameters": {"updates": ["message"], "additionalFields": {}},
            "type": "n8n-nodes-base.telegramTrigger", "typeVersion": 1.3,
            "position": [0, 0],
            "id": "d1bafbeb-2583-43ff-b165-dfeeee604cbd",
            "name": "Telegram Trigger",
            "webhookId": "25d44a59-cb86-489c-a2ef-1cc1259a6f64",
            "credentials": {"telegramApi": {"id": "ddaVhX88IF54TVAS", "name": "Telegram account 2"}},
        },
        # 2. Set Artist Context
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT set_artist_context('b0000000-0000-0000-0000-000000000001');",
            },
            "type": "n8n-nodes-base.postgres", "typeVersion": 2.6,
            "position": [260, 0],
            "id": "e4d4a0a7-ba5d-49fc-8491-e86ebb478cde",
            "name": "Set Artist Context",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 3. Upsert Lead
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
                    "queryReplacement": "={{ [$json.message.chat.id, ($json.message.from.first_name + ' ' + ($json.message.from.last_name || '')).trim()] }}"
                }
            },
            "type": "n8n-nodes-base.postgres", "typeVersion": 2.6,
            "position": [520, 0],
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
                "contextOutputLength": 10,
            },
            "type": "@n8n/n8n-nodes-langchain.memoryPostgresChat", "typeVersion": 1.4,
            "position": [800, 208],
            "id": "8d65f170-9308-40a5-8cc9-ee9dc6b63a2c",
            "name": "Postgres Chat Memory",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 6. AI Agent
        {
            "parameters": {
                "promptType": "define",
                "text": "={{ $json.message.text }}",
                "options": {
                    "systemMessage": open(os.path.join(FLOWS_ROOT, "prompts/beatriz-system.md")).read()
                }
            },
            "type": "@n8n/n8n-nodes-langchain.agent", "typeVersion": 3.1,
            "position": [780, 0],
            "id": "59824855-22b2-4a55-8bcc-22959cec9723",
            "name": "AI Agent",
        },
        # 7. Parse & Extract Fields (Code node)
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": open(os.path.join(BASE, "code/parse-extract.js")).read(),
            },
            "type": "n8n-nodes-base.code", "typeVersion": 2,
            "position": [1040, 0],
            "id": "d2b3c4d5-6e7f-8a9b-0c1d-2e3f4a5b6c7d",
            "name": "Parse & Extract Fields",
            "onError": "continueRegularOutput",
        },
        # 8. Update Lead
        {
            "parameters": {
                "operation": "executeQuery",
                "query": """UPDATE public.leads SET
  pipeline_status = CASE
    WHEN $2 = 'qualificando' AND pipeline_status = 'novo' THEN 'qualificando'::text
    WHEN $2 = 'orcamento_enviado' AND pipeline_status IN ('novo','qualificando') THEN 'orcamento_enviado'::text
    WHEN $2 = 'aguardando_artista' AND pipeline_status NOT IN ('fechado','perdido') THEN 'aguardando_artista'::text
    ELSE pipeline_status
  END,
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
                    "queryReplacement": "={{ [$json.lead_id, $json.pipeline_status, $json.placement_val, $json.body_zone_val, $json.style_val, String($json.primeira_tatuagem_val), $json.significado_val, $json.table_price_cents, $json.negotiated_price_cents] }}"
                }
            },
            "type": "n8n-nodes-base.postgres", "typeVersion": 2.6,
            "position": [1300, 0],
            "id": "e3c4d5e6-7f8a-9b0c-1d2e-3f4a5b6c7d8e",
            "name": "Update Lead",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
            "onError": "continueRegularOutput",
        },
        # 9. Log Event
        {
            "parameters": {
                "operation": "executeQuery",
                "query": """INSERT INTO public.events (lead_id, artist_id, event_type, payload)
SELECT $1::uuid, current_setting('app.artist_id')::uuid, $2, $3::jsonb
WHERE $2 IS NOT NULL AND $2 != '';""",
                "options": {
                    "queryReplacement": "={{ [$json.lead_id, $json.event_type, JSON.stringify({ pipeline_status: $json.pipeline_status, price_updated: $json.price_updated, placement: $json.placement_val, body_zone: $json.body_zone_val, style: $json.style_val, handoff_reason: $json.handoff_reason }) ] }}"
                }
            },
            "type": "n8n-nodes-base.postgres", "typeVersion": 2.6,
            "position": [1560, 0],
            "id": "f4d5e6f7-8a9b-0c1d-2e3f-4a5b6c7d8e9f",
            "name": "Log Event",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
            "onError": "continueRegularOutput",
        },
        # 10. Send Telegram Reply
        {
            "parameters": {
                "chatId": "={{ $('Telegram Trigger').item.json.message.chat.id }}",
                "text": "={{ $('AI Agent').first().json.output }}",
                "additionalFields": {},
            },
            "type": "n8n-nodes-base.telegram", "typeVersion": 1.2,
            "position": [1820, 0],
            "id": "980d11b3-4057-45b5-bdd3-1259e43ae685",
            "name": "Send a text message",
            "webhookId": "9520c6f6-ea33-4705-ae68-c269c8582ec8",
            "credentials": {"telegramApi": {"id": "ddaVhX88IF54TVAS", "name": "Telegram account 2"}},
        },
    ],
    "connections": {
        "Telegram Trigger": {
            "main": [[{"node": "Set Artist Context", "type": "main", "index": 0}]]
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
            "main": [[{"node": "Send a text message", "type": "main", "index": 0}]]
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
