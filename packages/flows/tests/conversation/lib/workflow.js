'use strict';
/**
 * Loads the generated Beatriz WhatsApp workflow and exposes small runners for
 * its inline JavaScript nodes, evaluated with mocked n8n globals ($, $input).
 *
 * Tests use this so they exercise the *deployed* logic (the JS embedded in
 * beatriz-whatsapp-agent.json) rather than a re-implementation.
 */
const fs = require('fs');
const path = require('path');

const FLOWS_ROOT = path.join(__dirname, '..', '..', '..');
const DEFS = path.join(FLOWS_ROOT, 'definitions');
const WF_PATH = path.join(DEFS, 'beatriz-whatsapp-agent.json');

function loadWorkflow(file = WF_PATH) {
  const wf = JSON.parse(fs.readFileSync(file, 'utf8'));
  const nodes = Object.fromEntries(wf.nodes.map((n) => [n.name, n]));
  return { wf, nodes };
}

function nodeJs(nodes, name) {
  const n = nodes[name];
  if (!n) throw new Error(`workflow node not found: ${name}`);
  return n.parameters.jsCode;
}

/** Build a fake `$` accessor over a map of node-name -> json. */
function makeDollar(refs) {
  return (name) => ({
    first: () => ({ json: refs[name] !== undefined ? refs[name] : {} }),
    all: () => [],
  });
}

/** Evaluate a workflow Code node's jsCode with mocked $ and $input. */
function evalNode(jsCode, { refs = {}, input = {} } = {}) {
  const fn = new Function('$', '$input', jsCode);
  const out = fn(makeDollar(refs), { first: () => ({ json: input }) });
  if (!Array.isArray(out) || !out[0]) throw new Error('code node returned no items');
  return out[0].json;
}

/** Wrap a raw classifier object the way the OpenRouter node would return it. */
function openRouterInput(classifier) {
  return { choices: [{ message: { content: JSON.stringify(classifier) } }] };
}

/**
 * Run the production "Parse Classification" node.
 * Returns the json it emits (final_reply, pipeline_status, gate flags, ...).
 */
function runParse(nodes, { lead, agentOutput, classifier, combinedText } = {}) {
  return evalNode(nodeJs(nodes, 'Parse Classification'), {
    refs: {
      'Upsert Lead': lead || {},
      'AI Agent': { output: agentOutput || '' },
      'Debounce Resolve': { combined_text: combinedText || 'quero uma tatuagem nova' },
    },
    input: openRouterInput(classifier || {}),
  });
}

/**
 * Run the production "Debounce Resolve" node.
 * state = row from message_buffer; debounceStart = { msg_ts, chat_id }.
 */
function runDebounceResolve(nodes, { state, msgTs, chatId, now }) {
  const RealDate = Date;
  if (typeof now === 'number') {
    global.Date = class extends RealDate {
      constructor(...a) { return a.length ? new RealDate(...a) : new RealDate(now); }
      static now() { return now; }
    };
  }
  try {
    return evalNode(nodeJs(nodes, 'Debounce Resolve'), {
      refs: {
        'Get Buffer State': state || {},
        'Debounce Start': { msg_ts: msgTs, chat_id: chatId || 'default:5511999999999' },
      },
    });
  } finally {
    global.Date = RealDate;
  }
}

/** Run the production "Build Humanize Delay" node given the final reply. */
function runHumanizeDelay(nodes, { finalReply, agentOutput }) {
  return evalNode(nodeJs(nodes, 'Build Humanize Delay'), {
    refs: {
      'Parse Classification': { final_reply: finalReply },
      'AI Agent': { output: agentOutput || '' },
    },
  });
}

/** Run the production "Check AI Window" node. */
function runCheckAiWindow(nodes, { waBody, artist }) {
  return evalNode(nodeJs(nodes, 'Check AI Window'), {
    refs: { 'WAHA Webhook': { body: waBody || {} }, 'Resolve Artist': artist || {} },
  });
}

/**
 * Run the production "Build Handoff Message" node — the Telegram ops
 * notification for handoffs and deposit (signal) requests.
 * Returns { send, chatId, text, btn1_*, btn2_* } or { send: false }.
 */
function runHandoffMessage(nodes, { parse, update, lead, artist, agentOutput } = {}) {
  return evalNode(nodeJs(nodes, 'Build Handoff Message'), {
    refs: {
      'Parse Classification': parse || {},
      'Update Lead': update || {},
      'Upsert Lead': lead || {},
      'Resolve Artist': artist || {},
      'AI Agent': { output: agentOutput || '' },
    },
  });
}

/** Render the system prompt template the same way "Build System Prompt" does. */
function renderSystemPrompt(nodes, artist, pricing) {
  const js = nodeJs(nodes, 'Build System Prompt');
  // Build System Prompt reads $('Resolve Artist') and $input.all() (pricing).
  const fn = new Function('$', '$input', js);
  const $ = (name) => ({ first: () => ({ json: artist }) });
  const input = { all: () => (pricing || []).map((p) => ({ json: p })) };
  return fn($, input)[0].json.system_message;
}

function readPrompt(name) {
  return fs.readFileSync(path.join(FLOWS_ROOT, 'prompts', name), 'utf8');
}

module.exports = {
  FLOWS_ROOT,
  DEFS,
  WF_PATH,
  loadWorkflow,
  nodeJs,
  evalNode,
  runParse,
  runDebounceResolve,
  runHumanizeDelay,
  runCheckAiWindow,
  runHandoffMessage,
  renderSystemPrompt,
  readPrompt,
};
