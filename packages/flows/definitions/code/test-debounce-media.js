#!/usr/bin/env node
/**
 * Behavioural test for inbound reference images across the debounce burst.
 *
 * The reply is produced by whichever execution wins the quiet-period race,
 * usually a later text message. The image URL therefore has to travel through
 * the text buffer; otherwise a photo sent with a caption (or just before a
 * follow-up text) is silently lost and the agent asks for it again.
 *
 * Run: node packages/flows/definitions/code/test-debounce-media.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const wf = JSON.parse(fs.readFileSync(
  path.join(__dirname, '..', 'beatriz-whatsapp-agent.json'), 'utf8'));
const nodes = Object.fromEntries(wf.nodes.map((n) => [n.name, n]));

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

function run(name, mock) {
  const code = nodes[name].parameters.jsCode;
  const $ = (node) => ({ first: () => ({ json: mock(node) || {} }) });
  return new Function('$', '$input', code)($, { all: () => [] })[0].json;
}

const IMAGE_URL = 'http://waha.local/api/files/teste/photo.jpeg';

function startMock(payload) {
  return (name) => (name === 'WAHA Webhook' ? { body: { session: 'teste', payload } } : {});
}

// ── 1. An image with a caption carries its URL into the buffer ──
console.log('=== Debounce Start (image + caption) ===');
const imageStart = run('Debounce Start', startMock({
  from: '5910596431872@lid',
  type: 'image',
  hasMedia: true,
  body: 'a aguia queria tipo essa',
  media: { url: IMAGE_URL, mimetype: 'image/jpeg' },
  _data: { message: { imageMessage: {} } },
}));
check('media_url is kept', imageStart.media_url === IMAGE_URL, imageStart.media_url);
check('buffer text carries the marker', imageStart.msg_text.includes('[[REF_FOTO:' + IMAGE_URL + ']]'), imageStart.msg_text);

// ── 2. A text-only message does not invent media ──
console.log('=== Debounce Start (text) ===');
const textStart = run('Debounce Start', startMock({
  from: '5910596431872@lid',
  body: 'o telhado nao tenho refeencia',
}));
check('no media_url on text', textStart.media_url === null);
check('no marker on text', !textStart.msg_text.includes('[[REF_FOTO:'), textStart.msg_text);

// ── 3. Resolve extracts media from the buffer and hides the marker ──
console.log('=== Debounce Resolve (later text message) ===');
const now = Date.now();
const pending = 'a aguia queria tipo essa\n[[REF_FOTO:' + IMAGE_URL + ']]\no telhado nao tenho refeencia';
const resolveMock = (name) => {
  if (name === 'Get Buffer State') {
    return {
      pending,
      last_msg_at: new Date(now - 10000).toISOString(),
      typing_state: 'paused',
    };
  }
  if (name === 'Debounce Start') {
    return { chat_id: 'teste:1', msg_ts: now - 10000 };
  }
  return {};
};
const resolved = run('Debounce Resolve', resolveMock);
check('decision is process', resolved.decision === 'process', JSON.stringify(resolved));
check('media_urls contains the image', JSON.stringify(resolved.media_urls) === JSON.stringify([IMAGE_URL]), JSON.stringify(resolved.media_urls));
check('marker stripped from combined_text', !resolved.combined_text.includes('[[REF_FOTO:'), resolved.combined_text);
check('caption survives in combined_text', resolved.combined_text.includes('a aguia queria tipo essa'), resolved.combined_text);

// ── 4. Build References sends the buffered image even when the resolving
//       message has no media of its own ──
console.log('=== Build References ===');
function refsMock(resolve, start) {
  return (name) => {
    if (name === 'Debounce Start') return start;
    if (name === 'Debounce Resolve') return resolve;
    if (name === 'Start Turn') return { project_id: 'p1' };
    if (name === 'Resolve Artist') return { id: 'a1' };
    return {};
  };
}
const refs = run('Build References', refsMock(resolved, { media_url: null, links: [] }));
check('image from an earlier message is included',
  refs.references.length === 1 && refs.references[0].url === IMAGE_URL,
  JSON.stringify(refs.references));

const refsDedup = run('Build References', refsMock(resolved, { media_url: IMAGE_URL, media_mime: 'image/jpeg', links: [] }));
check('image is not duplicated when present on both sides',
  refsDedup.references.length === 1,
  JSON.stringify(refsDedup.references));

// ── 5. text-only turns still produce no references ──
const emptyResolve = { media_urls: [], combined_text: 'oi' };
const emptyRefs = run('Build References', refsMock(emptyResolve, { media_url: null, links: [] }));
check('text-only turn has no references', emptyRefs.references.length === 0, JSON.stringify(emptyRefs.references));

console.log('\n=== Results: ' + passes + ' passed, ' + failures + ' failed ===');
process.exit(failures === 0 ? 0 : 1);
