#!/usr/bin/env python3
"""Build the Notion Sync Consumer workflow JSON definition.

Drains the `notion_sync_outbox` table (filled by the Beatriz flows via
`enqueue_notion_sync`) and pushes each lead's current state to Notion with
retry/backoff.

This replaces the old fire-and-forget webhook call with a guaranteed
at-least-once delivery pipeline:

  1. Schedule trigger (every minute)
  2. claim_notion_sync_rows() claims a batch of pending/failed rows (SKIP LOCKED)
  3. If none, stop
  4. For each row: join lead + artist Notion config
  5. Build Notion payloads (reuses code/notion-sync.js)
  6. Search / Create-or-Update Clientes page, create Project if agendado
  7. complete_notion_sync_row() on success, fail_notion_sync_row() on error
"""
import json, os

BASE = os.path.dirname(os.path.abspath(__file__))
FLOWS_ROOT = os.path.dirname(BASE)

NOTION_SYNC_CODE = open(os.path.join(BASE, "code/notion-sync.js")).read()

NOTION_API = "https://api.notion.com/v1"
NOTION_VERSION = "2026-03-11"

# ── Code: Merge claimed row + Lead + Artist Notion config ──
MERGE_ROW_CODE = r"""const row = $input.first().json;
// row comes from claim_notion_sync_rows(): { id, lead_id, artist_id, payload, ... }
const lead = row;
const payload = (typeof row.payload === 'object') ? row.payload : {};

return [{
  json: {
    outbox_id: row.id,
    lead_id: row.lead_id,
    artist_id: row.artist_id,
    nome: payload.nome || null,
    telefone: payload.telefone || null,
    email: payload.email || null,
    pipeline_status: payload.pipeline_status || 'novo',
    placement: payload.placement || null,
    body_zone: payload.body_zone || null,
    style: payload.style || null,
    table_price: payload.table_price || null,
    negotiated_price: payload.negotiated_price || null,
    deposit_status: payload.deposit_status || null,
    deposit_amount: payload.deposit_amount || null,
    booked_date: payload.booked_date || null,
    session_duration_min: payload.session_duration_min || null,
    handoff_reason: payload.handoff_reason || null,
    tatuador_nome: row.artist_name || null,
    notion_token: row.notion_token,
    notion_clientes_database_id: row.notion_clientes_database_id,
    notion_projects_database_id: row.notion_projects_database_id,
  }
}];""".replace("NOTION_API", repr(NOTION_API))

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
    outbox_id: input.outbox_id,
  }
}];""".replace("NOTION_API", repr(NOTION_API))

# ── Code: Prepare Project (injects Cliente relation page ID) ──
PREPARE_PROJECT_CODE = r"""const input = $input.first().json;
const upsertResp = $('Create or Update Clientes').first().json;
const projectPayload = input.project_payload;

if (!projectPayload) return [{ json: { should_create_project: false, outbox_id: input.outbox_id } }];

const pageId = upsertResp.id || input.page_id;
if (!pageId) return [{ json: { should_create_project: false, outbox_id: input.outbox_id } }];

const body = JSON.parse(JSON.stringify(projectPayload));
body.properties.Cliente.relation = [{ id: pageId }];

return [{
  json: {
    should_create_project: true,
    url: NOTION_API + '/pages',
    body: body,
    notion_token: input.notion_token,
    lead_id: input.lead_id,
    outbox_id: input.outbox_id,
  }
}];""".replace("NOTION_API", repr(NOTION_API))

WF = {
    "name": "Notion Sync Consumer",
    "description": "Drains the notion_sync_outbox table and syncs lead state to Notion (Clientes + Projects) with retry/backoff. Guarantees at-least-once delivery of pipeline changes.",
    "nodes": [
        # 1. Schedule trigger — run every minute
        {
            "parameters": {
                "rule": {
                    "interval": [{"field": "minutes", "minutesInterval": 1}],
                },
            },
            "type": "n8n-nodes-base.scheduleTrigger",
            "typeVersion": 1.2,
            "position": [0, 0],
            "id": "oc-sched-0000-0000-0000-000000000001",
            "name": "Schedule",
        },
        # 2. Claim pending outbox rows
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT * FROM claim_notion_sync_rows(50);",
                "options": {},
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [260, 0],
            "id": "oc-claim-0000-0000-0000-000000000001",
            "name": "Claim Rows",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 3. IF — rows claimed?
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
                            "id": "oc-if-rows-cond-000000000001",
                            "leftValue": "={{ $json.id }}",
                            "rightValue": "",
                            "operator": {
                                "type": "string",
                                "operation": "notEmpty",
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
            "id": "oc-if-rows-0000-0000-0000-000000000001",
            "name": "Rows Claimed?",
        },
        # 4. Load Lead + Artist Notion Config (join)
        {
            "parameters": {
                "operation": "executeQuery",
                "query": """SELECT
  o.id AS outbox_id,
  o.lead_id,
  o.artist_id,
  o.payload,
  a.nome AS artist_name,
  a.notion_token,
  a.notion_clientes_database_id,
  a.notion_projects_database_id
