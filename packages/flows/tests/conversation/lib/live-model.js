'use strict';
/**
 * Live model driver (OPT-IN).
 *
 * Runs the real chat model against the real Beatriz system prompt and the real
 * classifier prompt, with the agent's tools mocked in-process. It produces the
 * same { agentOutput, classifier } shape the mock scenarios use, so the engine
 * can drive multi-turn evaluations.
 *
 * Requires an OpenAI-compatible endpoint:
 *   BEATRIZ_EVAL_API_KEY   (or OPENROUTER_API_KEY / OPENAI_API_KEY)
 *   BEATRIZ_EVAL_BASE_URL  (default https://openrouter.ai/api/v1)
 *   BEATRIZ_EVAL_MODEL     (default openai/gpt-4o-mini)
 *
 * Nothing here touches WAHA, n8n or the database.
 */
const { renderSystemPrompt, readPrompt, runParse } = require('./workflow');

function workflowModel(nodes) {
  try {
    return (nodes && nodes['OpenRouter Chat Model'] && nodes['OpenRouter Chat Model'].parameters.model) || '';
  } catch (e) {
    return '';
  }
}

function resolveConfig(nodes) {
  const apiKey = process.env.BEATRIZ_EVAL_API_KEY
    || process.env.OPENROUTER_API_KEY
    || process.env.OPENAI_API_KEY
    || '';
  const baseUrl = process.env.BEATRIZ_EVAL_BASE_URL
    || (process.env.OPENAI_API_KEY && !process.env.OPENROUTER_API_KEY ? 'https://api.openai.com/v1' : 'https://openrouter.ai/api/v1');
  // Default to the exact model the deployed workflow uses, so the eval and
  // production can't silently diverge.
  const model = process.env.BEATRIZ_EVAL_MODEL || workflowModel(nodes) || 'openai/gpt-4o-mini';
  return { apiKey, baseUrl, model, workflowModel: workflowModel(nodes), available: !!apiKey };
}

function toolSchemas() {
  return [
    { type: 'function', function: { name: 'check_availability', description: 'Consulta horários livres no calendário.', parameters: { type: 'object', properties: { duration_min: { type: 'number' } }, required: ['duration_min'] } } },
    { type: 'function', function: { name: 'book_slot', description: 'Reserva um horário.', parameters: { type: 'object', properties: { lead_id: { type: 'string' }, start_at: { type: 'string' }, duration_min: { type: 'number' }, buffer_min: { type: 'number' } }, required: ['lead_id', 'start_at'] } } },
    { type: 'function', function: { name: 'write_quote', description: 'Registra o orçamento.', parameters: { type: 'object', properties: { lead_id: { type: 'string' }, table_price: { type: 'number' }, negotiated_price: { type: 'number' } }, required: ['lead_id', 'table_price'] } } },
    { type: 'function', function: { name: 'request_deposit', description: 'Solicita o sinal via PIX.', parameters: { type: 'object', properties: { lead_id: { type: 'string' }, amount: { type: 'number' } }, required: ['lead_id', 'amount'] } } },
  ];
}

/** Deterministic mock tool results — no external side effects. */
function runTool(name, args) {
  switch (name) {
    case 'check_availability':
      return { slots: ['2026-10-02T14:00:00-03:00', '2026-10-03T10:00:00-03:00', '2026-10-05T14:00:00-03:00'] };
    case 'book_slot':
      return { ok: true, booked: args.start_at };
    case 'write_quote':
      return { ok: true };
    case 'request_deposit':
      return { ok: true, amount: args.amount };
    default:
      return { ok: false, error: `unknown tool ${name}` };
  }
}

async function chat(cfg, body) {
  const res = await fetch(`${cfg.baseUrl}/chat/completions`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${cfg.apiKey}` },
    body: JSON.stringify({ model: cfg.model, ...body }),
  });
  if (!res.ok) throw new Error(`model HTTP ${res.status}: ${(await res.text()).slice(0, 300)}`);
  return res.json();
}

/** Call the agent until it stops requesting tools; return its text. */
async function runAgentTurn(cfg, { systemPrompt, history, leadMessage, leadId }) {
  const messages = [
    { role: 'system', content: systemPrompt },
    ...history,
    { role: 'user', content: leadMessage },
  ];
  const toolCalls = [];
  for (let step = 0; step < 6; step++) {
    const resp = await chat(cfg, { messages, tools: toolSchemas(), temperature: 0.2 });
    const msg = resp.choices[0].message;
    messages.push(msg);
    const calls = msg.tool_calls || [];
    if (!calls.length) return { content: msg.content || '', toolCalls };
    for (const call of calls) {
      const args = JSON.parse(call.function.arguments || '{}');
      if (!args.lead_id) args.lead_id = leadId;
      toolCalls.push({ name: call.function.name, args });
      messages.push({ role: 'tool', tool_call_id: call.id, content: JSON.stringify(runTool(call.function.name, args)) });
    }
  }
  return { content: '(max tool steps reached)', toolCalls };
}

async function classifyTurn(cfg, { classifyTemplate, pipeline, lead, assistant }) {
  const system = classifyTemplate.replace(/\{\{pipeline\}\}/g, pipeline);
  const resp = await chat(cfg, {
    messages: [
      { role: 'system', content: system },
      { role: 'user', content: `Lead: ${lead}\n\nBeatriz: ${assistant}` },
    ],
    temperature: 0,
  });
  const content = resp.choices[0].message.content || '{}';
  try { return JSON.parse(content); } catch { return {}; }
}

/**
 * Build a modelFn for engine.runScenario().
 * Returns { agentOutput, classifier } per turn.
 */
function createModel(nodes, { artist, pricing }) {
  const cfg = resolveConfig(nodes);
  if (!cfg.available) throw new Error('no API key: set BEATRIZ_EVAL_API_KEY (or OPENROUTER_API_KEY / OPENAI_API_KEY)');
  const systemPrompt = renderSystemPrompt(nodes, artist, pricing);
  const classifyTemplate = readPrompt('classify-conversation.md');
  const histories = {};

  return async ({ scenario, turn, index, lead }) => {
    const key = scenario.name;
    if (index === 0) histories[key] = [];
    const leadId = lead.id || 'lead-0000';
    const { content } = await runAgentTurn(cfg, {
      systemPrompt,
      history: histories[key],
      leadMessage: turn.lead,
      leadId,
    });
    const classifier = await classifyTurn(cfg, {
      classifyTemplate,
      pipeline: lead.pipeline_status || 'novo',
      lead: turn.lead,
      assistant: content,
    });
    histories[key].push({ role: 'user', content: turn.lead }, { role: 'assistant', content });
    return { agentOutput: content, classifier };
  };
}

module.exports = { resolveConfig, createModel, runTool, toolSchemas, workflowModel };
