#!/usr/bin/env python3
"""Build the Artist Onboard Webhook workflow JSON definition.

Self-serve WhatsApp connect for the beta SDR agent (#8): a single n8n webhook
the standalone sdr-admin bundle calls to (a) validate an artist's token and
fetch the WAHA QR, (b) poll whether the artist has scanned it, and (c) mark the
token consumed once connected.

WAHA (v2026.x, NOWEB) API used:
  GET /api/sessions/{name}            -> { status, me } (me set once authenticated)
  GET /api/{name}/auth/qr             -> QR PNG (binary)

Contract (POST JSON body):
  { token, action: 'validate'|'status'|'consume', refreshQr?: bool }

  - validate -> { valid, artist_name, qr_image(base64 png) }  (200)
  - status   -> { connected: true }                            (200)
                { connected: false, qr_image }                 (200, not scanned yet)
  - consume  -> { success: true }                              (200)
  Invalid/missing token -> 401 { success:false, error:'invalid_token' }.
  Unknown action        -> 400 { success:false, error:'invalid_action' }.
"""
import json, os

BASE = os.path.dirname(os.path.abspath(__file__))
WEBHOOK_PATH = "onboard-api"
MAIN_DB = {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}}
WAHA = {"wahaApi": {"id": "uQsr20PaYqbQUAGD", "name": "WAHA account"}}

RESOLVE_QUERY = """\
SELECT
  a.id, a.nome, a.wa_session_slug, a.status,
  $1::text   AS token,
  $2::text   AS action,
  $3::boolean AS refresh_qr,
  (a.id IS NOT NULL) AS found
FROM artists a
WHERE a.onboarding_token = $1
UNION ALL
SELECT
  NULL::uuid, NULL::text, NULL::text, NULL::text,
  $1::text, $2::text, $3::boolean, false AS found
WHERE NOT EXISTS (SELECT 1 FROM artists a WHERE a.onboarding_token = $1)
LIMIT 1;"""

# A QR is only meaningful while the session is unauthenticated. The page
# refreshes it on a timer; WAHA returns base64 when asked for JSON
# (Accept: application/json), so no n8n binary handling is needed.
BUILD_QR_RESPONSE_JS = """const artist = $('Resolve Artist by Token').first().json;
const resp = $input.first().json;
const qr = (resp && resp.data) || '';

return [{
  json: {
    valid: true,
    action: artist.action,
    artist_name: artist.nome,
    qr_image: qr,
  }
}];"""

# Connected when WAHA reports an authenticated user for the session.
PARSE_SESSION_STATE_JS = """const artist = $('Resolve Artist by Token').first().json;
const sess = $input.first().json;
const connected = !!(sess && sess.me);
const refreshQr = artist.refresh_qr === true;

return [{
  json: {
    connected: connected,
    refresh_qr: refreshQr,
  }
}];"""

# Status response when not connected (optionally with a refreshed QR).
BUILD_STATUS_RESPONSE_JS = """const resp = $input.first().json;
const qr = (resp && resp.data) || '';

return [{ json: { connected: false, qr_image: qr } }];"""

CONSUME_RESPONSE_JS = """const row = $input.first().json;
return [{
  json: {
    success: !!row.id,
    error: row.id ? null : 'token_not_consumed',
  }
}];"""

SWITCH_EXPR = (
    "={{ $json.action === 'validate' ? 0 : $json.action === 'status' ? 1 : "
    "$json.action === 'consume' ? 2 : 3 }}"
)