FROM notion_sync_outbox o
JOIN artists a ON a.id = o.artist_id
WHERE o.id = $1::uuid;""",
                "options": {
                    "queryReplacement": "={{ [$json.id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [780, -100],
            "id": "oc-load-0000-0000-0000-000000000001",
            "name": "Load Lead + Config",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 5. Code — Merge row + lead + config
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": MERGE_ROW_CODE,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1040, -100],
            "id": "oc-merge-0000-0000-0000-000000000001",
            "name": "Merge Row + Config",
        },
        # 6. Code — Build Notion payloads (reuse notion-sync.js)
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": NOTION_SYNC_CODE,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1300, -100],
            "id": "oc-build-0000-0000-0000-000000000001",
            "name": "Build Notion Payloads",
        },
        # 7. HTTP — Search Clientes by Telefone
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
            "position": [1560, -100],
            "id": "oc-search-0000-0000-0000-000000000001",
            "name": "Search Clientes",
            "onError": "continueRegularOutput",
        },
        # 8. Code — Finalize create vs update
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": FINALIZE_PAGE_CODE,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1820, -100],
            "id": "oc-final-0000-0000-0000-000000000001",
            "name": "Finalize Page Payload",
        },
        # 9. HTTP — Create or Update Clientes page
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
            "position": [2080, -100],
            "id": "oc-upsert-0000-0000-0000-000000000001",
            "name": "Create or Update Clientes",
            "onError": "continueRegularOutput",
        },
        # 10. Code — Prepare Project payload
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": PREPARE_PROJECT_CODE,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [2340, -100],
            "id": "oc-project-0000-0000-0000-000000000001",
            "name": "Prepare Project",
        },
        # 11. IF — Should create project?
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
                            "id": "oc-if-proj-cond-000000000001",
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
            "typeVersion": 2.2,
            "position": [2600, -200],
            "id": "oc-if-project-0000-0000-0000-000000000001",
            "name": "Create Project?",
        },
        # 12. HTTP — Create Project page
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
            "position": [2860, -300],
            "id": "oc-create-proj-0000-0000-0000-000000000001",
            "name": "Create Project",
            "onError": "continueRegularOutput",
        },
        # 13. Code — Determine success/failure (inspect error markers from HTTP nodes)
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": """const prep = $('Prepare Project').first().json;
const outboxId = prep.outbox_id || '';
// HTTP nodes with onError:continueRegularOutput emit { json: { error: msg } } on failure.
// Detect any error marker carried through the chain.
const clientesErr = $('Create or Update Clientes').first().json?.error;
const projectErr = $('Create Project').first().json?.error;
const failed = Boolean(clientesErr || projectErr);
return [{ json: { outbox_id: outboxId, success: !failed, error: (clientesErr || projectErr || '') } }];""",
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [3120, -200],
            "id": "oc-eval-0000-0000-0000-000000000001",
            "name": "Evaluate Result",
        },
        # 14. IF — success?
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
                            "id": "oc-if-ok-cond-000000000001",
                            "leftValue": "={{ $json.success }}",
                            "rightValue": True,
                            "operator": {
                                "type": "boolean",
                                "operation": "equals",
                            },                        }
                    ],
                    "combinator": "and",
                },
                "options": {},
            },
            "type": "n8n-nodes-base.if",
            "typeVersion": 2.2,
            "position": [3380, -200],
            "id": "oc-if-ok-0000-0000-0000-000000000001",
            "name": "Success?",
        },
        # 15. Postgres — Mark row done (success branch)
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT complete_notion_sync_row($1::uuid);",
                "options": {
                    "queryReplacement": "={{ [$json.outbox_id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [3640, -260],
            "id": "oc-complete-0000-0000-0000-000000000001",
            "name": "Complete Row",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
        # 16. Postgres — Mark row failed (failure branch, retry with backoff)
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT fail_notion_sync_row($1::uuid, $2::text);",
                "options": {
                    "queryReplacement": "={{ [$json.outbox_id, $json.error] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [3640, -140],
            "id": "oc-fail-0000-0000-0000-000000000001",
            "name": "Fail Row",
            "credentials": {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}},
        },
    ],

    "connections": {
        "Schedule": {
            "main": [[{"node": "Claim Rows", "type": "main", "index": 0}]]
        },
        "Claim Rows": {
            "main": [[{"node": "Rows Claimed?", "type": "main", "index": 0}]]
        },
        "Rows Claimed?": {
            "main": [
                [{"node": "Load Lead + Config", "type": "main", "index": 0}],
                [],
            ]
        },
        "Load Lead + Config": {
            "main": [[{"node": "Merge Row + Config", "type": "main", "index": 0}]]
        },
        "Merge Row + Config": {
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
                [{"node": "Evaluate Result", "type": "main", "index": 0}],
            ]
        },
        "Create Project": {
            "main": [[{"node": "Evaluate Result", "type": "main", "index": 0}]]
        },
        "Evaluate Result": {
            "main": [[{"node": "Success?", "type": "main", "index": 0}]]
        },
        "Success?": {
            "main": [
                [{"node": "Complete Row", "type": "main", "index": 0}],
                [{"node": "Fail Row", "type": "main", "index": 0}],
            ]
        },
    },
    "settings": {
        "executionOrder": "v1",
    },
}

out_path = os.path.join(BASE, "notion-sync-consumer.json")
with open(out_path, "w", encoding="utf-8") as f:
    json.dump(WF, f, indent=2, ensure_ascii=False)
print(f"Wrote {out_path}")
