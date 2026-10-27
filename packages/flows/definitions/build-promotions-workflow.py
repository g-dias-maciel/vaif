#!/usr/bin/env python3
"""Build the Artist Promotions admin webhook workflow JSON definition.

VAIF-admin seam for the Offers module: create/activate/pause/end an artist's
promotion. Auth: a shared VAIF admin token (n8n env var VAIF_ADMIN_TOKEN)
sent in the POST body; mismatch -> HTTP 401. The artist-facing dashboard never
calls this — only the VAIF partner page.

Contract (POST JSON body):
  { admin_token, action, artist_id?, promotion_id?, type_key?, name?, multiplier?, audience? }

  - list_artists    -> { success, artists: [{id, nome, status, active_promotion}] }
  - list_promotions -> { success, promotions: [{id, artist_id, type_key, name, multiplier, audience, status}] }
  - create          -> { success, promotion: {id, ...} }   (status = 'draft')
  - activate        -> { success, promotion: {id, status} } (snapshots prices at multiplier)
  - pause           -> { success, promotion: {id, status} }
  - end             -> { success, promotion: {id, status} } (voids open quotes)

Deploy: python3 build-promotions-workflow.py && python3 scripts/deploy.py ...
Env (n8n): VAIF_ADMIN_TOKEN, and on the admin page VAIF_ADMIN_TOKEN +
N8N_PROMOTIONS_WEBHOOK_URL = https://n8n.vaif.com.br/webhook/promotions
"""
import json, os

BASE = os.path.dirname(os.path.abspath(__file__))
MAIN_DB = {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}}
WEBHOOK_PATH = "promotions"

AUTH_JS = r"""const body = $json.body || {};
const token = body.admin_token || '';
const expected = $env.VAIF_ADMIN_TOKEN || '';
return [{ json: { authorized: token !== '' && token === expected, body: body } }];"""

LIST_ARTISTS_QUERY = """\
SELECT a.id, a.nome, a.status,
       (SELECT p.name FROM promotions p WHERE p.artist_id = a.id AND p.status = 'active' LIMIT 1) AS active_promotion
FROM artists a
ORDER BY a.nome;"""

LIST_PROMOTIONS_QUERY = """\
SELECT p.id, p.artist_id, a.nome AS artist_name, p.type_key, p.name, p.multiplier, p.audience, p.status,
       p.activated_at, p.paused_at, p.ended_at, p.quote_valid_days
FROM promotions p
JOIN artists a ON a.id = p.artist_id
WHERE ($1::uuid IS NULL OR p.artist_id = $1::uuid)
ORDER BY p.created_at DESC
LIMIT 100;"""

CREATE_PROMOTION_QUERY = """\
INSERT INTO promotions (artist_id, type_key, name, multiplier, audience)
VALUES ($1::uuid, COALESCE(NULLIF($2::text, ''), 'bogo_same_value'),
        COALESCE(NULLIF($3::text, ''), 'Promoção'),
        COALESCE(NULLIF($4::numeric, NULL), 2.0),
        COALESCE(NULLIF($5::text, ''), 'all'))
RETURNING id, artist_id, type_key, name, multiplier, audience, status;"""

ACTIVATE_PROMOTION_QUERY = """\
SELECT id, artist_id, name, multiplier, status FROM activate_promotion($1::uuid)
UNION ALL
SELECT NULL::uuid, NULL::uuid, NULL::text, NULL::numeric, NULL::text
WHERE NOT EXISTS (SELECT 1 FROM activate_promotion($1::uuid));"""

PAUSE_PROMOTION_QUERY = """\
SELECT id, artist_id, name, multiplier, status FROM pause_promotion($1::uuid)
UNION ALL
SELECT NULL::uuid, NULL::uuid, NULL::text, NULL::numeric, NULL::text
WHERE NOT EXISTS (SELECT 1 FROM pause_promotion($1::uuid));"""

END_PROMOTION_QUERY = """\
SELECT id, artist_id, name, multiplier, status FROM end_promotion($1::uuid)
UNION ALL
SELECT NULL::uuid, NULL::uuid, NULL::text, NULL::numeric, NULL::text
WHERE NOT EXISTS (SELECT 1 FROM end_promotion($1::uuid));"""

LIST_ARTISTS_RESPONSE_JS = r"""const rows = $input.all().map(i => i.json);
return [{ json: { success: true, action: 'list_artists', artists: rows } }];"""

LIST_PROMOTIONS_RESPONSE_JS = r"""const rows = $input.all().map(i => i.json);
return [{ json: { success: true, action: 'list_promotions', promotions: rows } }];"""

CREATE_RESPONSE_JS = r"""const r = $input.first().json;
return [{ json: { success: true, action: 'create', promotion: r } }];"""

TRANSITION_RESPONSE_JS = r"""const r = $input.first().json;
if (!r.id) {
  return [{ json: { success: false, action: $json.body.action, error: 'transition_not_allowed', message: 'Transição não permitida.' } }];
}
return [{ json: { success: true, action: $json.body.action, promotion: r } }];"""

