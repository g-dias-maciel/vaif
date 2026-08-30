#!/usr/bin/env python3
"""Build the Artist Calendar Webhook workflow JSON definition.

Integration seam for the agenda admin page (#29, #30): a single n8n webhook
the PHP page calls to list an artist's upcoming availability (derived 60-min
slots plus existing blocks) and to block/unblock time ranges, backed by the
Postgres CRM.

Auth: per-artist. The webhook resolves the artist from the token the page
sends (artists.onboarding_token, the same token the onboarding portal uses),
rejecting unknown/invalid tokens with HTTP 401. No shared secret is needed —
the Postgres credential already held by n8n (main-db) is reused, same as the
onboarding webhooks.

NOTE on token lifecycle: consume_onboarding_token() nulls onboarding_token
once the artist connects, so LIVE artists have no token today. For /agenda
(#30) to reach live artists, onboarding_token must be kept (or a dedicated
per-artist token minted) — the only change needed is upstream of this
workflow; the resolve query below is the single seam to adapt.

Contract (POST JSON body, top-level fields — same shape the existing webhook
flows read, e.g. WAHA `$json.session`):
  { token, action: 'list'|'block'|'unblock', start_at?, end_at?, block_id?, duration_min? }

  - list     -> { success, action, artist_id, artist_name, duration_min,
                  available: [{id,start_at,end_at}], blocks: [{id,start_at,end_at}] }
                 available comes from check_availability(...) (derived 60-min
                 slots, artist timezone); blocks are calendar type='blocked'
                 rows that have not ended yet. Defaults: from = today,
                 to = +60 days, duration_min = 60.
  - block    -> { success, action, block: {id,start_at,end_at} }  (block_slot)
  - unblock  -> { success, action, block: {...} }                 (unblock_slot)
                 or { success: false, error: 'block_not_found' } when the id
                 does not reference an existing 'blocked' row.
  Invalid/missing token -> HTTP 401 { success: false, error: 'invalid_token' }.
  Unknown action        -> HTTP 400 { success: false, error: 'invalid_action' }.

Routing uses a Switch node in expression mode (3.2) over the resolved action.

Deploy step (do not run here — see also scripts/deploy.py):
  1. python3 build-calendar-workflow.py            # writes artist-calendar-webhook.json
  2. python3 scripts/deploy.py create definitions/artist-calendar-webhook.json
  3. python3 scripts/deploy.py activate <workflow_id>
Env var for the admin page (Coolify): N8N_AGENDA_WEBHOOK_URL
  = https://n8n.vaif.com.br/webhook/calendar
  (distinct from N8N_CALENDAR_WEBHOOK_URL, which is the LP funnel booking hook)
"""
import json, os

BASE = os.path.dirname(os.path.abspath(__file__))

WEBHOOK_PATH = "calendar"
MAIN_DB = {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}}

# Resolve the artist from the incoming token and carry the request params so
# the Switch and downstream Postgres nodes can read them from $json.
# UNION ALL guarantees a row even for unknown tokens (n8n Postgres nodes skip
# downstream on empty results), so the invalid-token branch can respond.
# `found` is explicit: n8n's isNotEmpty returns TRUE for null, so a null-uuid
# row must be gated on the boolean, not on id presence.
RESOLVE_ARTIST_QUERY = """\
SELECT
  a.id, a.nome, a.timezone, a.status,
  $1::text     AS token,
  $2::text     AS action,
  $3::text     AS start_at,
  $4::text     AS end_at,
  $5::text     AS block_id,
  $6::integer  AS duration_min,
  (a.id IS NOT NULL) AS found
FROM artists a
WHERE a.onboarding_token = $1
UNION ALL
SELECT
  NULL::uuid, NULL::text, NULL::text, NULL::text,
  $1::text, $2::text, $3::text, $4::text, $5::text, $6::integer,
  false AS found
WHERE NOT EXISTS (SELECT 1 FROM artists a WHERE a.onboarding_token = $1)
LIMIT 1;"""

# Derived 60-min slots (check_availability, artist timezone) + existing blocks.
# The trailing sentinel row guarantees a response even when nothing is free.
LIST_AVAILABILITY_QUERY = """\
(
  SELECT id, start_at, end_at, type
  FROM (
    SELECT id, start_at, end_at, 'available'::text AS type
    FROM check_availability(
      $1::uuid,
      COALESCE($2::timestamptz, date_trunc('day', now())::timestamptz),
      COALESCE($3::timestamptz, now() + interval '60 days'),
      COALESCE($4::integer, 60)
    )
    UNION ALL
    SELECT id, start_at, end_at, type
    FROM calendar
    WHERE artist_id = $1::uuid
      AND type = 'blocked'
      AND end_at > now()
  ) s
  ORDER BY start_at
)
UNION ALL
SELECT NULL::uuid, NULL::timestamptz, NULL::timestamptz, NULL::text;"""

