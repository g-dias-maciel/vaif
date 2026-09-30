#!/usr/bin/env node
'use strict';
/**
 * Integration test — Artist Calendar Webhook + /agenda (real stack, OPT-IN).
 *
 * Seeds the test artist (slug `default` = Bruno) with two clients + two booked
 * tattoos, then exercises the real n8n calendar webhook the /agenda page calls:
 * list, block, unblock, suspend, resume, invalid token, invalid action.
 * Optionally fetches the /agenda/<token> page itself (set AGENDA_PAGE_URL).
 *
 * Everything it creates is removed at the end (also on failure).
 *
 * Env:
 *   N8N_API_KEY           (or ~/.n8n-api-key)
 *   N8N_BASE              default https://n8n.vaif.com.br
 *   CALENDAR_WEBHOOK_URL  default ${N8N_BASE}/webhook/calendar
 *   ARTIST_SLUG           default "default"
 *   AGENDA_PAGE_URL       optional, e.g. https://dev.vaif.com.br
 *
 * Run: node packages/flows/tests/integration/calendar-agenda.js
 */
const fs = require('fs');
const os = require('os');
const path = require('path');

const N8N_BASE = process.env.N8N_BASE || 'https://n8n.vaif.com.br';
const N8N_API = `${N8N_BASE}/api/v1`;
const WEBHOOK = process.env.CALENDAR_WEBHOOK_URL || `${N8N_BASE}/webhook/calendar`;
const AGENDA_PAGE_URL = process.env.AGENDA_PAGE_URL || '';
const SLUG = process.env.ARTIST_SLUG || 'default';
const PG_CRED = { postgres: { id: 'nngaQDfXHYQ1Q43P', name: 'main-db' } };
const TEST_PHONES = ['5511900000001', '5511900000002'];

function apiKey() {
  if (process.env.N8N_API_KEY) return process.env.N8N_API_KEY;
  const p = path.join(os.homedir(), '.n8n-api-key');
  if (fs.existsSync(p)) return fs.readFileSync(p, 'utf8').trim();
  return '';
}

