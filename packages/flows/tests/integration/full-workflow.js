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
 */
const fs = require('fs');
const path = require('path');
const { N8N_BASE, apiKey, runSql, postJson, getExecution, listExecutions } = require('./lib/stack');

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

const TABLE_PRICE = 150000;   // seeded R$ 1.500
const DEPOSIT_PCT = 30;       // Bruno's sinal
const EXPECTED_DEPOSIT_CENTS = Math.round(TABLE_PRICE * DEPOSIT_PCT / 100); // 45000
const EXPECTED_DEPOSIT_TEXT = 'R$ ' + (EXPECTED_DEPOSIT_CENTS / 100).toFixed(2).replace('.', ',');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const phoneFilter = (p) => `telefone = '${p}'`;
const has = (s, re) => typeof s === 'string' && re.test(s);

// ── SQL helpers ──
async function resetLead(phone) {
  // FK-safe order: child rows first (one statement; children don't reference
  // each other), then leads, then the debounce buffer.
  await runSql(`
    WITH del_cm AS (DELETE FROM chat_memory WHERE session_id IN (SELECT id::text FROM leads WHERE ${phoneFilter(phone)}) RETURNING 1),
         del_ev AS (DELETE FROM events WHERE lead_id IN (SELECT id FROM leads WHERE ${phoneFilter(phone)}) RETURNING 1),
         del_ob AS (DELETE FROM notion_sync_outbox WHERE lead_id IN (SELECT id FROM leads WHERE ${phoneFilter(phone)}) RETURNING 1),
         del_cal AS (DELETE FROM calendar WHERE lead_id IN (SELECT id FROM leads WHERE ${phoneFilter(phone)}) RETURNING 1)
    SELECT 'ok' AS reset;`);
  await runSql(`DELETE FROM leads WHERE ${phoneFilter(phone)};`);
  await runSql(`DELETE FROM message_buffer WHERE chat_id = '${SLUG}:${phone}';`);
}

async function seedNearDeposit(phone) {
  await resetLead(phone);
  const row = await runSql(`
    INSERT INTO leads (artist_id, nome, telefone, placement, body_zone, pipeline_status, processo_explicado, preco_liberado, table_price, negotiated_price)
    SELECT id, 'Teste Full Workflow', '${phone}', 'braco_externo', 'fechamento', 'orcamento_enviado', true, true, ${TABLE_PRICE}, ${TABLE_PRICE}
    FROM artists WHERE wa_session_slug = '${SLUG}'
    RETURNING id::text AS lead_id;`);
  return row.lead_id;
}

async function leadState(phone) {
  const row = await runSql(`
    SELECT jsonb_build_object(
      'pipeline_status', pipeline_status, 'deposit_status', deposit_status,
      'deposit_amount', deposit_amount, 'booked_date', booked_date::text,
      'preco_liberado', preco_liberado, 'table_price', table_price, 'negotiated_price', negotiated_price
    )::text AS state
    FROM leads WHERE ${phoneFilter(phone)} ORDER BY created_at DESC LIMIT 1;`);
  return row.state ? JSON.parse(row.state) : null;
}

async function bookedIsRealSlot(phone) {
  // Validate the booked instant against the artist's working hours (the same
  // source check_availability derives from). We cannot use check_availability
  // here: the slot is now booked, so it is correctly no longer "available".
  const row = await runSql(`
    WITH x AS (
      SELECT l.booked_date,
             a.working_hours,
             to_char(l.booked_date AT TIME ZONE COALESCE(NULLIF(a.timezone, ''), 'UTC'), 'HH24:MI') AS hm,
             (ARRAY['seg','ter','qua','qui','sex','sab','dom'])[
               EXTRACT(ISODOW FROM (l.booked_date AT TIME ZONE COALESCE(NULLIF(a.timezone, ''), 'UTC')))::int
             ] AS dow
      FROM leads l
      JOIN artists a ON a.id = l.artist_id
      WHERE ${phoneFilter(phone)} AND l.booked_date IS NOT NULL
    )
    SELECT
      (SELECT booked_date::text FROM x LIMIT 1) AS booked,
      COALESCE(bool_or(
        x.hm ~ '^[0-2][0-9]:00$'
        AND x.working_hours IS NOT NULL
        AND EXISTS (
          SELECT 1 FROM jsonb_array_elements_text(x.working_hours -> x.dow) b
          WHERE split_part(b, '-', 1) <= x.hm AND x.hm < split_part(b, '-', 2)
        )
      ), false)::text AS ok
    FROM x;`);
  return row;
}