SWITCH_OUTPUT_EXPR = (
    "={{ $json.body.action === 'list_artists' ? 0 : $json.body.action === 'list_promotions' ? 1 : "
    "$json.body.action === 'create' ? 2 : $json.body.action === 'activate' ? 3 : "
    "$json.body.action === 'pause' ? 4 : $json.body.action === 'end' ? 5 : 6 }}"
)

WF = {
    "name": "Artist Promotions Webhook",
    "description": "VAIF-admin seam for the Offers module — create/activate/pause/end an artist's promotion. Auth: shared admin token (VAIF_ADMIN_TOKEN).",
    "nodes": [
        {
            "parameters": {
                "httpMethod": "POST",
                "path": WEBHOOK_PATH,
                "responseMode": "responseNode",
                "options": {},
            },
            "type": "n8n-nodes-base.webhook",
            "typeVersion": 2,
            "position": [0, 0],
            "id": "promo-webhook-0000-0000-0000-000000000001",
            "name": "Promotions Webhook",
            "webhookId": "promo-webhook-0000-0000-0000-000000000001",
        },
        {
            "parameters": {"mode": "runOnceForAllItems", "jsCode": AUTH_JS},
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [260, 0],
            "id": "promo-auth-0000-0000-0000-000000000001",
            "name": "Authorize",
        },
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
                            "id": "promo-auth-cond-0000",
                            "leftValue": "={{ $json.authorized }}",
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
            "position": [520, 0],
            "id": "promo-auth-if-0000-0000-0000-000000000001",
            "name": "Authorized?",
        },
        {
            "parameters": {
                "respondWith": "json",
                "responseBody": "={{ JSON.stringify({ success: false, error: 'unauthorized', message: 'Token de admin inválido.' }) }}",
                "options": {"responseCode": 401},
            },
            "type": "n8n-nodes-base.respondToWebhook",
            "typeVersion": 1.2,
            "position": [780, 200],
            "id": "promo-unauth-0000-0000-0000-000000000001",
            "name": "Unauthorized Response",
        },
        {
            "parameters": {
                "mode": "expression",
                "numberOutputs": 7,
                "output": SWITCH_OUTPUT_EXPR,
                "options": {},
            },
            "type": "n8n-nodes-base.switch",
            "typeVersion": 3.2,
            "position": [780, -200],
            "id": "promo-switch-0000-0000-0000-000000000001",
            "name": "Route by Action",
        },
        {
            "parameters": {
                "operation": "executeQuery",
                "query": LIST_ARTISTS_QUERY,
                "options": {},
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1040, -400],
            "id": "promo-list-artists-0000-0000-0000-000000000001",
            "name": "List Artists",
            "credentials": MAIN_DB,
        },
        {
            "parameters": {"mode": "runOnceForAllItems", "jsCode": LIST_ARTISTS_RESPONSE_JS},
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1300, -400],
            "id": "promo-list-artists-resp-0000-0000-0000-000000000001",
            "name": "Build Artists Response",
        },
        {
            "parameters": {
                "operation": "executeQuery",
                "query": LIST_PROMOTIONS_QUERY,
                "options": {
                    "queryReplacement": "={{ [$json.body.artist_id ? $json.body.artist_id : null] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1040, -200],
            "id": "promo-list-promos-0000-0000-0000-000000000001",
            "name": "List Promotions",
            "credentials": MAIN_DB,
        },
        {
            "parameters": {"mode": "runOnceForAllItems", "jsCode": LIST_PROMOTIONS_RESPONSE_JS},
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1300, -200],
            "id": "promo-list-promos-resp-0000-0000-0000-000000000001",
            "name": "Build Promotions Response",
        },
        {
            "parameters": {
                "operation": "executeQuery",
                "query": CREATE_PROMOTION_QUERY,
                "options": {
                    "queryReplacement": "={{ [$json.body.artist_id, $json.body.type_key, $json.body.name, $json.body.multiplier, $json.body.audience] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1040, 0],
            "id": "promo-create-0000-0000-0000-000000000001",
            "name": "Create Promotion",
            "credentials": MAIN_DB,
        },
        {
            "parameters": {"mode": "runOnceForAllItems", "jsCode": CREATE_RESPONSE_JS},
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1300, 0],
            "id": "promo-create-resp-0000-0000-0000-000000000001",
            "name": "Build Create Response",
        },
        {
            "parameters": {
                "operation": "executeQuery",
                "query": ACTIVATE_PROMOTION_QUERY,
                "options": {
                    "queryReplacement": "={{ [$json.body.promotion_id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1040, 200],
            "id": "promo-activate-0000-0000-0000-000000000001",
            "name": "Activate Promotion",
            "credentials": MAIN_DB,
        },
        {
            "parameters": {"mode": "runOnceForAllItems", "jsCode": TRANSITION_RESPONSE_JS},
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1300, 200],
            "id": "promo-activate-resp-0000-0000-0000-000000000001",
            "name": "Build Activate Response",
        },
        {
            "parameters": {
                "operation": "executeQuery",
                "query": PAUSE_PROMOTION_QUERY,
                "options": {
                    "queryReplacement": "={{ [$json.body.promotion_id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1040, 400],
            "id": "promo-pause-0000-0000-0000-000000000001",
            "name": "Pause Promotion",
            "credentials": MAIN_DB,
        },
        {
            "parameters": {"mode": "runOnceForAllItems", "jsCode": TRANSITION_RESPONSE_JS},
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1300, 400],
            "id": "promo-pause-resp-0000-0000-0000-000000000001",
            "name": "Build Pause Response",
        },
        {
            "parameters": {
                "operation": "executeQuery",
                "query": END_PROMOTION_QUERY,
                "options": {
                    "queryReplacement": "={{ [$json.body.promotion_id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1040, 600],
            "id": "promo-end-0000-0000-0000-000000000001",
            "name": "End Promotion",
            "credentials": MAIN_DB,
        },
        {
            "parameters": {"mode": "runOnceForAllItems", "jsCode": TRANSITION_RESPONSE_JS},
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1300, 600],
            "id": "promo-end-resp-0000-0000-0000-000000000001",
            "name": "Build End Response",
        },
        {
            "parameters": {
                "respondWith": "json",
                "responseBody": "={{ JSON.stringify({ success: false, error: 'invalid_action', message: 'Ação desconhecida.' }) }}",
                "options": {"responseCode": 400},
            },
            "type": "n8n-nodes-base.respondToWebhook",
            "typeVersion": 1.2,
            "position": [1040, 800],
            "id": "promo-bad-action-0000-0000-0000-000000000001",
            "name": "Invalid Action Response",
        },
        {
            "parameters": {
                "respondWith": "json",
                "responseBody": "={{ JSON.stringify($json) }}",
                "options": {"responseCode": 200},
            },
            "type": "n8n-nodes-base.respondToWebhook",
            "typeVersion": 1.2,
            "position": [1560, -200],
            "id": "promo-resp-ok-0000-0000-0000-000000000001",
            "name": "Promotions Response",
        },
    ],
    "connections": {
        "Promotions Webhook": {
            "main": [[{"node": "Authorize", "type": "main", "index": 0}]]
        },
        "Authorize": {
            "main": [[{"node": "Authorized?", "type": "main", "index": 0}]]
        },
        "Authorized?": {
            "main": [
                [{"node": "Route by Action", "type": "main", "index": 0}],
                [{"node": "Unauthorized Response", "type": "main", "index": 0}],
            ]
        },
        "Route by Action": {
            "main": [
                [{"node": "List Artists", "type": "main", "index": 0}],
                [{"node": "List Promotions", "type": "main", "index": 0}],
                [{"node": "Create Promotion", "type": "main", "index": 0}],
                [{"node": "Activate Promotion", "type": "main", "index": 0}],
                [{"node": "Pause Promotion", "type": "main", "index": 0}],
                [{"node": "End Promotion", "type": "main", "index": 0}],
                [{"node": "Invalid Action Response", "type": "main", "index": 0}],
            ]
        },
        "List Artists": {
            "main": [[{"node": "Build Artists Response", "type": "main", "index": 0}]]
        },
        "Build Artists Response": {
            "main": [[{"node": "Promotions Response", "type": "main", "index": 0}]]
        },
        "List Promotions": {
            "main": [[{"node": "Build Promotions Response", "type": "main", "index": 0}]]
        },
        "Build Promotions Response": {
            "main": [[{"node": "Promotions Response", "type": "main", "index": 0}]]
        },
        "Create Promotion": {
            "main": [[{"node": "Build Create Response", "type": "main", "index": 0}]]
        },
        "Build Create Response": {
            "main": [[{"node": "Promotions Response", "type": "main", "index": 0}]]
        },
        "Activate Promotion": {
            "main": [[{"node": "Build Activate Response", "type": "main", "index": 0}]]
        },
        "Build Activate Response": {
            "main": [[{"node": "Promotions Response", "type": "main", "index": 0}]]
        },
        "Pause Promotion": {
            "main": [[{"node": "Build Pause Response", "type": "main", "index": 0}]]
        },
        "Build Pause Response": {
            "main": [[{"node": "Promotions Response", "type": "main", "index": 0}]]
        },
        "End Promotion": {
            "main": [[{"node": "Build End Response", "type": "main", "index": 0}]]
        },
        "Build End Response": {
            "main": [[{"node": "Promotions Response", "type": "main", "index": 0}]]
        },
    },
    "settings": {"executionOrder": "v1"},
}

out_path = os.path.join(BASE, "artist-promotions-webhook.json")
with open(out_path, "w", encoding="utf-8") as f:
    json.dump(WF, f, indent=2, ensure_ascii=False)
print(f"Wrote {out_path}")
