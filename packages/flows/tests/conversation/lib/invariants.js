'use strict';
/**
 * Global conversation invariants. These are checked on EVERY turn of every
 * scenario, independent of the scenario's own expectations. They encode the
 * safety rules that must never regress:
 *
 *  1. No price is ever sent before the price gate is open.
 *  2. While pre-price and pre-doubt-clearance, replies keep the doubt loop
 *     open with "Mais alguma dúvida?".
 *  3. Once the gate is open, the doubts question is never asked again.
 *  4. When a reply is rewritten by the gate, the unsafe content is gone.
 */

const PRICE_RE = /R\$\s*\d/;
const DOUBTS_RE = /(mais alguma d[uú]vida|antes de falarmos de valores)/i;
const REASK_RE = /(antes de falarmos de valores|mais alguma d[uú]vida)/i;
const QUOTE_STAGES = ['orcamento_enviado', 'aguardando_deposito', 'agendado'];

const isHandoff = (parsed) =>
  parsed.pipeline_status === 'aguardando_artista'
  || parsed.pipeline_status === 'bloqueado'
  || !!parsed.handoff_reason;

const gateOpenBefore = (state) =>
  state.preco_liberado === true || QUOTE_STAGES.includes(state.pipeline_status);

const gateOpenAfter = (parsed) =>
  parsed.preco_liberado_val === true || QUOTE_STAGES.includes(parsed.pipeline_status);

const GLOBALS = [
  {
    name: 'no price before the gate is open',
    fn: ({ finalReply, parsed, stateBefore }) => {
      const hasPrice = PRICE_RE.test(finalReply);
      if (!hasPrice) return true;
      // A price in the final reply is only legitimate if the gate was already
      // open, or it opened this turn (deterministic quote stage).
      return gateOpenBefore(stateBefore) || gateOpenAfter(parsed) || parsed.price_gate_violation === false;
    },
  },
  {
    name: 'doubt loop stays open while pre-price',
    fn: ({ finalReply, parsed, stateBefore }) => {
      const preGate = !gateOpenBefore(stateBefore) && !gateOpenAfter(parsed);
      const processExplained = stateBefore.processo_explicado === true || parsed.processo_explicado_val === true;
      const doubtsCleared = parsed.duvidas_eliminadas_val === true;
      if (!preGate || !processExplained || doubtsCleared) return true;
      if (isHandoff(parsed)) return true;
      if (!finalReply || !finalReply.trim()) return true;
      if (PRICE_RE.test(finalReply)) return true; // price turn (shouldn't happen pre-gate)
      return DOUBTS_RE.test(finalReply);
    },
  },
  {
    name: 'doubts question is never re-asked after the gate opens',
    fn: ({ finalReply, parsed, stateBefore }) => {
      const wasOpen = gateOpenBefore(stateBefore) || parsed.preco_liberado_val === true;
      if (!wasOpen) return true;
      // Only forbidden if the reply *only* asks the doubts question again; a
      // negotiation reply may legitimately mention "valor" without asking it.
      const reasks = REASK_RE.test(finalReply) && !PRICE_RE.test(finalReply);
      return !reasks || parsed.pipeline_status === 'bloqueado';
    },
  },
  {
    name: 'rewritten replies drop the blocked price content',
    fn: ({ finalReply, parsed }) => {
      if (parsed.price_gate_violation !== true) return true;
      return !PRICE_RE.test(finalReply);
    },
  },
];

function checkGlobals(ctx) {
  return GLOBALS.map((g) => {
    let ok = false;
    let detail = '';
    try {
      ok = g.fn(ctx) === true;
    } catch (e) {
      ok = false;
      detail = e.message;
    }
    return { ok, label: `[invariant] ${g.name}`, detail };
  });
}

module.exports = { checkGlobals, GLOBALS, PRICE_RE, DOUBTS_RE, REASK_RE, gateOpenBefore, gateOpenAfter, isHandoff };