async function artistPrecheck() {
  const row = await runSql(`SELECT jsonb_build_object('slug', wa_session_slug, 'status', status, 'ai_active_hours', ai_active_hours, 'telegram_group_id', telegram_group_id)::text AS a FROM artists WHERE wa_session_slug = '${SLUG}';`);
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
    INSERT INTO artists (id, display_name, nome, specialties, nao_faco, floor_pct, deposit_type, deposit_value, pix_key, instagram_handle, working_hours, ai_active_hours, timezone, wa_session_slug, status, telegram_group_id)
    VALUES ('a0000000-0000-4000-8000-000000000001', 'SDR Test', 'SDR Test', ARRAY['realismo', 'blackwork'], ARRAY[]::text[], 80.00, 'percent', 30,
      'sdr.test@pix.example', '@sdr_test',
      '{"seg":["09:00-12:00","14:00-18:00"],"ter":["09:00-12:00","14:00-18:00"],"qua":["09:00-12:00","14:00-18:00"],"qui":["09:00-12:00","14:00-18:00"],"sex":["09:00-12:00","14:00-18:00"],"sab":["09:00-13:00"]}'::jsonb,
      NULL, 'America/Sao_Paulo', '${SLUG}', 'live', NULLIF('${TEST_GROUP}', ''))
    ON CONFLICT (id) DO UPDATE SET
      wa_session_slug = EXCLUDED.wa_session_slug,
      status = 'live',
      nome = EXCLUDED.nome,
      display_name = EXCLUDED.display_name,
      floor_pct = EXCLUDED.floor_pct,
      deposit_type = EXCLUDED.deposit_type,
      deposit_value = EXCLUDED.deposit_value,
      pix_key = EXCLUDED.pix_key,
      instagram_handle = EXCLUDED.instagram_handle,
      working_hours = EXCLUDED.working_hours,
      timezone = EXCLUDED.timezone,
      telegram_group_id = EXCLUDED.telegram_group_id;`);
  await runSql(`
    INSERT INTO pricing (artist_id, placement, body_zone, table_price, session_duration_min, buffer_min)
    SELECT 'a0000000-0000-4000-8000-000000000001', v.placement, v.zone, v.price, 120, 30
    FROM (VALUES
      ('antebraco','pequeno',30000),('antebraco','medio',60000),('antebraco','grande',90000),('antebraco','fechamento',120000),
      ('braco_externo','pequeno',30000),('braco_externo','medio',60000),('braco_externo','grande',90000),('braco_externo','fechamento',150000),
      ('costas','pequeno',35000),('costas','medio',70000),('costas','grande',120000),('costas','fechamento',200000)
    ) AS v(placement, zone, price)
    ON CONFLICT (artist_id, placement, body_zone) DO UPDATE SET
      table_price = EXCLUDED.table_price, session_duration_min = EXCLUDED.session_duration_min, buffer_min = EXCLUDED.buffer_min;`);
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
    pipeline: pc.pipeline_status || null,
    depositStatus: pc.deposit_status_val || null,
    gateViolation: pc.price_gate_violation === true,
    notification: notif,
    telegramSent: tg ? !tg.error : false,
    availability: allItems(run, 'Check Availability'),
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
    availability: t ? t.availability : [],
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
  console.log(`full-workflow test: webhook=${WEBHOOK} workflow=${WORKFLOW_ID} slug=${SLUG}`);
  console.log('ensuring isolated test artist (no connected WhatsApp session)...');
  await ensureTestArtist();
  const artist = await artistPrecheck();
  console.log(`artist precheck: ${JSON.stringify(artist)}`);
  if (!artist) { console.error(`No artist with slug ${SLUG}. Aborting.`); return 1; }
  if (artist.ai_active_hours) console.warn('WARNING: artist has ai_active_hours set; messages may be dropped outside the window.');
  console.log(`isolation: outbound WhatsApp sends for "${SLUG}" have no connected session (nothing is delivered to real people).`);

  const phone = `5511900000${String(Math.floor(Math.random() * 900) + 100)}`;
  console.log(`fake lead phone: ${phone}  retries=${RETRIES} timeout=${TIMEOUT_MS}ms`);

  const results = [];
  for (const scenario of SCENARIOS) {
    try {
      results.push(await runScenario(scenario, phone));
    } catch (e) {
      console.error(`scenario ${scenario.name} crashed: ${e.message}`);
      results.push({ name: scenario.name, description: scenario.description, passed: 0, failed: 1, turns: [], assessChecks: [{ label: `crashed: ${e.message}`, ok: false }], finalizeChecks: [] });
    }
  }

  await resetLead(phone).catch(() => {});

  const { jsonPath, mdPath } = saveResults(results, { webhook: WEBHOOK, workflowId: WORKFLOW_ID, slug: SLUG, phone, retries: RETRIES, when: new Date().toISOString() });
  const passed = results.reduce((s, r) => s + r.passed, 0);
  const failed = results.reduce((s, r) => s + r.failed, 0);
  console.log(`\n=== full-workflow: ${passed} passed, ${failed} failed across ${results.length} scenarios ===`);
  console.log(`saved: ${mdPath}`);
  console.log(`       ${jsonPath}`);
  return failed === 0 ? 0 : 1;
}

main().then((c) => process.exit(c)).catch((e) => { console.error(e); process.exit(1); });
