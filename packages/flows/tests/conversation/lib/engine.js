'use strict';
/**
 * Multi-turn conversation simulator.
 *
 * Each turn runs the production "Parse Classification" node against a model
 * reply + a classifier result, then folds the emitted state into the lead
 * (mirroring what "Update Lead" persists) so the next turn sees it.
 *
 * The model reply source is pluggable:
 *   - mock mode: the scenario supplies `turn.model` (a canned assistant reply)
 *   - live mode: a function calls the real model (see live-model.js)
 */
const { runParse } = require('./workflow');
const { checkGlobals } = require('./invariants');

const BASE_LEAD = {
  id: 'lead-0000',
  pipeline_status: 'novo',
  processo_explicado: false,
  preco_liberado: false,
  deposit_status: 'nao_solicitado',
};

function applyTurnState(lead, parsed) {
  const keep = (next, prev) => (next !== undefined && next !== null ? next : prev);
  return {
    ...lead,
    pipeline_status: parsed.pipeline_status || lead.pipeline_status,
    processo_explicado: lead.processo_explicado === true || parsed.processo_explicado_val === true,
    preco_liberado: lead.preco_liberado === true || parsed.preco_liberado_val === true,
    table_price: keep(parsed.table_price_cents, lead.table_price),
    negotiated_price: keep(parsed.negotiated_price_cents, lead.negotiated_price),
    deposit_status: keep(parsed.deposit_status_val, lead.deposit_status),
    deposit_amount: keep(parsed.deposit_amount_cents, lead.deposit_amount),
    booked_date: keep(parsed.booked_date_val, lead.booked_date),
    nome: keep(parsed.nome_val, lead.nome),
  };
}

function checkExpectations(expect, ctx) {
  const checks = [];
  if (!expect) return checks;
  const { finalReply, parsed } = ctx;

  for (const s of expect.finalReplyIncludes || []) {
    checks.push({ ok: finalReply.includes(s), label: `reply includes "${s}"`, detail: finalReply });
  }
  for (const s of expect.finalReplyExcludes || []) {
    checks.push({ ok: !finalReply.includes(s), label: `reply excludes "${s}"`, detail: finalReply });
  }
  for (const re of expect.finalReplyMatches || []) {
    const rx = re instanceof RegExp ? re : new RegExp(re, 'i');
    checks.push({ ok: rx.test(finalReply), label: `reply matches ${rx}`, detail: finalReply });
  }
  if (expect.gateViolation !== undefined) {
    checks.push({ ok: parsed.price_gate_violation === expect.gateViolation, label: `gate violation === ${expect.gateViolation}`, detail: String(parsed.price_gate_violation) });
  }
  if (expect.handoff !== undefined) {
    const isHandoff = parsed.pipeline_status === 'aguardando_artista' || !!parsed.handoff_reason;
    checks.push({ ok: isHandoff === expect.handoff, label: `handoff === ${expect.handoff}`, detail: parsed.pipeline_status });
  }
  return checks;
}

function checkStateExpectations(state, lead) {
  const checks = [];
  for (const [k, v] of Object.entries(state || {})) {
    checks.push({ ok: lead[k] === v, label: `state ${k} === ${JSON.stringify(v)}`, detail: JSON.stringify(lead[k]) });
  }
  return checks;
}

/**
 * Run one scenario.
 * @param {object} nodes  from loadWorkflow()
 * @param {object} scenario
 * @param {(ctx)=>Promise<string>|string} [modelFn]  live model; omit for mock
 */
async function runScenario(nodes, scenario, modelFn) {
  let lead = { ...BASE_LEAD, ...(scenario.initial || {}) };
  const turns = [];

  for (let i = 0; i < scenario.turns.length; i++) {
    const turn = scenario.turns[i];
    const stateBefore = { ...lead };

    const agentOutput = modelFn
      ? undefined
      : (turn.model || '');

    let finalAgentOutput = agentOutput;
    let classifier = turn.classify || {};
    if (modelFn) {
      const produced = await modelFn({ scenario, turn, index: i, lead: stateBefore });
      finalAgentOutput = produced.agentOutput;
      classifier = produced.classifier || turn.classify || {};
    }

    const parsed = runParse(nodes, {
      lead: stateBefore,
      agentOutput: finalAgentOutput,
      classifier,
      combinedText: turn.lead,
    });
    const finalReply = parsed.final_reply || '';

    const checks = [
      ...checkGlobals({ turn, stateBefore, parsed, finalReply }),
      ...checkExpectations(turn.expect, { turn, stateBefore, parsed, finalReply }),
    ];

    const nextLead = applyTurnState(stateBefore, parsed);
    checks.push(...checkStateExpectations((turn.expect || {}).state, nextLead));

    // Discount floor: a persisted negotiated price must respect the artist's
    // floor (e.g. floor_pct 80 means never below 80% of the table price).
    if (scenario.floor_pct != null && nextLead.negotiated_price != null && nextLead.table_price != null) {
      const min = Math.ceil(nextLead.table_price * scenario.floor_pct / 100);
      checks.push({
        ok: nextLead.negotiated_price >= min,
        label: `negotiated price ≥ floor (${scenario.floor_pct}% = ${min}c)`,
        detail: `${nextLead.negotiated_price}c`,
      });
    }

    turns.push({ index: i, lead: turn.lead, agentOutput: finalAgentOutput, finalReply, parsed, checks });
    lead = nextLead;
  }

  return { scenario, turns, finalLead: lead };
}

function summarize(result) {
  let passed = 0;
  let failed = 0;
  for (const t of result.turns) {
    for (const c of t.checks) (c.ok ? passed++ : failed++);
  }
  return { passed, failed };
}

module.exports = { runScenario, applyTurnState, summarize, BASE_LEAD };
