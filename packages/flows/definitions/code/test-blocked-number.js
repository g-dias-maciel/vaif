#!/usr/bin/env node
/**
 * Behavioural test for the per-artist blocked-number filter.
 *
 * Run: node packages/flows/definitions/code/test-blocked-number.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const wf = JSON.parse(fs.readFileSync(
  path.join(__dirname, '..', 'beatriz-whatsapp-agent.json'), 'utf8'));
const nodes = Object.fromEntries(wf.nodes.map((n) => [n.name, n]));
const raw = nodes['Blocked Number?'].parameters.conditions.conditions[0].leftValue;

let failures = 0;
let passes = 0;

function check(label, cond, detail) {
  if (cond) {
    passes++;
    console.log('  PASS: ' + label);
  } else {
    failures++;
    console.log('  FAIL: ' + label + (detail ? ' -> ' + detail : ''));
  }
}

function run({ from, alt = '', fromMe = false, blocked = [] }) {
  const body = {
    payload: {
      from,
      fromMe,
      _data: { key: alt ? { remoteJidAlt: alt } : {} },
    },
  };
  const $ = (name) => ({
    first: () => ({
      json: name === 'WAHA Webhook'
        ? { body }
        : { blocked_numbers: blocked },
    }),
  });
  const expression = raw.replace(/^=\{\{/, '').replace(/\}\}$/, '');
  return new Function('$', 'return ' + expression)($);
}

check('blocks a matching phone',
  run({ from: '5511999990001@c.us', blocked: ['5511999990001'] }) === true);
check('normalizes punctuation in the list',
  run({ from: '5511999990001@c.us', blocked: ['+55 (11) 99999-0001'] }) === true);
check('resolves a LID to the real phone',
  run({ from: '123456789@lid', alt: '5511999990001@c.us', blocked: ['5511999990001'] }) === true);
check('does not block another number',
  run({ from: '5511888880000@c.us', blocked: ['5511999990001'] }) === false);
check('does not block the artist own outgoing message',
  run({ from: '5511999990001@c.us', fromMe: true, blocked: ['5511999990001'] }) === false);
check('empty list blocks nobody',
  run({ from: '5511999990001@c.us', blocked: [] }) === false);

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
