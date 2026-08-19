#!/usr/bin/env python3
"""Build the Notion Sync workflow JSON definition.

Standalone n8n sub-workflow called via webhook from the Beatriz WhatsApp
workflow after every pipeline state change. Fire-and-forget — sync failure
does not block the conversation.

Flow:
  1. Webhook receives lead payload
  2. Postgres loads artist Notion config (token + database IDs)
  3. If configured, search Clientes DB by Telefone
  4. Create or update the Clientes page with current pipeline state
  5. If agendado, create a linked Project page in the Projects DB
"""
import json, os

BASE = os.path.dirname(os.path.abspath(__file__))
FLOWS_ROOT = os.path.dirname(BASE)

NOTION_SYNC_CODE = open(os.path.join(BASE, "code/notion-sync.js")).read()

NOTION_API = "https://api.notion.com/v1"
NOTION_VERSION = "2026-03-11"

# ── Code: Finalize Page Payload (create vs update decision) ──
FINALIZE_PAGE_CODE = r"""const input = $input.first().json;
const searchResp = $('Search Clientes').first().json;
const notionToken = input.notion_token;
const clientesDbId = input.clientes_db_id;

const results = searchResp.results || [];
const found = results.length > 0;
const pageId = found ? results[0].id : null;

let method, url, body;

if (found) {
  method = 'PATCH';
  url = NOTION_API + '/pages/' + pageId;
  body = { properties: input.page_properties };
} else {
  method = 'POST';
  url = NOTION_API + '/pages';
  body = {
    parent: { type: 'data_source_id', data_source_id: clientesDbId },
    properties: input.page_properties,
  };
}

return [{
  json: {
    notion_token: notionToken,
    method: method,
    url: url,
    body: body,
    found: found,
    page_id: pageId,
    project_payload: input.project_payload,
    pipeline_status: input.pipeline_status,
    lead_id: input.lead_id,
    artist_id: input.artist_id,
  }
}];
""".replace("NOTION_API", repr(NOTION_API))

# ── Code: Prepare Project (injects Cliente relation page ID) ──
PREPARE_PROJECT_CODE = r"""const input = $input.first().json;
const upsertResp = $('Create or Update Clientes').first().json;
const projectPayload = input.project_payload;

if (!projectPayload) return [{ json: { should_create_project: false } }];

const pageId = upsertResp.id || input.page_id;
if (!pageId) return [{ json: { should_create_project: false } }];

const body = JSON.parse(JSON.stringify(projectPayload));
body.properties.Cliente.relation = [{ id: pageId }];

return [{
  json: {
    should_create_project: true,
    url: NOTION_API + '/pages',
    body: body,
    notion_token: input.notion_token,
    lead_id: input.lead_id,
  }
}];
""".replace("NOTION_API", repr(NOTION_API))

