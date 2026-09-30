'use strict';
/**
 * Shared helpers for integration tests that talk to the real n8n + Postgres.
 * Short-lived workflows are created to run a single SQL statement against the
 * `main-db` credential, then deleted. No arbitrary SQL endpoint is exposed.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');

const N8N_BASE = process.env.N8N_BASE || 'https://n8n.vaif.com.br';
const N8N_API = `${N8N_BASE}/api/v1`;
const PG_CRED = { postgres: { id: 'nngaQDfXHYQ1Q43P', name: 'main-db' } };

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
  const base = { name: `TEMP it ${Date.now()}-${Math.floor(Math.random() * 1e6)}`, nodes, connections, settings: { executionOrder: 'v1' } };
  let id;
  try {
    id = (await n8n('POST', '/workflows', base)).id;
    await n8n('PUT', `/workflows/${id}`, { name: base.name, nodes, connections, settings: base.settings });
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

async function postJson(url, body) {
  const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  const text = await res.text();
  let json = null;
  try { json = JSON.parse(text); } catch (e) { json = { raw: text }; }
  return { status: res.status, json, text };
}

async function getExecution(id) {
  return n8n('GET', `/executions/${id}?includeData=true`);
}

async function listExecutions(workflowId, limit = 30) {
  const r = await n8n('GET', `/executions?workflowId=${workflowId}&limit=${limit}`);
  return r.data || [];
}

module.exports = { N8N_BASE, N8N_API, apiKey, n8n, withTempWorkflow, runSql, postJson, getExecution, listExecutions };
