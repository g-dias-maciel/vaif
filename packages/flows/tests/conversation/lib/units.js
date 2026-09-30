'use strict';
/**
 * Unit checks for the deterministic workflow nodes that don't involve the
 * model: the typing-aware debounce, the humanized delay, and the AI window
 * gate. Run against the generated workflow's inline JS.
 */
const { runDebounceResolve, runHumanizeDelay, runCheckAiWindow } = require('./workflow');

const T = 1_700_000_000_000;

function runUnits(nodes) {
  const checks = [];
  const check = (label, ok, detail) => checks.push({ ok: !!ok, label, detail: detail === undefined ? '' : String(detail) });

  // ── Debounce Resolve ──
  let d = runDebounceResolve(nodes, {
    state: { last_msg_at: new Date(T).toISOString(), last_typing_at: null, typing_state: null, pending: 'oi' },
    msgTs: T, chatId: 'default:5511999999999', now: T + 5000,
  });
  check('debounce: quiet + no typing → process', d.decision === 'process', d.decision);
  check('debounce: combined text preserved', d.combined_text === 'oi', d.combined_text);

  d = runDebounceResolve(nodes, {
    state: { last_msg_at: new Date(T + 1000).toISOString(), pending: 'a\nb' },
    msgTs: T, chatId: 'default:1', now: T + 6000,
  });
  check('debounce: a newer message makes this execution stale → stop', d.decision === 'stop', d.decision);

  d = runDebounceResolve(nodes, {
    state: { last_msg_at: new Date(T).toISOString(), last_typing_at: new Date(T + 4000).toISOString(), typing_state: 'typing', pending: 'a' },
    msgTs: T, chatId: 'default:1', now: T + 5000,
  });
  check('debounce: active typing → wait', d.decision === 'wait', d.decision);

  d = runDebounceResolve(nodes, {
    state: { last_msg_at: new Date(T).toISOString(), last_typing_at: null, typing_state: null, pending: 'a' },
    msgTs: T, chatId: 'default:1', now: T + 1000,
  });
  check('debounce: recent activity (not quiet yet) → wait', d.decision === 'wait', d.decision);

  d = runDebounceResolve(nodes, {
    state: { last_msg_at: new Date(T).toISOString(), last_typing_at: new Date(T - 40000).toISOString(), typing_state: 'typing', pending: 'a' },
    msgTs: T, chatId: 'default:1', now: T + 5000,
  });
  check('debounce: stale typing past the grace cap → process', d.decision === 'process', d.decision);

  // ── Humanize delay ──
  let h = runHumanizeDelay(nodes, { agentOutput: 'Oi!' });
  check('humanize: delay has a floor ≥ 1.5s', h.delay_seconds >= 1.5, h.delay_seconds);
  h = runHumanizeDelay(nodes, { agentOutput: 'palavra '.repeat(120) });
  check('humanize: delay has a ceiling ≤ 7s', h.delay_seconds <= 7, h.delay_seconds);

  // ── Check AI Window ──
  let w = runCheckAiWindow(nodes, { waBody: { payload: { fromMe: true } }, artist: {} });
  check('window: own outgoing message is ignored', w.in_window === false, w.in_window);
  w = runCheckAiWindow(nodes, { waBody: { payload: { fromMe: false } }, artist: { ai_active_hours: null } });
  check('window: no configured hours → always in window', w.in_window === true, w.in_window);

  return checks;
}

module.exports = { runUnits };
