#!/usr/bin/env node
'use strict';
/**
 * FULL workflow test — real Beatriz WhatsApp Agent end-to-end (OPT-IN).
 *
 * Drives the *deployed* workflow through the WAHA webhook with a reserved fake
 * lead number: real model, real tools, real DB, real Telegram notifications.
 * Seeds the test artist's lead at the booking stage, then verifies the reply,
 * the DB state, and the Telegram signal (deposit) / handoff notification.
 *
 * Telegram messages are left in the group on purpose (test results).
 * Everything created on the test artist is deleted at the end, and a reviewable
 * result file is written to tests/integration/results/.
 *
 * Env:
 *   N8N_API_KEY            (or ~/.n8n-api-key)
 *   N8N_BASE               default https://n8n.vaif.com.br
 *   WAHA_WEBHOOK_URL       default ${N8N_BASE}/webhook/waha-webhook
 *   BEATRIZ_WORKFLOW_ID    default SFfHtbGLkscEk47n
 *   ARTIST_SLUG            default "default"
 *   TURN_RETRIES           default 2
 *   TURN_TIMEOUT_MS        default 90000
 *
 * Run:
 *   node packages/flows/tests/integration/full-workflow.js
 *   node packages/flows/tests/integration/full-workflow.js --full   # + from-scratch run
 *   node packages/flows/tests/integration/full-workflow.js --only=promotion-bogo
 */
const fs = require('fs');
const path = require('path');
const { N8N_BASE, apiKey, runSql, postJson, getExecution, listExecutions, n8n } = require('./lib/stack');

const WEBHOOK = process.env.WAHA_WEBHOOK_URL || `${N8N_BASE}/webhook/waha-webhook`;
const WORKFLOW_ID = process.env.BEATRIZ_WORKFLOW_ID || 'SFfHtbGLkscEk47n';
// Dedicated, isolated test artist. Its wa_session_slug has NO connected WAHA
// session, so outbound WhatsApp sends fail harmlessly and the test can never
// message a real person. Telegram alerts still work (separate channel).
const SLUG = process.env.ARTIST_SLUG || 'sdr-test';
const TEST_GROUP = process.env.TEST_TELEGRAM_GROUP || '-5195870017';
const RETRIES = Number(process.env.TURN_RETRIES || 2);
const TIMEOUT_MS = Number(process.env.TURN_TIMEOUT_MS || 90000);
const RESULTS_DIR = path.join(__dirname, 'results');
const INCLUDE_FULL = process.argv.includes('--full');
// --only=promotion-bogo[,other] — run a subset while iterating.
const ONLY = (process.argv.find((a) => a.startsWith('--only=')) || '').slice('--only='.length)
  .split(',')
  .map((s) => s.trim())
  .filter(Boolean);

const TABLE_PRICE = 150000;   // seeded R$ 1.500
const DEPOSIT_PCT = 30;       // Bruno's sinal
const EXPECTED_DEPOSIT_CENTS = Math.round(TABLE_PRICE * DEPOSIT_PCT / 100); // 45000
const EXPECTED_DEPOSIT_TEXT = 'R$ ' + (EXPECTED_DEPOSIT_CENTS / 100).toFixed(2).replace('.', ',');

// Promotion scenario: the isolated test artist gets a real "Leve 2" offer,
// activated through the same SQL function the VAIF admin panel uses.
const TEST_ARTIST_ID = 'a0000000-0000-4000-8000-000000000001';
const PROMO_NAME = 'Leve 2 E2E';
const PROMO_MULTIPLIER = 2;
const PROMO_BASE_CENTS = TABLE_PRICE;                                   // braco_externo/fechamento
const PROMO_FIRST_CENTS = Math.ceil(PROMO_BASE_CENTS * PROMO_MULTIPLIER); // 300000
const PROMO_SECOND_CAP_CENTS = PROMO_BASE_CENTS;                       // 150000

