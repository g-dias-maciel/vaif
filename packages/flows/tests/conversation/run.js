#!/usr/bin/env node
'use strict';
/**
 * Beatriz conversation test runner.
 *
 *   node packages/flows/tests/conversation/run.js            # deterministic (mock) suite
 *   node packages/flows/tests/conversation/run.js --list     # list scenarios
 *   node packages/flows/tests/conversation/run.js --scenario negotiation
 *   node packages/flows/tests/conversation/run.js --live     # real model eval (needs API key)
 *   node packages/flows/tests/conversation/run.js --live --runs 3
 *   node packages/flows/tests/conversation/run.js --dry      # show eval config, no calls
 *
 * Deterministic mode is offline: no network, no DB, no n8n, no WAHA.
 */
const fs = require('fs');
const path = require('path');
const { loadWorkflow } = require('./lib/workflow');
const { runScenario, summarize } = require('./lib/engine');
const { runUnits } = require('./lib/units');
const { runNotificationTests } = require('./lib/notifications');

const SCENARIOS_PATH = path.join(__dirname, 'scenarios.json');

function parseArgs(argv) {
  const args = { live: false, dry: false, list: false, scenario: null, runs: Number(process.env.BEATRIZ_EVAL_RUNS || 1) };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--live') args.live = true;
    else if (a === '--dry') args.dry = true;
    else if (a === '--list') args.list = true;
    else if (a === '--scenario') args.scenario = argv[++i];
    else if (a === '--runs') args.runs = Number(argv[++i]);
  }
  return args;
}

function loadScenarios() {
  const data = JSON.parse(fs.readFileSync(SCENARIOS_PATH, 'utf8'));
  return data.scenarios;
}

function printResult(result) {
  const { scenario, turns } = result;
  const { passed, failed } = summarize(result);
  const tag = failed === 0 ? 'PASS' : 'FAIL';
  console.log(`\n[${tag}] ${scenario.name}  (${passed} passed, ${failed} failed)`);
  for (const t of turns) {
    const bad = t.checks.filter((c) => !c.ok);
    console.log(`  · turn ${t.index + 1} | lead: ${JSON.stringify(t.lead)}`);
    console.log(`    reply: ${JSON.stringify(t.finalReply)}`);
    if (t.parsed.price_gate_violation) console.log('    (reply rewritten by the price gate)');
    for (const c of bad) console.log(`    ✗ ${c.label}${c.detail ? ' -> ' + c.detail : ''}`);
  }
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const scenarios = loadScenarios();
  const selected = args.scenario
    ? scenarios.filter((s) => s.name.includes(args.scenario))
    : scenarios;

  if (args.list) {
    for (const s of scenarios) console.log(`- ${s.name}: ${s.description || ''}`);
    return 0;
  }
  if (!selected.length) {
    console.error(`no scenarios matched ${JSON.stringify(args.scenario)}`);
    return 1;
  }

  const { nodes } = loadWorkflow();

  if (args.live || args.dry) {
    const { resolveConfig, createModel, workflowModel } = require('./lib/live-model');
    const cfg = resolveConfig(nodes);
    const wfModel = workflowModel(nodes) || '(none)';
    console.log(`live eval: model=${cfg.model} base=${cfg.baseUrl} key=${cfg.available ? 'present' : 'MISSING'}`);
    console.log(`workflow model: ${wfModel} — parity: ${cfg.model === wfModel ? 'exact' : 'OVERRIDDEN by BEATRIZ_EVAL_MODEL'}`);
    if (args.dry || !cfg.available) {
      if (!args.dry) { console.error('\nNo API key. Set BEATRIZ_EVAL_API_KEY (or OPENROUTER_API_KEY / OPENAI_API_KEY).'); return 1; }
      console.log('dry run — no model calls made.');
      return 0;
    }
    let allPassed = 0, allFailed = 0;
    for (const scenario of selected) {
      const model = createModel(nodes, { artist: scenario.artist || {}, pricing: scenario.pricing || [] });
      for (let run = 0; run < args.runs; run++) {
        const result = await runScenario(nodes, scenario, model);
        printResult(result);
        const s = summarize(result);
        allPassed += s.passed; allFailed += s.failed;
      }
    }
    console.log(`\n=== live: ${allPassed} passed, ${allFailed} failed (${args.runs} run(s)) ===`);
    return allFailed === 0 ? 0 : 1;
  }

  // Deterministic (mock) mode
  let totalPassed = 0, totalFailed = 0, scenariosFailed = 0;

  console.log('\n=== workflow units ===');
  const unitChecks = runUnits(nodes);
  for (const c of unitChecks) {
    if (c.ok) totalPassed++;
    else { totalFailed++; console.log(`  ✗ ${c.label}${c.detail ? ' -> ' + c.detail : ''}`); }
  }
  console.log(`  units: ${unitChecks.filter((c) => c.ok).length}/${unitChecks.length} passed`);

  console.log('\n=== notifications (handoff / signal → Telegram) ===');
  const notifChecks = runNotificationTests(nodes);
  for (const c of notifChecks) {
    if (c.ok) totalPassed++;
    else { totalFailed++; console.log(`  ✗ ${c.label}${c.detail ? ' -> ' + c.detail : ''}`); }
  }
  console.log(`  notifications: ${notifChecks.filter((c) => c.ok).length}/${notifChecks.length} passed`);

  for (const scenario of selected) {
    const result = await runScenario(nodes, scenario, null);
    printResult(result);
    const s = summarize(result);
    totalPassed += s.passed; totalFailed += s.failed;
    if (s.failed) scenariosFailed++;
  }
  console.log(`\n=== deterministic: ${totalPassed} passed, ${totalFailed} failed, ${scenariosFailed}/${selected.length} scenarios with failures ===`);
  return totalFailed === 0 ? 0 : 1;
}

main().then((code) => process.exit(code)).catch((e) => { console.error(e); process.exit(1); });