BLOCK_SLOT_QUERY = """\
SELECT id, artist_id, start_at, end_at, type
FROM block_slot($1::uuid, $2::timestamptz, $3::timestamptz);"""

UNBLOCK_SLOT_QUERY = """\
WITH removed AS (
  SELECT * FROM unblock_slot($1::uuid)
)
SELECT id, artist_id, start_at, end_at, type
FROM removed
UNION ALL
SELECT NULL::uuid, NULL::uuid, NULL::timestamptz, NULL::timestamptz, NULL::text
WHERE NOT EXISTS (SELECT 1 FROM removed);"""

BUILD_LIST_RESPONSE_JS = r"""const items = $input.all();
const artist = $('Resolve Artist by Token').first().json;

const available = [];
const blocks = [];

for (const item of items) {
  const row = item.json;
  if (!row.id) continue; // sentinel row
  const entry = { id: row.id, start_at: row.start_at, end_at: row.end_at };
  if (row.type === 'blocked') blocks.push(entry);
  else available.push(entry);
}

return [{
  json: {
    success: true,
    action: 'list',
    artist_id: artist.id,
    artist_name: artist.nome,
    duration_min: artist.duration_min || 60,
    timezone: artist.timezone,
    available,
    blocks,
  }
}];"""

BLOCK_RESPONSE_JS = r"""const row = $input.first().json;
return [{
  json: {
    success: true,
    action: 'block',
    block: { id: row.id, start_at: row.start_at, end_at: row.end_at },
  }
}];"""

UNBLOCK_RESPONSE_JS = r"""const row = $input.first().json;
if (!row.id) {
  return [{
    json: {
      success: false,
      action: 'unblock',
      error: 'block_not_found',
      message: 'Bloqueio não encontrado.',
    }
  }];
}
return [{
  json: {
    success: true,
    action: 'unblock',
    block: { id: row.id, start_at: row.start_at, end_at: row.end_at },
  }
}];"""

SWITCH_OUTPUT_EXPR = (
    "={{ $json.action === 'list' ? 0 : $json.action === 'block' ? 1 : "
    "$json.action === 'unblock' ? 2 : 3 }}"
)