WF = {
    "name": "Artist Onboard Webhook",
    "description": "Self-serve WhatsApp connect (#8) — validate artist token, serve WAHA QR, poll scan state, consume token. Proxies WAHA (NOWEB) auth/qr + sessions.",
    "nodes": [
        {
            "parameters": {"httpMethod": "POST", "path": WEBHOOK_PATH, "responseMode": "responseNode", "options": {}},
            "type": "n8n-nodes-base.webhook", "typeVersion": 2,
            "position": [0, 0],
            "id": "ob-webhook-0000-0000-0000-000000000001",
            "name": "Onboard Webhook",
            "webhookId": "ob-webhook-0000-0000-0000-000000000001",
        },
        {
            "parameters": {
                "operation": "executeQuery",
                "query": RESOLVE_QUERY,
                "options": {"queryReplacement": "={{ [$json.body.token, $json.body.action, $json.body.refreshQr === true] }}"},
            },
            "type": "n8n-nodes-base.postgres", "typeVersion": 2.6,
            "position": [260, 0],
            "id": "ob-resolve-0000-0000-0000-000000000001",
            "name": "Resolve Artist by Token",
            "credentials": MAIN_DB,
        },
        {
            "parameters": {
                "conditions": {
                    "options": {"caseSensitive": True, "leftValue": "", "typeValidation": "strict", "version": 3},
                    "conditions": [
                        {
                            "id": "ob-if-token-cond-0000",
                            "leftValue": "={{ $json.found }}",
                            "rightValue": True,
                            "operator": {"type": "boolean", "operation": "equals"},
                        }
                    ],
                    "combinator": "and",
                },
                "options": {},
            },
            "type": "n8n-nodes-base.if", "typeVersion": 2.2,
            "position": [520, 0],
            "id": "ob-if-token-0000-0000-0000-000000000001",
            "name": "Token Valid?",
        },
        {
            "parameters": {
                "respondWith": "json",
                "responseBody": "={{ JSON.stringify({ success: false, error: 'invalid_token', message: 'Token de artista inválido ou desconhecido.' }) }}",
                "options": {"responseCode": 401},
            },
            "type": "n8n-nodes-base.respondToWebhook", "typeVersion": 1.2,
            "position": [780, 240],
            "id": "ob-resp-bad-token-0000-0000-0000-000000000001",
            "name": "Invalid Token Response",
        },
        {
            "parameters": {
                "mode": "expression",
                "numberOutputs": 4,
                "output": SWITCH_EXPR,
                "options": {},
            },
            "type": "n8n-nodes-base.switch", "typeVersion": 3.2,
            "position": [780, -200],
            "id": "ob-switch-0000-0000-0000-000000000001",
            "name": "Route by Action",
        },
        # ── validate: serve the QR ──
        {
            "parameters": {
                "method": "GET",
                "url": "={{ 'https://waha.vaif.com.br/api/' + $('Resolve Artist by Token').first().json.wa_session_slug + '/auth/qr' }}",
                "authentication": "predefinedCredentialType",
                "nodeCredentialType": "wahaApi",
                "sendHeaders": True,
                "headerParameters": {
                    "parameters": [
                        {"name": "Accept", "value": "application/json"}
                    ]
                },
                "options": {"timeout": 20000},
            },
            "type": "n8n-nodes-base.httpRequest", "typeVersion": 4.2,
            "position": [1040, -400],
            "id": "ob-fetch-qr-0000-0000-0000-000000000001",
            "name": "Fetch QR",
            "credentials": WAHA,
        },
        {
            "parameters": {"mode": "runOnceForAllItems", "jsCode": BUILD_QR_RESPONSE_JS},
            "type": "n8n-nodes-base.code", "typeVersion": 2,
            "position": [1300, -400],
            "id": "ob-validate-resp-0000-0000-0000-000000000001",
            "name": "Build Validate Response",
        },
        # ── status: poll scan state ──
        {
            "parameters": {
                "method": "GET",
                "url": "={{ 'https://waha.vaif.com.br/api/sessions/' + $('Resolve Artist by Token').first().json.wa_session_slug }}",
                "authentication": "predefinedCredentialType",
                "nodeCredentialType": "wahaApi",
                "options": {"timeout": 15000},
            },
            "type": "n8n-nodes-base.httpRequest", "typeVersion": 4.2,
            "position": [1040, -200],
            "id": "ob-session-0000-0000-0000-000000000001",
            "name": "Check Session",
            "credentials": WAHA,
        },
        {
            "parameters": {"mode": "runOnceForAllItems", "jsCode": PARSE_SESSION_STATE_JS},
            "type": "n8n-nodes-base.code", "typeVersion": 2,
            "position": [1300, -200],
            "id": "ob-parse-0000-0000-0000-000000000001",
            "name": "Parse Session State",
        },
        {
            "parameters": {
                "conditions": {
                    "options": {"caseSensitive": True, "leftValue": "", "typeValidation": "strict", "version": 3},
                    "conditions": [
                        {
                            "id": "ob-if-connected-cond-0000",
                            "leftValue": "={{ $json.connected }}",
                            "rightValue": True,
                            "operator": {"type": "boolean", "operation": "equals"},
                        }
                    ],
                    "combinator": "and",
                },
                "options": {},
            },
            "type": "n8n-nodes-base.if", "typeVersion": 2,
            "position": [1560, -200],
            "id": "ob-if-connected-0000-0000-0000-000000000001",
            "name": "Connected?",
        },
        {
            "parameters": {
                "method": "GET",
                "url": "={{ 'https://waha.vaif.com.br/api/' + $('Resolve Artist by Token').first().json.wa_session_slug + '/auth/qr' }}",
                "authentication": "predefinedCredentialType",
                "nodeCredentialType": "wahaApi",
                "sendHeaders": True,
                "headerParameters": {
                    "parameters": [
                        {"name": "Accept", "value": "application/json"}
                    ]
                },
                "options": {"timeout": 20000},
            },
            "type": "n8n-nodes-base.httpRequest", "typeVersion": 4.2,
            "position": [1820, -200],
            "id": "ob-refresh-qr-0000-0000-0000-000000000001",
            "name": "Refresh QR",
            "credentials": WAHA,
        },
        {
            "parameters": {"mode": "runOnceForAllItems", "jsCode": BUILD_STATUS_RESPONSE_JS},
            "type": "n8n-nodes-base.code", "typeVersion": 2,
            "position": [2080, -200],
            "id": "ob-status-resp-0000-0000-0000-000000000001",
            "name": "Build Status Response",
        },
        # ── consume: mark token used ──
        {
            "parameters": {
                "operation": "executeQuery",
                "query": "SELECT id, status FROM consume_onboarding_token($1);",
                "options": {"queryReplacement": "={{ [$json.token] }}"},
            },
            "type": "n8n-nodes-base.postgres", "typeVersion": 2.6,
            "position": [1040, 0],
            "id": "ob-consume-0000-0000-0000-000000000001",
            "name": "Consume Token",
            "credentials": MAIN_DB,
        },
        {
            "parameters": {"mode": "runOnceForAllItems", "jsCode": CONSUME_RESPONSE_JS},
            "type": "n8n-nodes-base.code", "typeVersion": 2,
            "position": [1300, 0],
            "id": "ob-consume-resp-0000-0000-0000-000000000001",
            "name": "Build Consume Response",
        },
        {
            "parameters": {
                "respondWith": "json",
                "responseBody": "={{ JSON.stringify($json) }}",
                "options": {"responseCode": 200},
            },
            "type": "n8n-nodes-base.respondToWebhook", "typeVersion": 1.2,
            "position": [2340, 0],
            "id": "ob-resp-ok-0000-0000-0000-000000000001",
            "name": "Onboard Response",
        },
        {
            "parameters": {
                "respondWith": "json",
                "responseBody": "={{ JSON.stringify({ success: false, error: 'invalid_action', message: 'Ação desconhecida. Use validate, status ou consume.' }) }}",
                "options": {"responseCode": 400},
            },
            "type": "n8n-nodes-base.respondToWebhook", "typeVersion": 1.2,
            "position": [1040, -500],
            "id": "ob-resp-bad-action-0000-0000-0000-000000000001",
            "name": "Invalid Action Response",
        },
    ],
    "connections": {
        "Onboard Webhook": {"main": [[{"node": "Resolve Artist by Token", "type": "main", "index": 0}]]},
        "Resolve Artist by Token": {"main": [[{"node": "Token Valid?", "type": "main", "index": 0}]]},
        "Token Valid?": {
            "main": [
                [{"node": "Route by Action", "type": "main", "index": 0}],
                [{"node": "Invalid Token Response", "type": "main", "index": 0}],
            ]
        },
        "Route by Action": {
            "main": [
                [{"node": "Fetch QR", "type": "main", "index": 0}],
                [{"node": "Check Session", "type": "main", "index": 0}],
                [{"node": "Consume Token", "type": "main", "index": 0}],
                [{"node": "Invalid Action Response", "type": "main", "index": 0}],
            ]
        },
        "Fetch QR": {"main": [[{"node": "Build Validate Response", "type": "main", "index": 0}]]},
        "Build Validate Response": {"main": [[{"node": "Onboard Response", "type": "main", "index": 0}]]},
        "Check Session": {"main": [[{"node": "Parse Session State", "type": "main", "index": 0}]]},
        "Parse Session State": {"main": [[{"node": "Connected?", "type": "main", "index": 0}]]},
        "Connected?": {
            "main": [
                [{"node": "Onboard Response", "type": "main", "index": 0}],
                [{"node": "Refresh QR", "type": "main", "index": 0}],
            ]
        },
        "Refresh QR": {"main": [[{"node": "Build Status Response", "type": "main", "index": 0}]]},
        "Build Status Response": {"main": [[{"node": "Onboard Response", "type": "main", "index": 0}]]},
        "Consume Token": {"main": [[{"node": "Build Consume Response", "type": "main", "index": 0}]]},
        "Build Consume Response": {"main": [[{"node": "Onboard Response", "type": "main", "index": 0}]]},
    },
    "settings": {"executionOrder": "v1"},
}

out_path = os.path.join(BASE, "artist-onboard-webhook.json")
with open(out_path, "w", encoding="utf-8") as f:
    json.dump(WF, f, indent=2, ensure_ascii=False)
print(f"Wrote {out_path}")