const brl = (cents) => 'R$ ' + (cents / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const brlRegex = (cents) => new RegExp(brl(cents).replace(/[.$]/g, '\\$&'));

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const phoneFilter = (p) => `phone_e164 = '${p}'`;
// pt-BR currency formatting uses a non-breaking space after "R$"; normalise it
// so reply assertions can use ordinary spaces.
const norm = (s) => (typeof s === 'string' ? s.replace(/[\u00A0\u202F]/g, ' ') : s);
const has = (s, re) => typeof s === 'string' && re.test(norm(s));

// ── SQL helpers ──
async function resetLead(phone) {
  // `chat_memory` is keyed by the Lead id as text, so collect the ids before
  // the cascade removes the rows. Everything else hangs off the Lead FK.
  await runSql(`
    WITH l AS (SELECT id FROM leads WHERE ${phoneFilter(phone)})
    DELETE FROM chat_memory WHERE session_id IN (SELECT id::text FROM l);`);
  await runSql(`DELETE FROM events WHERE lead_id IN (SELECT id FROM leads WHERE ${phoneFilter(phone)});`);
  await runSql(`DELETE FROM leads WHERE ${phoneFilter(phone)};`);
  await runSql(`DELETE FROM chat_buffers WHERE chat_id = '${SLUG}:${phone}';`);
}

/**
 * Seed a Project already at the quote/deposit stage, with the gate open and a
 * live standard quote. `start_turn` reuses the Lead and its open Project.
 */
async function seedNearDeposit(phone) {
  await resetLead(phone);
  const row = await runSql(`
    WITH l AS (
      INSERT INTO leads (artist_id, name, phone_e164, source, last_message_at)
      SELECT id, 'Teste Full Workflow', '${phone}', 'whatsapp', now()
      FROM artists WHERE wa_session_slug = '${SLUG}'
      RETURNING id, artist_id
    ), p AS (
      INSERT INTO projects (artist_id, lead_id, placement, body_zone, stage_key)
      SELECT artist_id, id, 'braco_externo', 'fechamento', 'orcamento_enviado'
      FROM l
      RETURNING id, artist_id, lead_id
    ), q AS (
      INSERT INTO quotes (artist_id, project_id, kind, base_price_cents, offered_price_cents, status)
      SELECT artist_id, id, 'standard', ${TABLE_PRICE}, ${TABLE_PRICE}, 'open' FROM p
      RETURNING project_id
    )
    INSERT INTO chat_sessions (artist_id, chat_id, lead_id, agent_state)
    SELECT p.artist_id, '${SLUG}:${phone}', p.lead_id,
           '{"processo_explicado": true, "preco_liberado": true}'::jsonb
    FROM p
    ON CONFLICT (artist_id, chat_id) DO UPDATE SET
      lead_id = EXCLUDED.lead_id, agent_state = EXCLUDED.agent_state
    RETURNING lead_id::text AS lead_id;`);
  return row.lead_id;
}

/**
 * Seed a Lead standing exactly at the price gate with the doubts checkpoint
 * already cleared. That is the only state where the deterministic first quote
 * fires — which is also the code path that injects the promotion.
 *
 * `preco_liberado = false` is deliberate: once it is true the workflow takes
 * the "repeat the quote" path instead of quoting for the first time.
 */
async function seedNearQuote(phone) {
  await resetLead(phone);
  const row = await runSql(`
    WITH l AS (
      INSERT INTO leads (artist_id, name, phone_e164, source, last_message_at)
      SELECT id, 'Teste Promo', '${phone}', 'whatsapp', now()
      FROM artists WHERE wa_session_slug = '${SLUG}'
      RETURNING id, artist_id
    ), p AS (
      INSERT INTO projects (artist_id, lead_id, placement, body_zone, stage_key)
      SELECT artist_id, id, 'braco_externo', 'fechamento', 'qualificando'
      FROM l
      RETURNING id, artist_id, lead_id
    )
    INSERT INTO chat_sessions (artist_id, chat_id, lead_id, agent_state)
    SELECT p.artist_id, '${SLUG}:${phone}', p.lead_id,
           '{"processo_explicado": true, "duvidas_eliminadas": true, "preco_liberado": false}'::jsonb
    FROM p
    ON CONFLICT (artist_id, chat_id) DO UPDATE SET
      lead_id = EXCLUDED.lead_id, agent_state = EXCLUDED.agent_state
    RETURNING lead_id::text AS lead_id;`);
  return row.lead_id;
}

// ── promotion helpers (real SQL functions used by the VAIF /promo admin) ──

/**
 * Promotions need two things that are deployed separately from this repo's
 * code: the offers schema in the live database and the promotion nodes in the
 * live workflow. Check both before driving traffic, so a missing deploy reads
 * as a clear infrastructure problem instead of a confusing test failure.
 */
async function promotionPreflight() {
  const issues = [];
  try {
    await runSql('SELECT count(*)::int AS n FROM promotions');
  } catch (e) {
    issues.push('DB: `promotions` table/function not found — apply packages/crm/db/migrations/0001_schema_v2.sql and 0002_functions_v2.sql');
  }
  try {
    const wf = await n8n('GET', `/workflows/${WORKFLOW_ID}`);
    const names = (wf.nodes || []).map((n) => n.name);
    for (const node of ['Load Offer', 'Write Promotion Quote']) {
      if (!names.includes(node)) {
        issues.push(`workflow: node "${node}" is not deployed — run scripts/deploy.py update ${WORKFLOW_ID} definitions/beatriz-whatsapp-agent.json`);
      }
    }
  } catch (e) {
    issues.push(`workflow: could not inspect live workflow (${e.message})`);
  }
  return issues;
}

async function clearTestPromotion() {
  // Deleting cascades to promotion_prices + promotion_quotes, so the test
  // leaves no offer state behind.
  await runSql(`DELETE FROM promotions WHERE artist_id = '${TEST_ARTIST_ID}' AND name = '${PROMO_NAME}';`);
}

async function activateTestPromotion() {
  await clearTestPromotion();
  const inserted = await runSql(`
    WITH ins AS (
      INSERT INTO promotions (artist_id, type_key, name, multiplier, audience, conditions, quote_valid_days, status)
      VALUES ('${TEST_ARTIST_ID}', 'bogo_same_value', '${PROMO_NAME}', ${PROMO_MULTIPLIER}, 'all', '{}'::jsonb, 7, 'draft')
      RETURNING id
    )
    SELECT id::text AS promotion_id FROM ins;`);
  const id = inserted.promotion_id;

  // Same activation function the admin panel calls: snapshots the pricing
  // matrix into promotion_prices at `multiplier` and flips status to active.
  await runSql(`SELECT 1 AS ok FROM activate_promotion('${TEST_ARTIST_ID}', '${id}');`);

  const state = await runSql(`
    SELECT jsonb_build_object(
      'status', p.status,
      'multiplier', p.multiplier,
      'snapshot_count', (SELECT count(*) FROM promotion_prices WHERE promotion_id = p.id),
      'first_price', (SELECT offer_price_cents FROM promotion_prices
                      WHERE promotion_id = p.id AND placement = 'braco_externo' AND body_zone = 'fechamento'),
      'second_cap', (SELECT base_price_cents FROM promotion_prices
                     WHERE promotion_id = p.id AND placement = 'braco_externo' AND body_zone = 'fechamento')
    )::text AS state
    FROM promotions p WHERE p.id = '${id}';`);

  return { id, ...JSON.parse(state.state) };
}

async function pauseTestPromotion(promotionId) {
  await runSql(`SELECT 1 AS ok FROM pause_promotion('${promotionId}');`);
  const row = await runSql(`SELECT status::text AS status FROM promotions WHERE id = '${promotionId}';`);
  return row.status;
}

/** The Project's latest promotion quote (price-protection row), if any. */
async function promoQuoteFor(phone) {
  const row = await runSql(`
    SELECT jsonb_build_object(
      'count', (SELECT count(*) FROM quotes q2
                 JOIN projects p2 ON p2.id = q2.project_id
                 JOIN leads l2 ON l2.id = p2.lead_id
                WHERE l2.phone_e164 = '${phone}' AND q2.kind = 'promotion'),
      'promotion_id', q.promotion_id,
      'status', q.status,
      'placement', p.placement,
      'body_zone', p.body_zone,
      'first_price', q.offered_price_cents,
      'second_cap_price', q.second_cap_cents,
      'valid_until', q.valid_until::text,
      'valid_days', round(extract(epoch from (q.valid_until - now())) / 86400.0, 1)
    )::text AS q
    FROM leads l
    JOIN projects p ON p.lead_id = l.id
    LEFT JOIN LATERAL (
      SELECT * FROM quotes WHERE project_id = p.id AND kind = 'promotion'
       ORDER BY created_at DESC LIMIT 1
    ) q ON true
    WHERE l.phone_e164 = '${phone}'
    ORDER BY p.created_at DESC
    LIMIT 1;`);
  return row.q ? JSON.parse(row.q) : null;
}

async function leadState(phone) {
  const row = await runSql(`
    SELECT jsonb_build_object(
      'stage_key', p.stage_key,
      'deposit_status', COALESCE((
        SELECT CASE pay.status
                 WHEN 'confirmed' THEN 'confirmado'
                 WHEN 'requested' THEN 'aguardando_confirmacao'
                 ELSE 'nao_solicitado' END
        FROM payments pay WHERE pay.project_id = p.id AND pay.kind = 'deposit'
        ORDER BY pay.requested_at DESC LIMIT 1), 'nao_solicitado'),
      'deposit_amount', (SELECT amount_cents FROM payments
                          WHERE project_id = p.id AND kind = 'deposit'
                          ORDER BY requested_at DESC LIMIT 1),
      'booked_date', (SELECT start_at::text FROM calendar_entries
                       WHERE project_id = p.id AND kind = 'booking' AND active
                       ORDER BY start_at DESC LIMIT 1),
      'preco_liberado', COALESCE((SELECT (cs.agent_state->>'preco_liberado')::boolean
                                    FROM chat_sessions cs WHERE cs.lead_id = p.lead_id
                                   ORDER BY cs.updated_at DESC LIMIT 1), false),
      'table_price', (SELECT pb.base_price_cents FROM price_book pb
                       WHERE pb.artist_id = p.artist_id AND pb.placement = p.placement
                         AND pb.body_zone = p.body_zone AND pb.effective_to IS NULL),
      'quoted_price', (SELECT q.offered_price_cents FROM quotes q
                        WHERE q.project_id = p.id AND q.status = 'open'
                        ORDER BY q.created_at DESC LIMIT 1)
    )::text AS state
    FROM projects p
    JOIN leads l ON l.id = p.lead_id
    WHERE l.phone_e164 = '${phone}'
    ORDER BY p.created_at DESC LIMIT 1;`);
  return row.state ? JSON.parse(row.state) : null;
}

async function bookedIsRealSlot(phone) {
  // Validate the booked instant against the artist's working hours (the same
  // source check_availability derives from). We cannot use check_availability
  // here: the slot is now booked, so it is correctly no longer "available".
  const row = await runSql(`
    WITH x AS (
      SELECT ce.start_at,
             COALESCE(s.working_hours, '{}'::jsonb) AS working_hours,
             to_char(ce.start_at AT TIME ZONE COALESCE(NULLIF(a.timezone, ''), 'UTC'), 'HH24:MI') AS hm,
             (ARRAY['seg','ter','qua','qui','sex','sab','dom'])[
               EXTRACT(ISODOW FROM (ce.start_at AT TIME ZONE COALESCE(NULLIF(a.timezone, ''), 'UTC')))::int
             ] AS dow
      FROM calendar_entries ce
      JOIN projects p ON p.id = ce.project_id
      JOIN leads l ON l.id = p.lead_id
      JOIN artists a ON a.id = ce.artist_id
      LEFT JOIN artist_settings s ON s.artist_id = a.id
      WHERE l.phone_e164 = '${phone}' AND ce.kind = 'booking' AND ce.active
      ORDER BY ce.start_at DESC
      LIMIT 1
    )
    SELECT
      (SELECT start_at::text FROM x LIMIT 1) AS booked,
      COALESCE(bool_or(
        x.hm ~ '^[0-2][0-9]:00$'
        AND EXISTS (
          SELECT 1 FROM jsonb_array_elements_text(x.working_hours -> x.dow) b
          WHERE split_part(b, '-', 1) <= x.hm AND x.hm < split_part(b, '-', 2)
        )
      ), false)::text AS ok
    FROM x;`);
  return row;
}

async function artistPrecheck() {
  const row = await runSql(`
    SELECT jsonb_build_object(
      'slug', a.wa_session_slug, 'status', a.status,
      'ai_active_hours', s.ai_active_hours,
      'telegram_group_id', s.telegram_group_id)::text AS a
    FROM artists a LEFT JOIN artist_settings s ON s.artist_id = a.id
    WHERE a.wa_session_slug = '${SLUG}';`);
  return row.a ? JSON.parse(row.a) : null;
}

/**
 * Ensure a dedicated, isolated test artist exists. Its `wa_session_slug` has
 * no connected WAHA session, so the workflow's outbound WhatsApp nodes fail
 * harmlessly (onError: continue) — the test NEVER messages a real person.
 * Telegram notifications still work, so the signal/handoff alerts are verified.
 */
async function ensureTestArtist() {
  await runSql(`
    INSERT INTO artists (id, name, timezone, wa_session_slug, status)
    VALUES ('a0000000-0000-4000-8000-000000000001', 'SDR Test', 'America/Sao_Paulo', '${SLUG}', 'live')
    ON CONFLICT (id) DO UPDATE SET
      wa_session_slug = EXCLUDED.wa_session_slug,
      status = 'live',
      name = EXCLUDED.name,
      timezone = EXCLUDED.timezone;`);
  await runSql(`
    INSERT INTO artist_settings (artist_id, display_name, specialties, excluded_styles, floor_pct,
                                 deposit_type, deposit_value, pix_key, instagram_handle,
                                 working_hours, ai_active_hours, telegram_group_id)
    VALUES ('a0000000-0000-4000-8000-000000000001', 'SDR Test',
      ARRAY['realismo', 'blackwork'], ARRAY[]::text[], 80.00, 'percent', 30,
      'sdr.test@pix.example', '@sdr_test',
      '{"seg":["09:00-12:00","14:00-18:00"],"ter":["09:00-12:00","14:00-18:00"],"qua":["09:00-12:00","14:00-18:00"],"qui":["09:00-12:00","14:00-18:00"],"sex":["14:00-18:00"],"sab":["09:00-13:00"]}'::jsonb,
      '{}'::jsonb, NULLIF('${TEST_GROUP}', ''))
    ON CONFLICT (artist_id) DO UPDATE SET
      display_name = EXCLUDED.display_name,
      floor_pct = EXCLUDED.floor_pct,
      deposit_type = EXCLUDED.deposit_type,
      deposit_value = EXCLUDED.deposit_value,
      pix_key = EXCLUDED.pix_key,
      instagram_handle = EXCLUDED.instagram_handle,
      working_hours = EXCLUDED.working_hours,
      telegram_group_id = EXCLUDED.telegram_group_id;`);
  await runSql(`
    INSERT INTO price_book (artist_id, placement, body_zone, base_price_cents, session_duration_min, buffer_min)
    SELECT 'a0000000-0000-4000-8000-000000000001', v.placement, v.zone, v.price, 120, 30
    FROM (VALUES
      ('antebraco','pequeno',30000),('antebraco','medio',60000),('antebraco','grande',90000),('antebraco','fechamento',120000),
      ('braco_externo','pequeno',30000),('braco_externo','medio',60000),('braco_externo','grande',90000),('braco_externo','fechamento',150000),
      ('costas','pequeno',35000),('costas','medio',70000),('costas','grande',120000),('costas','fechamento',200000)
    ) AS v(placement, zone, price)
    ON CONFLICT (artist_id, placement, body_zone) WHERE effective_to IS NULL
    DO UPDATE SET
      base_price_cents = EXCLUDED.base_price_cents,
      session_duration_min = EXCLUDED.session_duration_min,
      buffer_min = EXCLUDED.buffer_min;`);
}

// ── workflow driving ──
function wahaPayload(phone, text) {
  return {
    event: 'message',
    session: SLUG,
    payload: {
      id: `${phone}@c.us`, from: `${phone}@c.us`, fromMe: false, type: 'chat',
      body: text, timestamp: Math.floor(Date.now() / 1000),
      _data: { notifyName: 'Teste Full Workflow' },
    },
  };
}

async function maxExecId() {
  const ex = await listExecutions(WORKFLOW_ID, 20);
  return ex.reduce((m, e) => Math.max(m, Number(e.id) || 0), 0);
}

function firstNode(runData, name) {
  const a = runData[name];
  if (!a || !a.length) return null;
  try { return a[0].data.main[0][0].json; } catch (e) { return null; }
}

function allItems(runData, name) {
  const a = runData[name];
  if (!a || !a.length) return [];
  const d = a[0].data || {};
  const arr = (d.main && d.main[0]) || (d.ai_tool && d.ai_tool[0]) || [];
  try { return arr.map((i) => i.json); } catch (e) { return []; }
}

function extractTurn(exec) {
  const run = (exec.data && exec.data.resultData && exec.data.resultData.runData) || {};
  const pc = firstNode(run, 'Parse Classification') || {};
  const notif = firstNode(run, 'Build Handoff Message') || {};
  const tg = run['Send Notification to Group'] && run['Send Notification to Group'][0];
  return {
    reply: pc.final_reply || '',
    aiOutput: (firstNode(run, 'AI Agent') || {}).output || '',
    pipeline: pc.stage_key || null,
    offerQuoted: pc.offer_quoted === true,
    gateViolation: pc.price_gate_violation === true,
    notification: notif,
    telegramSent: tg ? !tg.error : false,
    availability: allItems(run, 'Check Availability'),
    // Promotion path: what the workflow loaded for this turn, and what the
    // deterministic quote-recording node wrote.
    loadOffer: firstNode(run, 'Load Offer') || null,
    promoQuoteWritten: allItems(run, 'Write Promotion Quote'),
  };
}

async function waitForTurn(phone, baselineId) {
  const deadline = Date.now() + TIMEOUT_MS;
  while (Date.now() < deadline) {
    const ex = await listExecutions(WORKFLOW_ID, 40);
    const candidates = ex
      .filter((e) => (Number(e.id) || 0) > baselineId)
      .filter((e) => e.finished === true || ['success', 'error', 'crashed'].includes(e.status))
      .sort((a, b) => Number(a.id) - Number(b.id));
    for (const c of candidates) {
      let full;
      try { full = await getExecution(c.id); } catch (e) { continue; }
      const run = (full.data && full.data.resultData && full.data.resultData.runData) || {};
      const ds = firstNode(run, 'Debounce Start');
      if (!ds || ds.phone !== phone) continue;
      if (!run['Parse Classification']) continue;
      return { id: c.id, ...extractTurn(full) };
    }
    await sleep(3000);
  }
  return null;
}

async function runTurn(phone, text, expectFn) {
  let best = null;
  for (let attempt = 1; attempt <= RETRIES + 1; attempt++) {
    const baseline = await maxExecId();
    const post = await postJson(WEBHOOK, wahaPayload(phone, text));
    const turn = await waitForTurn(phone, baseline);
    const checks = turn ? expectFn(turn) : [{ label: 'workflow produced a reply execution', ok: false, detail: `webhook HTTP ${post.status}` }];
    const passed = checks.filter((c) => c.ok).length;
    const candidate = { turn, checks, passed, attempt, webhookStatus: post.status };
    if (!best || passed > best.passed) best = candidate;
    if (checks.every((c) => c.ok)) break;
    if (attempt <= RETRIES) await sleep(2500);
  }
  return best;
}

// ── reply parsing helpers ──
function firstDay(reply) {
  const m = (reply || '').match(/(segunda|terça|quarta|quinta|sexta|sábado|domingo)/i);
  return m ? m[1] : 'segunda';
}
function firstTime(reply) {
  const m = (reply || '').match(/(\d{1,2})\s*(?:horas|h\b)/i);
  return m ? `às ${m[1]} horas` : 'às 14 horas';
}
const replied = (t) => [{ label: 'workflow replied', ok: !!(t && t.reply), detail: t && t.reply }];

function recordTurn(index, leadText, res) {
  const t = res.turn;
  return {
    index, leadText,
    reply: t ? t.reply : null,
    aiOutput: t ? t.aiOutput : null,
    notification: t ? t.notification : null,
    telegramSent: t ? t.telegramSent : null,
    gateViolation: t ? t.gateViolation : null,
    offerQuoted: t ? t.offerQuoted : null,
    availability: t ? t.availability : [],
    loadOffer: t ? t.loadOffer : null,
    promoQuoteWritten: t ? t.promoQuoteWritten : [],
    executionId: t ? t.id : null,
    attempts: res.attempt,
    checks: res.checks,
  };
}

/**
 * Adaptive driver: keeps offering/choosing days and times until a deposit
 * (signal) alert fires or we run out of turns. Handles models that offer
 * day+time in one message.
 */
async function depositDriver(phone, runTurn) {
  const turns = [];
  let reply = '';
  for (let i = 0; i < 5; i++) {
    const text = i === 0
      ? 'fechado, pode ser'
      : (has(reply, /\d{1,2}\s*(horas|h\b)/i) ? firstTime(reply) : firstDay(reply));
    const res = await runTurn(phone, text, replied);
    const rec = recordTurn(i, text, res);
    turns.push(rec);
    if (!res.turn) break;
    reply = res.turn.reply || reply;
    const n = res.turn.notification;
    if (n && n.send === true && has(n.text, /sinal/i)) {
      return { turns, deposit: res.turn };
    }
  }
  return { turns, deposit: null };
}

// ── scenarios ──
const SCENARIOS = [
  {
    name: 'deposit-signal',
    description: 'Seed near booking → accept → choose slot → Beatriz books and requests the signal; the Telegram deposit alert is sent with the correct amount.',
    setup: seedNearDeposit,
    driver: depositDriver,
    assess: ({ deposit, turns }) => {
      const checks = [];
      checks.push({ label: 'an appointment slot was offered', ok: turns.some((t) => has(t.reply, /(horas|dia\s*\d+|segunda|terça|quarta|quinta|sexta|sábado|domingo)/i)), detail: turns.map((t) => t.reply).filter(Boolean)[0] });
      checks.push({ label: 'a deposit (signal) alert fired', ok: !!deposit, detail: JSON.stringify(turns.map((t) => t.notification && t.notification.send)) });
      if (deposit) {
        checks.push({ label: 'reply confirms the slot and requests the signal', ok: has(deposit.reply, /sinal|pix|fechado/i), detail: deposit.reply });
        checks.push({ label: 'alert text is "Sinal solicitado"', ok: has(deposit.notification.text, /Sinal solicitado/i), detail: deposit.notification.text });
        checks.push({ label: `alert states the correct signal amount (${EXPECTED_DEPOSIT_TEXT})`, ok: has(deposit.notification.text, new RegExp(EXPECTED_DEPOSIT_TEXT.replace(/[.$]/g, '\\$&'))), detail: deposit.notification.text });
        checks.push({ label: 'alert carries a deposit:confirm callback', ok: has(deposit.notification.btn1_cb, /^deposit:confirm:/), detail: deposit.notification.btn1_cb });
        checks.push({ label: 'alert shows the lead phone + WhatsApp link', ok: has(deposit.notification.text, /Telefone:/i) && has(deposit.notification.text, /https:\/\/wa\.me\//), detail: deposit.notification.text });
        checks.push({ label: 'Telegram send node succeeded', ok: deposit.telegramSent === true, detail: deposit.telegramSent });
      }
      return checks;
    },
    finalize: async (phone, { turns }) => {
      const st = await leadState(phone);
      const slotRow = await bookedIsRealSlot(phone);
      const offered = turns
        .flatMap((t) => (t.availability || []).map((s) => new Date(s.start_at).getTime()))
        .filter((n) => !isNaN(n));
      const booked = st && st.booked_date ? new Date(st.booked_date).getTime() : null;
      return [
        { label: 'DB: deposit_status = aguardando_confirmacao', ok: st && st.deposit_status === 'aguardando_confirmacao', detail: JSON.stringify(st) },
        { label: 'DB: a booked date was stored', ok: !!st && !!st.booked_date, detail: st && st.booked_date },
        { label: 'DB: booked date is in the future', ok: booked !== null && booked > Date.now(), detail: st && st.booked_date },
        { label: 'DB: booked date falls in the artist working hours', ok: slotRow.ok === 'true', detail: slotRow },
        { label: 'DB: booked date matches a slot from Check Availability (when the model called it)', ok: offered.length === 0 || offered.includes(booked), detail: { booked: st && st.booked_date, offeredCount: offered.length } },
      ];
    },
  },
  {
    name: 'deposit-signal-objection',
    description: 'Reach the signal request, then the lead questions it; Beatriz explains it reserves the slot and is discounted from the total.',
    setup: seedNearDeposit,
    driver: async (phone, runTurn) => {
      const out = await depositDriver(phone, runTurn);
      if (out.deposit) {
        const res = await runTurn(phone, 'por que preciso pagar sinal?', (t) => [
          { label: 'explains the signal reserves the slot / is discounted', ok: has(t.reply, /(reserv|descont|garant)/i), detail: t.reply },
        ]);
        out.turns.push(recordTurn(out.turns.length, 'por que preciso pagar sinal?', res));
      }
      return out;
    },
    assess: ({ deposit, turns }) => ([
      { label: 'a deposit (signal) alert fired', ok: !!deposit, detail: JSON.stringify(turns.map((t) => t.notification && t.notification.send)) },
      { label: 'objection handled (reserve/discount explained)', ok: turns.some((t) => has(t.reply, /(reserv|descont|garant)/i)), detail: turns.map((t) => t.reply).filter(Boolean).slice(-1)[0] },
    ]),
  },
  {
    name: 'coverup-handoff',
    description: 'Cover-up request → Beatriz hands off to the artist; the Telegram handoff alert with a WhatsApp link is sent.',
    setup: async (phone) => { await resetLead(phone); },
    turns: [
      { text: 'oi, quero cobrir uma tatuagem antiga no braço', expect: (t) => [
        { label: 'hands off to the artist', ok: has(t.reply, /passar|encaminh|Bruno/i), detail: t.reply },
        { label: 'Telegram handoff alert was built', ok: t.notification && t.notification.send === true, detail: t.notification },
        { label: 'handoff alert shows the reason', ok: has(t.notification && t.notification.text, /(Cover-up|Reforma|Handoff)/i), detail: t.notification && t.notification.text },
        { label: 'handoff confirm callback present', ok: has(t.notification && t.notification.btn1_cb, /^handoff:confirm:/), detail: t.notification && t.notification.btn1_cb },
        { label: 'handoff shows the lead phone + WhatsApp link', ok: has(t.notification && t.notification.text, /Telefone:/i) && has(t.notification && t.notification.text, /https:\/\/wa\.me\//), detail: t.notification && t.notification.text },
      ] },
    ],
  },
  {
    name: 'promotion-bogo',
    description: 'Activate a real "Leve 2" promotion → Beatriz quotes the promoted price (2x) plus the second tattoo, records a 7-day promotion quote, repeats the same price on a second ask, then pause → the open quote is honoured but a new lead gets the regular price.',
    setup: async (phone) => { await seedNearQuote(phone); },
    driver: async (phone, runTurn) => {
      const out = {
        turns: [],
        promo: null,
        quoteAfterFirst: null,
        statusAfterPause: null,
        quoteAfterPause: null,
        freshQuote: null,
      };

      // Activate through the admin SQL function (snapshots pricing at 2x).
      out.promo = await activateTestPromotion();

      const quoteText = 'quero fechar, é no braço externo fechamento';

      // 1. Promotion live → the first deterministic quote must carry the offer.
      const r1 = await runTurn(phone, quoteText, (t) => [
        { label: 'Load Offer saw the active promotion', ok: !!(t.loadOffer && t.loadOffer.promo_active === true), detail: t.loadOffer },
        { label: `reply quotes the promoted price (${brl(PROMO_FIRST_CENTS)})`, ok: has(t.reply, brlRegex(PROMO_FIRST_CENTS)), detail: t.reply },
        { label: 'reply offers the second tattoo', ok: has(t.reply, /segunda tatuagem|segunda tattoo/i), detail: t.reply },
        { label: 'reply does not quote the regular single-tattoo price', ok: !has(t.reply, brlRegex(PROMO_BASE_CENTS)), detail: t.reply },
      ]);
      out.turns.push(recordTurn(0, quoteText, r1));
      out.quoteAfterFirst = await promoQuoteFor(phone);

      // 2. The lead asks the price again while the promotion is still live.
      //    This is the "repeat the quote" path: the multiplier must NOT be
      //    applied a second time on top of the price already sent.
      const repeatText = 'e quanto fica mesmo?';
      const r2 = await runTurn(phone, repeatText, (t) => [
        { label: `repeat ask repeats the quoted price (${brl(PROMO_FIRST_CENTS)})`, ok: has(t.reply, brlRegex(PROMO_FIRST_CENTS)), detail: t.reply },
        { label: 'repeat ask does not apply the multiplier twice', ok: !has(t.reply, brlRegex(PROMO_FIRST_CENTS * PROMO_MULTIPLIER)), detail: t.reply },
      ]);
      out.turns.push(recordTurn(1, repeatText, r2));

      // 3. Pause → the quote's 7-day price protection must keep the price.
      out.statusAfterPause = await pauseTestPromotion(out.promo.id);
      const afterPauseText = 'e quanto fica agora?';
      const r3 = await runTurn(phone, afterPauseText, (t) => [
        { label: 'Load Offer: promotion paused but the open quote is still valid', ok: !!(t.loadOffer && t.loadOffer.promo_active !== true && t.loadOffer.open_quote_valid === true), detail: t.loadOffer },
        { label: 'open quote price is honoured after the pause', ok: has(t.reply, brlRegex(PROMO_FIRST_CENTS)), detail: t.reply },
      ]);
      out.turns.push(recordTurn(2, afterPauseText, r3));
      out.quoteAfterPause = await promoQuoteFor(phone);

      // 4. A brand-new lead while the promotion is paused: regular price, no offer.
      await seedNearQuote(phone);
      const r4 = await runTurn(phone, quoteText, (t) => [
        { label: 'fresh lead: no promotion and no open quote in context', ok: !!(t.loadOffer && t.loadOffer.promo_active !== true && t.loadOffer.open_quote_valid !== true), detail: t.loadOffer },
        { label: `fresh lead is quoted the regular price (${brl(PROMO_BASE_CENTS)})`, ok: has(t.reply, brlRegex(PROMO_BASE_CENTS)), detail: t.reply },
        { label: 'fresh lead is not offered a second tattoo', ok: !has(t.reply, /segunda tatuagem|segunda tattoo/i), detail: t.reply },
      ]);
      out.turns.push(recordTurn(3, quoteText, r4));
      out.freshQuote = await promoQuoteFor(phone);

      return out;
    },
    assess: (out) => {
      const promo = out.promo || {};
      const t1 = out.turns[0] || {};
      const t2 = out.turns[1] || {};
      const t3 = out.turns[2] || {};
      const t4 = out.turns[3] || {};
      const q1 = out.quoteAfterFirst || {};
      const q2 = out.quoteAfterPause || {};
      const q4 = out.freshQuote || {};
      const written = (t1.promoQuoteWritten || [])[0] || null;

      return [
        { label: 'promotion activated and priced from the table', ok: promo.status === 'active' && promo.snapshot_count > 0, detail: promo },
        { label: `activation snapshot is ${brl(PROMO_FIRST_CENTS)} (cap ${brl(PROMO_SECOND_CAP_CENTS)})`, ok: promo.first_price === PROMO_FIRST_CENTS && promo.second_cap === PROMO_SECOND_CAP_CENTS, detail: promo },
        { label: 'Load Offer names the promotion + multiplier', ok: !!(t1.loadOffer && t1.loadOffer.name === PROMO_NAME && Number(t1.loadOffer.multiplier) === PROMO_MULTIPLIER), detail: t1.loadOffer },
        { label: 'classifier flagged the turn as an offer quote', ok: t1.offerQuoted === true, detail: t1.offerQuoted },
        { label: 'Write Promotion Quote node returned a quote row', ok: !!written && Number(written.offered_price_cents) === PROMO_FIRST_CENTS, detail: written },
        { label: 'DB: exactly one promotion quote for the lead', ok: q1.count === 1, detail: q1 },
        { label: 'DB: quote is open with the 2x price and the second-tattoo cap', ok: q1.status === 'open' && q1.first_price === PROMO_FIRST_CENTS && q1.second_cap_price === PROMO_SECOND_CAP_CENTS, detail: q1 },
        { label: 'DB: quote is bound to the quoted placement', ok: q1.placement === 'braco_externo' && q1.body_zone === 'fechamento', detail: q1 },
        { label: 'DB: quote carries the 7-day protection window', ok: typeof q1.valid_days === 'number' && q1.valid_days > 6.5 && q1.valid_days <= 7.1, detail: q1 },
        { label: 'repeat ask still saw the active promotion', ok: !!(t2.loadOffer && t2.loadOffer.promo_active === true), detail: t2.loadOffer },
        { label: 'pause flipped the promotion to paused', ok: out.statusAfterPause === 'paused', detail: out.statusAfterPause },
        { label: 'DB: pausing keeps the quote open (no void)', ok: q2.status === 'open', detail: q2 },
        { label: 'fresh lead sees neither the promotion nor a quote', ok: !!(t4.loadOffer && t4.loadOffer.promo_active !== true && t4.loadOffer.open_quote_valid !== true), detail: t4.loadOffer },
        { label: 'DB: fresh lead has no promotion quote', ok: q4.count === 0, detail: q4 },
        { label: 'open-quote turn was served by the workflow (no price-gate violation)', ok: t3.gateViolation === false, detail: t3.gateViolation },
      ];
    },
    teardown: async () => { await clearTestPromotion(); },
  },
];

if (INCLUDE_FULL) {
  SCENARIOS.push({
    name: 'full-from-scratch',
    description: 'Best-effort full conversation from a brand-new lead through qualification and process.',
    setup: async (phone) => { await resetLead(phone); },
    turns: [
      { text: 'Oi, quero fazer uma tatuagem', expect: (t) => [{ label: 'introduces herself', ok: has(t.reply, /Beatriz|assistente/i), detail: t.reply }] },
      { text: 'É nova, no antebraço, estilo realismo', expect: replied },
      { text: 'É pela estética, não tem significado', expect: replied },
    ],
  });
}

// ── runner ──
async function runScenario(scenario, phone) {
  console.log(`\n──────── ${scenario.name} ────────`);
  await scenario.setup(phone);
  let turns = [];
  let assessChecks = [];

  if (scenario.driver) {
    const out = await scenario.driver(phone, runTurn);
    turns = out.turns;
    assessChecks = scenario.assess ? scenario.assess(out) : [];
    for (const t of turns) {
      console.log(`  turn ${t.index + 1}: lead="${t.leadText}"  (attempts: ${t.attempts}${t.executionId ? `, exec ${t.executionId}` : ''})`);
      console.log(`    reply: ${JSON.stringify(t.reply)}`);
      if (t.notification && t.notification.send) console.log(`    telegram: ${JSON.stringify(t.notification.text)}`);
    }
    for (const c of assessChecks) console.log(`    ${c.ok ? 'PASS' : 'FAIL'}: ${c.label}${c.ok ? '' : ' -> ' + JSON.stringify(c.detail)}`);
  } else {
    let prevReply = '';
    for (let i = 0; i < scenario.turns.length; i++) {
      const def = scenario.turns[i];
      const text = typeof def.text === 'function' ? def.text(prevReply) : def.text;
      console.log(`  turn ${i + 1}: lead="${text}"`);
      const res = await runTurn(phone, text, def.expect);
      if (res.turn) {
        for (const c of res.checks) console.log(`    ${c.ok ? 'PASS' : 'FAIL'}: ${c.label}${c.ok ? '' : ' -> ' + JSON.stringify(c.detail)}`);
        console.log(`    reply: ${JSON.stringify(res.turn.reply)}`);
        if (res.turn.notification && res.turn.notification.send) console.log(`    telegram: ${JSON.stringify(res.turn.notification.text)}`);
        prevReply = res.turn.reply || prevReply;
      } else {
        console.log(`    FAIL: no reply execution found (webhook HTTP ${res.webhookStatus})`);
      }
      turns.push(recordTurn(i, text, res));
    }
  }

  let finalizeChecks = [];
  if (scenario.finalize) {
    finalizeChecks = await scenario.finalize(phone, { turns }).catch((e) => [{ label: 'finalize', ok: false, detail: e.message }]);
    for (const c of finalizeChecks) console.log(`    ${c.ok ? 'PASS' : 'FAIL'}: ${c.label}${c.ok ? '' : ' -> ' + JSON.stringify(c.detail)}`);
  }

  console.log('  teardown...');
  await resetLead(phone).catch((e) => console.error('  teardown error:', e.message));
  if (scenario.teardown) await scenario.teardown().catch((e) => console.error('  teardown error:', e.message));

  const all = [...turns.flatMap((t) => t.checks), ...assessChecks, ...finalizeChecks];
  return {
    name: scenario.name,
    description: scenario.description,
    passed: all.filter((c) => c.ok).length,
    failed: all.filter((c) => !c.ok).length,
    turns,
    assessChecks,
    finalizeChecks,
  };
}

function saveResults(results, config) {
  fs.mkdirSync(RESULTS_DIR, { recursive: true });
  const ts = new Date().toISOString().replace(/[:.]/g, '-');
  const jsonPath = path.join(RESULTS_DIR, `${ts}.json`);
  const mdPath = path.join(RESULTS_DIR, `${ts}.md`);
  fs.writeFileSync(jsonPath, JSON.stringify({ config, results }, null, 2));

  const lines = [`# Beatriz full-workflow test — ${ts}`, '', `Config: \`${JSON.stringify(config)}\``, ''];
  for (const r of results) {
    lines.push(`## ${r.name} — ${r.passed} passed, ${r.failed} failed`, '', `_${r.description}_`, '');
    for (const t of r.turns) {
      lines.push(`### Turn ${t.index + 1} (attempts: ${t.attempts}, exec ${t.executionId || '—'})`);
      lines.push(`- **Lead:** ${t.leadText}`);
      lines.push(`- **Reply:** ${t.reply === null ? '_(no reply execution)_' : t.reply.replace(/\n/g, ' ')}`);
      if (t.notification && t.notification.send) lines.push(`- **Telegram:** ${(t.notification.text || '').replace(/\n/g, ' | ')}  _(sent: ${t.telegramSent})_`);
      if (t.loadOffer && (t.loadOffer.promo_active || t.loadOffer.open_quote_valid)) {
        lines.push(`- **Offer context:** \`${JSON.stringify(t.loadOffer)}\``);
      }
      for (const c of t.checks) lines.push(`- ${c.ok ? '✅' : '❌'} ${c.label}${c.ok ? '' : ` — \`${JSON.stringify(c.detail)}\``}`);
      lines.push('');
    }
    for (const c of r.assessChecks) lines.push(`- ${c.ok ? '✅' : '❌'} ${c.label}${c.ok ? '' : ` — \`${JSON.stringify(c.detail)}\``}`);
    for (const c of r.finalizeChecks) lines.push(`- ${c.ok ? '✅' : '❌'} ${c.label}${c.ok ? '' : ` — \`${JSON.stringify(c.detail)}\``}`);
    lines.push('');
  }
  fs.writeFileSync(mdPath, lines.join('\n'));
  return { jsonPath, mdPath };
}

async function main() {
  if (!apiKey()) { console.error('No N8N_API_KEY (or ~/.n8n-api-key). Aborting.'); return 1; }
  if (ONLY.length) {
    const unknown = ONLY.filter((n) => !SCENARIOS.some((s) => s.name === n));
    if (unknown.length) {
      console.error(`unknown scenario(s): ${unknown.join(', ')}. Known: ${SCENARIOS.map((s) => s.name).join(', ')}`);
      return 1;
    }
  }
  const selected = ONLY.length ? SCENARIOS.filter((s) => ONLY.includes(s.name)) : SCENARIOS;

  console.log(`full-workflow test: webhook=${WEBHOOK} workflow=${WORKFLOW_ID} slug=${SLUG}`);
  console.log('ensuring isolated test artist (no connected WhatsApp session)...');
  await ensureTestArtist();
  const artist = await artistPrecheck();
  console.log(`artist precheck: ${JSON.stringify(artist)}`);
  if (!artist) { console.error(`No artist with slug ${SLUG}. Aborting.`); return 1; }
  if (artist.ai_active_hours) console.warn('WARNING: artist has ai_active_hours set; messages may be dropped outside the window.');
  console.log(`isolation: outbound WhatsApp sends for "${SLUG}" have no connected session (nothing is delivered to real people).`);

  // Promotions are deployed separately from this test. Fail loudly and early
  // with the exact missing piece instead of driving a broken workflow.
  if (selected.some((s) => s.name === 'promotion-bogo')) {
    const issues = await promotionPreflight();
    if (issues.length) {
      console.error('\npromotion-bogo preflight failed:');
      for (const issue of issues) console.error(`  - ${issue}`);
      console.error('\nNothing was sent. Deploy the two items above, then re-run with --only=promotion-bogo.\n');
      return 2;
    }
    console.log('promotion preflight: offers schema + promotion nodes are live.');
  }

  // Never let a stale test promotion leak into the other scenarios (it would
  // change every quoted price).
  await clearTestPromotion().catch((e) => console.warn('promo pre-clean:', e.message));

  const phone = `5511900000${String(Math.floor(Math.random() * 900) + 100)}`;
  console.log(`fake lead phone: ${phone}  retries=${RETRIES} timeout=${TIMEOUT_MS}ms`);
  if (ONLY.length) console.log(`scenarios: ${selected.map((s) => s.name).join(', ')}`);

  const results = [];
  for (const scenario of selected) {
    try {
      results.push(await runScenario(scenario, phone));
    } catch (e) {
      console.error(`scenario ${scenario.name} crashed: ${e.message}`);
      results.push({ name: scenario.name, description: scenario.description, passed: 0, failed: 1, turns: [], assessChecks: [{ label: `crashed: ${e.message}`, ok: false }], finalizeChecks: [] });
    }
  }

  await resetLead(phone).catch(() => {});
  await clearTestPromotion().catch((e) => console.warn('promo post-clean:', e.message));

  const { jsonPath, mdPath } = saveResults(results, { webhook: WEBHOOK, workflowId: WORKFLOW_ID, slug: SLUG, phone, retries: RETRIES, when: new Date().toISOString() });
  const passed = results.reduce((s, r) => s + r.passed, 0);
  const failed = results.reduce((s, r) => s + r.failed, 0);
  console.log(`\n=== full-workflow: ${passed} passed, ${failed} failed across ${results.length} scenarios ===`);
  console.log(`saved: ${mdPath}`);
  console.log(`       ${jsonPath}`);
  return failed === 0 ? 0 : 1;
}

main().then((c) => process.exit(c)).catch((e) => { console.error(e); process.exit(1); });