WF = {
    "name": "Artist Calendar Webhook",
    "description": "Agenda admin webhook (#29) — list availability (derived 60-min slots + blocks), block and unblock time ranges for an artist, backed by Postgres. Auth: per-artist onboarding token; unknown/invalid tokens are rejected.",
    "nodes": [
        # 1. Webhook Trigger
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
            "id": "cal-webhook-0000-0000-0000-000000000001",
            "name": "Calendar Webhook",
            "webhookId": "cal-webhook-0000-0000-0000-000000000001",
        },
        # 2. Resolve Artist by Token — UNION ALL always returns 1 row
        {
            "parameters": {
                "operation": "executeQuery",
                "query": RESOLVE_ARTIST_QUERY,
                "options": {
                    "queryReplacement": "={{ [$json.body.token, $json.body.action, $json.body.start_at, $json.body.end_at, $json.body.block_id, $json.body.duration_min] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [260, 0],
            "id": "cal-resolve-0000-0000-0000-000000000001",
            "name": "Resolve Artist by Token",
            "credentials": MAIN_DB,
        },
        # 3. Token Valid? gate
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
                            "id": "cal-if-token-cond-0000",
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
            "id": "cal-if-token-0000-0000-0000-000000000001",
            "name": "Token Valid?",
        },
        # 4. Invalid token response (false branch)
        {
            "parameters": {
                "respondWith": "json",
                "responseBody": "={{ JSON.stringify({ success: false, error: 'invalid_token', message: 'Token de artista inválido ou desconhecido.' }) }}",
                "options": {"responseCode": 401},
            },
            "type": "n8n-nodes-base.respondToWebhook",
            "typeVersion": 1.2,
            "position": [780, 200],
            "id": "cal-resp-bad-token-0000-0000-0000-000000000001",
            "name": "Invalid Token Response",
        },
        # 5. Route by action (switch, expression mode)
        {
            "parameters": {
                "mode": "expression",
                "numberOutputs": 4,
                "output": SWITCH_OUTPUT_EXPR,
                "options": {},
            },
            "type": "n8n-nodes-base.switch",
            "typeVersion": 3.2,
            "position": [780, -200],
            "id": "cal-switch-0000-0000-0000-000000000001",
            "name": "Route by Action",
        },
        # 6. List Availability (output 0)
        {
            "parameters": {
                "operation": "executeQuery",
                "query": LIST_AVAILABILITY_QUERY,
                "options": {
                    "queryReplacement": "={{ [$json.id, $json.start_at, $json.end_at, $json.duration_min] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1040, -400],
            "id": "cal-list-0000-0000-0000-000000000001",
            "name": "List Availability",
            "credentials": MAIN_DB,
        },
        # 7. Build List Response
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": BUILD_LIST_RESPONSE_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1300, -400],
            "id": "cal-list-resp-0000-0000-0000-000000000001",
            "name": "Build List Response",
        },
        # 8. Block Slot (output 1)
        {
            "parameters": {
                "operation": "executeQuery",
                "query": BLOCK_SLOT_QUERY,
                "options": {
                    "queryReplacement": "={{ [$json.id, $json.start_at, $json.end_at] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1040, -200],
            "id": "cal-block-0000-0000-0000-000000000001",
            "name": "Block Slot",
            "credentials": MAIN_DB,
        },
        # 9. Block Response
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": BLOCK_RESPONSE_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1300, -200],
            "id": "cal-block-resp-0000-0000-0000-000000000001",
            "name": "Build Block Response",
        },
        # 10. Unblock Slot (output 2)
        {
            "parameters": {
                "operation": "executeQuery",
                "query": UNBLOCK_SLOT_QUERY,
                "options": {
                    "queryReplacement": "={{ [$json.block_id] }}"
                },
            },
            "type": "n8n-nodes-base.postgres",
            "typeVersion": 2.6,
            "position": [1040, 0],
            "id": "cal-unblock-0000-0000-0000-000000000001",
            "name": "Unblock Slot",
            "credentials": MAIN_DB,
        },
        # 11. Unblock Response
        {
            "parameters": {
                "mode": "runOnceForAllItems",
                "jsCode": UNBLOCK_RESPONSE_JS,
            },
            "type": "n8n-nodes-base.code",
            "typeVersion": 2,
            "position": [1300, 0],
            "id": "cal-unblock-resp-0000-0000-0000-000000000001",
            "name": "Build Unblock Response",
        },
        # 12. Success response (shared by list/block/unblock branches)
        {
            "parameters": {
                "respondWith": "json",
                "responseBody": "={{ JSON.stringify($json) }}",
                "options": {"responseCode": 200},
            },
            "type": "n8n-nodes-base.respondToWebhook",
            "typeVersion": 1.2,
            "position": [1560, -200],
            "id": "cal-resp-ok-0000-0000-0000-000000000001",
            "name": "Calendar Response",
        },
        # 13. Invalid action response (switch output 3)
        {
            "parameters": {
                "respondWith": "json",
                "responseBody": "={{ JSON.stringify({ success: false, error: 'invalid_action', message: 'Ação desconhecida. Use list, block ou unblock.' }) }}",
                "options": {"responseCode": 400},
            },
            "type": "n8n-nodes-base.respondToWebhook",
            "typeVersion": 1.2,
            "position": [1040, -500],
            "id": "cal-resp-bad-action-0000-0000-0000-000000000001",
            "name": "Invalid Action Response",
        },
    ],
    "connections": {
        "Calendar Webhook": {
            "main": [[{"node": "Resolve Artist by Token", "type": "main", "index": 0}]]
        },
        "Resolve Artist by Token": {
            "main": [[{"node": "Token Valid?", "type": "main", "index": 0}]]
        },
        "Token Valid?": {
            "main": [
                [{"node": "Route by Action", "type": "main", "index": 0}],
                [{"node": "Invalid Token Response", "type": "main", "index": 0}],
            ]
        },
        "Route by Action": {
            "main": [
                [{"node": "List Availability", "type": "main", "index": 0}],
                [{"node": "Block Slot", "type": "main", "index": 0}],
                [{"node": "Unblock Slot", "type": "main", "index": 0}],
                [{"node": "Invalid Action Response", "type": "main", "index": 0}],
            ]
        },
        "List Availability": {
            "main": [[{"node": "Build List Response", "type": "main", "index": 0}]]
        },
        "Build List Response": {
            "main": [[{"node": "Calendar Response", "type": "main", "index": 0}]]
        },
        "Block Slot": {
            "main": [[{"node": "Build Block Response", "type": "main", "index": 0}]]
        },
        "Build Block Response": {
            "main": [[{"node": "Calendar Response", "type": "main", "index": 0}]]
        },
        "Unblock Slot": {
            "main": [[{"node": "Build Unblock Response", "type": "main", "index": 0}]]
        },
        "Build Unblock Response": {
            "main": [[{"node": "Calendar Response", "type": "main", "index": 0}]]
        },
    },
    "settings": {
        "executionOrder": "v1",
    },
}

out_path = os.path.join(BASE, "artist-calendar-webhook.json")
with open(out_path, "w", encoding="utf-8") as f:
    json.dump(WF, f, indent=2, ensure_ascii=False)
print(f"Wrote {out_path}")