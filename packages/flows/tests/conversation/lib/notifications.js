'use strict';
/**
 * Tests for the Telegram ops notifications built by "Build Handoff Message":
 * handoff alerts and deposit (signal) requests. This is what confirms the
 * signal question reaches the artist's Telegram group.
 */
const { runHandoffMessage } = require('./workflow');

const GROUP = '-5195870017';
const ARTIST = { nome: 'Bruno', telegram_group_id: GROUP };

function runNotificationTests(nodes) {
  const checks = [];
  const check = (label, ok, detail) => checks.push({ ok: !!ok, label, detail: detail === undefined ? '' : String(detail) });

  // ── Handoff ──
  let n = runHandoffMessage(nodes, {
    parse: { event_type: 'handoff_triggered', handoff_reason: 'cover_up', lead_id: 'L1', final_reply: 'Vou te passar pro Bruno.' },
    update: { pipeline_status: 'aguardando_artista' },
    lead: { nome: 'Gabriel', telefone: '5511999998888' },
    artist: ARTIST,
  });
  check('handoff: notification sent', n.send === true, JSON.stringify(n));
  check('handoff: goes to the artist ops group', n.chatId === GROUP, n.chatId);
  check('handoff: message names the lead', typeof n.text === 'string' && n.text.includes('Gabriel'), n.text);
  check('handoff: message gives the reason', typeof n.text === 'string' && n.text.includes('Cover-up'), n.text);
  check('handoff: includes a WhatsApp link', typeof n.text === 'string' && n.text.includes('https://wa.me/5511999998888'), n.text);
  check('handoff: shows the lead phone number', typeof n.text === 'string' && n.text.includes('<b>Telefone:</b>') && n.text.includes('+55 11 99999-8888'), n.text);
  check('handoff: confirm/cancel callbacks', n.btn1_cb === 'handoff:confirm:L1' && n.btn2_cb === 'handoff:cancel:L1', n.btn1_cb + '/' + n.btn2_cb);

  // ── Deposit / signal ──
  n = runHandoffMessage(nodes, {
    parse: { event_type: 'deposit_requested', lead_id: 'L2', deposit_amount_cents: 45000, final_reply: 'O sinal é R$ 450 no PIX.' },
    update: { pipeline_status: 'aguardando_deposito' },
    lead: { nome: 'Ana', telefone: '5511888887777' },
    artist: ARTIST,
  });
  check('signal: notification sent', n.send === true, JSON.stringify(n));
  check('signal: goes to the ops group', n.chatId === GROUP, n.chatId);
  check('signal: message states the amount in BRL', typeof n.text === 'string' && n.text.includes('R$ 450,00'), n.text);
  check('signal: shows the lead phone number', typeof n.text === 'string' && n.text.includes('<b>Telefone:</b>') && n.text.includes('+55 11 88888-7777'), n.text);
  check('signal: includes a WhatsApp link', typeof n.text === 'string' && n.text.includes('https://wa.me/5511888887777'), n.text);
  check('signal: asks the artist to confirm receipt', typeof n.text === 'string' && /recebimento/i.test(n.text), n.text);
  check('signal: confirm callback', n.btn1_cb === 'deposit:confirm:L2', n.btn1_cb);

  // ── Fallback detection from the sent text (classifier missed the event) ──
  n = runHandoffMessage(nodes, {
    parse: { final_reply: 'Deixa eu te passar pro Bruno, ele avalia melhor.' },
    update: { pipeline_status: 'qualificando' },
    lead: { nome: 'Lucas', telefone: '5511777776666' },
    artist: ARTIST,
  });
  check('handoff: detected from reply text when event missing', n.send === true, JSON.stringify(n));

  // ── No event → no notification ──
  n = runHandoffMessage(nodes, {
    parse: { final_reply: 'Qual estilo você tem em mente?' },
    update: { pipeline_status: 'qualificando' },
    lead: { nome: 'Lucas', telefone: '5511777776666' },
    artist: ARTIST,
  });
  check('ordinary reply: no notification', n.send === false, JSON.stringify(n));

  // ── Artist without a group → skip (never notify the wrong chat) ──
  const origError = console.error;
  console.error = () => {};
  try {
    n = runHandoffMessage(nodes, {
      parse: { event_type: 'deposit_requested', lead_id: 'L3', deposit_amount_cents: 30000, final_reply: 'sinal...' },
      update: { pipeline_status: 'aguardando_deposito' },
      lead: { nome: 'Ana', telefone: '5511888887777' },
      artist: { nome: 'Bruno', telegram_group_id: '' },
    });
  } finally {
    console.error = origError;
  }
  check('artist without telegram group: notification skipped', n.send === false, JSON.stringify(n));

  return checks;
}

module.exports = { runNotificationTests };