async function n8n(method, p, body) {
  const res = await fetch(N8N_API + p, {
    method,
    headers: { 'X-N8N-API-KEY': apiKey(), 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  if (!res.ok) throw new Error(`n8n ${method} ${p} -> ${res.status}: ${text.slice(0, 300)}`);
  return text ? JSON.parse(text) : {};
}

async function withTempWorkflow(nodes, connections, fn) {
  const base = { name: `TEMP integration ${Date.now()}`, nodes, connections, settings: { executionOrder: 'v1' } };
  let id;
  try {
    id = (await n8n('POST', '/workflows', base)).id;
    const credNodes = nodes.map((n) => (n.credentials ? { ...n, credentials: n.credentials } : n));
    await n8n('PUT', `/workflows/${id}`, { name: base.name, nodes: credNodes, connections, settings: base.settings });
    await n8n('POST', `/workflows/${id}/activate`, {});
    await new Promise((r) => setTimeout(r, 2500));
    return await fn();
  } finally {
    if (id) {
      try { await n8n('POST', `/workflows/${id}/deactivate`, {}); } catch (e) { /* ignore */ }
      try { await n8n('DELETE', `/workflows/${id}`); } catch (e) { /* ignore */ }
    }
  }
}

/** Run one SQL statement on the main DB and return its first row (json). */
async function runSql(sql) {
  const hook = `temp-sql-${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  const nodes = [
    { parameters: { httpMethod: 'GET', path: hook, responseMode: 'lastNode', options: {} },
      type: 'n8n-nodes-base.webhook', typeVersion: 2, position: [0, 0], name: 'Webhook', webhookId: hook },
    { parameters: { operation: 'executeQuery', query: sql, options: {} },
      type: 'n8n-nodes-base.postgres', typeVersion: 2.6, position: [260, 0], name: 'SQL', credentials: PG_CRED },
  ];
  const connections = { Webhook: { main: [[{ node: 'SQL', type: 'main', index: 0 }]] } };
  return withTempWorkflow(nodes, connections, async () => {
    const res = await fetch(`${N8N_BASE}/webhook/${hook}`);
    const text = await res.text();
    if (!res.ok) throw new Error(`sql webhook ${res.status}: ${text.slice(0, 300)}`);
    return text ? JSON.parse(text) : {};
  });
}

async function calendar(body) {
  const res = await fetch(WEBHOOK, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const text = await res.text();
  let json = null;
  try { json = JSON.parse(text); } catch (e) { json = { raw: text }; }
  return { status: res.status, json };
}

// ── assertions ──
let passed = 0, failed = 0;
function check(label, ok, detail) {
  if (ok) { passed++; console.log(`  PASS: ${label}`); }
  else { failed++; console.log(`  FAIL: ${label}${detail !== undefined ? ' -> ' + JSON.stringify(detail).slice(0, 300) : ''}`); }
}

const SEED_SQL = `
WITH a AS (
  SELECT id FROM artists WHERE wa_session_slug = '${SLUG}' LIMIT 1
),
tok AS (
  UPDATE artists SET onboarding_token = COALESCE(onboarding_token, 'testagenda0001'), status = 'live'
  WHERE id = (SELECT id FROM a)
  RETURNING id, onboarding_token
),
l1 AS (
  INSERT INTO leads (artist_id, nome, telefone, placement, body_zone, pipeline_status)
  SELECT id, 'Teste Agenda A', '${TEST_PHONES[0]}', 'braco_externo', 'fechamento', 'agendado' FROM tok RETURNING id
),
l2 AS (
  INSERT INTO leads (artist_id, nome, telefone, placement, body_zone, pipeline_status)
  SELECT id, 'Teste Agenda B', '${TEST_PHONES[1]}', 'costas', 'grande', 'agendado' FROM tok RETURNING id
),
c1 AS (
  INSERT INTO calendar (artist_id, start_at, end_at, type, lead_id)
  SELECT (SELECT id FROM tok), now() + interval '2 days', now() + interval '2 days 3 hours', 'booked', id FROM l1 RETURNING id
),
c2 AS (
  INSERT INTO calendar (artist_id, start_at, end_at, type, lead_id)
  SELECT (SELECT id FROM tok), now() + interval '3 days', now() + interval '3 days 2 hours', 'booked', id FROM l2 RETURNING id
)
SELECT jsonb_build_object(
  'artist_slug', '${SLUG}',
  'token', (SELECT onboarding_token FROM tok),
  'lead_ids', (SELECT jsonb_agg(id) FROM (SELECT id FROM l1 UNION ALL SELECT id FROM l2) x),
  'calendar_ids', (SELECT jsonb_agg(id) FROM (SELECT id FROM c1 UNION ALL SELECT id FROM c2) y)
)::text AS seed;`;

const TEARDOWN_SQL = `
WITH l AS (
  SELECT id FROM leads WHERE telefone IN ('${TEST_PHONES.join("','")}')
),
d1 AS (DELETE FROM calendar WHERE lead_id IN (SELECT id FROM l) RETURNING 1),
d2 AS (DELETE FROM leads WHERE id IN (SELECT id FROM l) RETURNING 1),
d3 AS (UPDATE artists SET status = 'live' WHERE wa_session_slug = '${SLUG}' RETURNING 1)
SELECT jsonb_build_object(
  'calendar_removed', (SELECT count(*) FROM d1),
  'leads_removed', (SELECT count(*) FROM d2)
)::text AS teardown;`;

async function main() {
  if (!apiKey()) { console.error('No N8N_API_KEY (or ~/.n8n-api-key). Aborting.'); return 1; }
  console.log(`integration: webhook=${WEBHOOK} slug=${SLUG}`);
  console.log('seeding test clients + booked tattoos on the test artist...');
  const seeded = JSON.parse((await runSql(SEED_SQL)).seed);
  const token = seeded.token;
  console.log(`  token=${token} leads=${JSON.stringify(seeded.lead_ids)} calendar=${JSON.stringify(seeded.calendar_ids)}`);

  let blockId = null;
  try {
    // ── list ──
    let r = await calendar({ token, action: 'list' });
    check('list: HTTP 200', r.status === 200, r.status);
    check('list: success', r.json.success === true, r.json);
    check('list: resolves the artist', typeof r.json.artist_name === 'string' && r.json.artist_name.length > 0, r.json.artist_name);
    check('list: timezone present', typeof r.json.timezone === 'string' && r.json.timezone.length > 0, r.json.timezone);
    check('list: returns the seeded booked tattoos', Array.isArray(r.json.booked) && r.json.booked.length >= 2, (r.json.booked || []).length);
    const names = (r.json.booked || []).map((b) => b.client_name);
    check('list: booked tattoos carry client names', names.includes('Teste Agenda A') && names.includes('Teste Agenda B'), names);
    check('list: booked tattoos carry placement', (r.json.booked || []).every((b) => b.placement), r.json.booked);

    // calendar correctness: a booked tattoo must not appear as an available slot
    const overlaps = (a, b) => new Date(a.start_at) < new Date(b.end_at) && new Date(a.end_at) > new Date(b.start_at);
    const freeSlots = r.json.available || [];
    const booked = r.json.booked || [];
    check('calendar: booked tattoos are excluded from availability',
      !freeSlots.some((s) => booked.some((b) => overlaps(s, b))),
      freeSlots.filter((s) => booked.some((b) => overlaps(s, b))));

    // calendar correctness: blocking a free slot removes it from availability
    if (freeSlots.length) {
      const slot = freeSlots[0];
      let rb = await calendar({ token, action: 'block', start_at: slot.start_at, end_at: slot.end_at });
      check('calendar: blocking a free slot succeeds', rb.json.success === true, rb.json);
      const slotBlockId = rb.json.block && rb.json.block.id;
      let rl = await calendar({ token, action: 'list' });
      check('calendar: blocked slot disappears from availability',
        !(rl.json.available || []).some((s) => s.start_at === slot.start_at), rl.json.available && rl.json.available.length);
      await calendar({ token, action: 'unblock', block_id: slotBlockId });
      rl = await calendar({ token, action: 'list' });
      check('calendar: slot returns after unblocking',
        (rl.json.available || []).some((s) => s.start_at === slot.start_at), rl.json.available && rl.json.available.length);
    } else {
      console.log('  (no free slots to test block/availability interaction)');
    }

    // ── block ──
    const start = new Date(Date.now() + 10 * 24 * 3600 * 1000).toISOString();
    const end = new Date(Date.now() + 10 * 24 * 3600 * 1000 + 2 * 3600 * 1000).toISOString();
    r = await calendar({ token, action: 'block', start_at: start, end_at: end });
    check('block: success', r.json.success === true, r.json);
    blockId = r.json.block && r.json.block.id;
    check('block: returns the created block', !!blockId, r.json.block);

    // ── list sees the block ──
    r = await calendar({ token, action: 'list' });
    check('list: block is visible', (r.json.blocks || []).some((b) => b.id === blockId), r.json.blocks);

    // ── unblock ──
    r = await calendar({ token, action: 'unblock', block_id: blockId });
    check('unblock: success', r.json.success === true, r.json);
    r = await calendar({ token, action: 'list' });
    check('list: block is gone', !(r.json.blocks || []).some((b) => b.id === blockId), r.json.blocks);

    // ── suspend / resume ──
    r = await calendar({ token, action: 'suspend' });
    check('suspend: success + status suspended', r.json.success === true && r.json.status === 'suspended', r.json);
    r = await calendar({ token, action: 'list' });
    check('list: status reflects suspended', r.json.status === 'suspended', r.json.status);
    r = await calendar({ token, action: 'resume' });
    check('resume: success + status live', r.json.success === true && r.json.status === 'live', r.json);

    // ── error paths ──
    r = await calendar({ token: 'definitely-not-a-real-token', action: 'list' });
    check('invalid token: HTTP 401', r.status === 401 && r.json.error === 'invalid_token', r);
    r = await calendar({ token, action: 'nonsense' });
    check('invalid action: HTTP 400', r.status === 400 && r.json.error === 'invalid_action', r);

    // ── optional: the /agenda page itself ──
    if (AGENDA_PAGE_URL) {
      const url = `${AGENDA_PAGE_URL.replace(/\/$/, '')}/agenda/${token}`;
      const res = await fetch(url);
      const html = await res.text();
      check(`/agenda page: HTTP 200 (${url})`, res.status === 200, res.status);
      check('/agenda page: shows the artist', html.includes('Bruno'), 'artist not found in HTML');
      check('/agenda page: shows a seeded client', html.includes('Teste Agenda A'), 'client not found in HTML');
    } else {
      console.log('  (skipping /agenda page check — set AGENDA_PAGE_URL to include it)');
    }
  } finally {
    console.log('cleaning up seeded data...');
    const td = JSON.parse((await runSql(TEARDOWN_SQL)).teardown);
    console.log(`  removed ${td.leads_removed} leads, ${td.calendar_removed} calendar rows`);
  }

  console.log(`\n=== integration: ${passed} passed, ${failed} failed ===`);
  return failed === 0 ? 0 : 1;
}

main().then((c) => process.exit(c)).catch((e) => { console.error(e); process.exit(1); });