WF = {
    "name": "Notion Sync",
    "description": "Mirrors Beatriz's pipeline state changes to the artist's Notion workspace — creates/updates Clientes pages and creates linked Projects when a tattoo is scheduled.",
    "nodes": [
        # 1. Webhook — receives lead payload from Beatriz workflow
        {
            "parameters": {
                "httpMethod": "POST",
                "path": "notion-sync",
                "responseMode": "responseNode",
                "options": {},
            },
            "type": "n8n-nodes-base.webhook",
            "typeVersion": 2,
            "position": [0, 0],
            "id": "ns-webhook-0000-0000-0000-000000000001",
            "name": "Notion Sync Webhook",
            "webhookId": "ns-webhook-0000-0000-0000-000000000001",
        },

        # 2. Postgres — Load artist Notion config
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT notion_token, notion_clientes_database_id, notion_projects_database_id, nome AS artist_name FROM artists WHERE id = $1::uuid",
                "options": {
                    "queryReplacement": "={{ [$json.artist_id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [260, 0],
            "id": "ns-pg-config-0000-0000-0000-000000000001",
            "name": "Load Artist Notion Config",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },

        # 3. IF — Has Notion config?
        {
            "parameters": {
                "conditions": {
                    "string": [
                        {
                            "value1": "={{ $json.notion_token }}",
                            "operation": "isNotEmpty",
                        }
                    ]
                }
            },
            "type": "n8n-nodes-base.if",
            "typeVersion": 2.2,
            "position": [520, 0],
            "id": "ns-if-config-0000-0000-0000-000000000001",
            "name": "Has Notion Config?",
        },

        # 4. Merge Webhook + Notion Config (Code)
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": """const webhook = $('Notion Sync Webhook').first().json;
const config = $('Load Artist Notion Config').first().json;

return [{
  json: {
    lead_id: webhook.lead_id,
    artist_id: webhook.artist_id,
    nome: webhook.nome,
    telefone: webhook.telefone,
    email: webhook.email || null,
    pipeline_status: webhook.pipeline_status,
    placement: webhook.placement || null,
    body_zone: webhook.body_zone || null,
    style: webhook.style || null,
    table_price: webhook.table_price || null,
    negotiated_price: webhook.negotiated_price || null,
    deposit_status: webhook.deposit_status || null,
    deposit_amount: webhook.deposit_amount || null,
    booked_date: webhook.booked_date || null,
    session_duration_min: webhook.session_duration_min || null,
    handoff_reason: webhook.handoff_reason || null,
    tatuador_nome: config.artist_name || null,
    notion_token: config.notion_token,
    notion_clientes_database_id: config.notion_clientes_database_id,
    notion_projects_database_id: config.notion_projects_database_id,
  }
}];""",
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [780, -100],
            "id": "ns-code-merge-0000-0000-0000-000000000001",
            "name": "Merge Webhook + Config",
        },

        # 5. Code — Build Notion payloads
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": NOTION_SYNC_CODE,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1040, -100],
            "id": "ns-code-build-0000-0000-0000-000000000001",
            "name": "Build Notion Payloads",
        },

        # 6. HTTP — Search Clientes by Telefone
        {
            "parameters": {
                "method": "POST",
                "url": "={{ 'https://api.notion.com/v1/data_sources/' + $json.clientes_db_id + '/query' }}",
                "authentication": "none",
                "sendHeaders": True,
                "headerParameters": {
                    "parameters": [
                        {"name": "Authorization", "value": "={{ 'Bearer ' + $json.notion_token }}"},
                        {"name": "Notion-Version", "value": NOTION_VERSION},
                        {"name": "Content-Type", "value": "application/json"},
                    ]
                },
                "sendBody": True,
                "specifyBody": "json",
                "jsonBody": "={{ JSON.stringify($json.search_body) }}",
                "options": {"timeout": 15000},
            },
            "type": "n8n-nodes-base.httpRequest",
            "typeVersion": 4.2,
            "position": [1300, -100],
            "id": "ns-http-search-0000-0000-0000-000000000001",
            "name": "Search Clientes",
            "onError": "continueRegularOutput",
        },

        # 7. Code — Finalize create vs update
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": FINALIZE_PAGE_CODE,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1560, -100],
            "id": "ns-code-final-0000-0000-0000-000000000001",
            "name": "Finalize Page Payload",
        },

        # 8. HTTP — Create or Update Clientes page
        {
            "parameters": {
                "url": "={{ $json.url }}",
                "method": "={{ $json.method }}",
                "authentication": "none",
                "sendHeaders": True,
                "headerParameters": {
                    "parameters": [
                        {"name": "Authorization", "value": "={{ 'Bearer ' + $json.notion_token }}"},
                        {"name": "Notion-Version", "value": NOTION_VERSION},
                        {"name": "Content-Type", "value": "application/json"},
                    ]
                },
                "sendBody": True,
                "specifyBody": "json",
                "jsonBody": "={{ JSON.stringify($json.body) }}",
                "options": {"timeout": 15000},
            },
            "type": "n8n-nodes-base.httpRequest",
            "typeVersion": 4.2,
            "position": [1820, -100],
            "id": "ns-http-upsert-0000-0000-0000-000000000001",
            "name": "Create or Update Clientes",
            "onError": "continueRegularOutput",
        },

        # 9. Code — Prepare Project payload
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": PREPARE_PROJECT_CODE,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [2080, -100],
            "id": "ns-code-project-0000-0000-0000-000000000001",
            "name": "Prepare Project",
        },

        # 10. IF — Should create project?
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
                            "id": "ns-if-proj-cond-000000000001",
                            "leftValue": "={{ $json.should_create_project }}",
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
            "position": [2340, -100],
            "id": "ns-if-project-0000-0000-0000-000000000001",
            "name": "Create Project?",
        },

        # 11. HTTP — Create Project page
        {
            "parameters": {
                "method": "POST",
                "url": "={{ $json.url }}",
                "authentication": "none",
                "sendHeaders": True,
                "headerParameters": {
                    "parameters": [
                        {"name": "Authorization", "value": "={{ 'Bearer ' + $json.notion_token }}"},
                        {"name": "Notion-Version", "value": NOTION_VERSION},
                        {"name": "Content-Type", "value": "application/json"},
                    ]
                },
                "sendBody": True,
                "specifyBody": "json",
                "jsonBody": "={{ JSON.stringify($json.body) }}",
                "options": {"timeout": 15000},
            },
            "type": "n8n-nodes-base.httpRequest",
            "typeVersion": 4.2,
            "position": [2600, -300],
            "id": "ns-http-project-0000-0000-0000-000000000001",
            "name": "Create Project",
            "onError": "continueRegularOutput",
        },

        # 12. Webhook Response — 200 OK
        {
            "parameters": {
                "respondWith": "json",
                "responseBody": "={{ JSON.stringify({ status: 'ok' }) }}",
            },
            "type": "n8n-nodes-base.respondToWebhook",
            "typeVersion": 1.2,
            "position": [3120, -100],
            "id": "ns-response-0000-0000-0000-000000000001",
            "name": "Webhook Response",
        },
    ],

    "connections": {
        "Notion Sync Webhook": {
            "main": [[{"node": "Load Artist Notion Config", "type": "main", "index": 0}]]
        },
        "Load Artist Notion Config": {
            "main": [[{"node": "Has Notion Config?", "type": "main", "index": 0}]]
        },
        "Has Notion Config?": {
            "main": [
                [{"node": "Merge Webhook + Config", "type": "main", "index": 0}],
                [{"node": "Webhook Response", "type": "main", "index": 0}],
            ]
        },
        "Merge Webhook + Config": {
            "main": [[{"node": "Build Notion Payloads", "type": "main", "index": 0}]]
        },
        "Build Notion Payloads": {
            "main": [[{"node": "Search Clientes", "type": "main", "index": 0}]]
        },
        "Search Clientes": {
            "main": [[{"node": "Finalize Page Payload", "type": "main", "index": 0}]]
        },
        "Finalize Page Payload": {
            "main": [[{"node": "Create or Update Clientes", "type": "main", "index": 0}]]
        },
        "Create or Update Clientes": {
            "main": [[{"node": "Prepare Project", "type": "main", "index": 0}]]
        },
        "Prepare Project": {
            "main": [[{"node": "Create Project?", "type": "main", "index": 0}]]
        },
        "Create Project?": {
            "main": [
                [{"node": "Create Project", "type": "main", "index": 0}],
                [{"node": "Webhook Response", "type": "main", "index": 0}],
            ]
        },
        "Create Project": {
            "main": [[{"node": "Webhook Response", "type": "main", "index": 0}]]
        },
    },
    "settings": {
        "executionOrder": "v1",
    },
}

out_path = os.path.join(BASE, "notion-sync.json")
with open(out_path, "w", encoding="utf-8") as f:
    json.dump(WF, f, indent=2, ensure_ascii=False)
print(f"Wrote {out_path}")
