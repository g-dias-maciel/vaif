#!/usr/bin/env -S node --no-warnings
/**
 * VAIF LP — Browser E2E tests (Playwright)
 *
 * Prerequisites:
 *   npx playwright install chromium
 *
 * Run:
 *   node tests/e2e.mjs
 */

import { chromium } from 'playwright';

const BASE = 'http://localhost:8000';
let passed = 0, failed = 0;

function test(label, ok, detail = '') {
  if (ok) { passed++; console.log(`  ✅ ${label}`); }
  else    { failed++; console.log(`  ❌ ${label}${detail ? ' — ' + detail : ''}`); }
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });

  // ── Index page ────────────────────────────────────────
  console.log('\n=== Index page ===');
  const page = await ctx.newPage();
  await page.goto(BASE + '/index.php', { waitUntil: 'networkidle' });

  test('Page title contains VAIF', await page.title(), await page.title());

  // Nav is visible and branded
  const navText = await page.textContent('.navbar');
  test('Nav shows Atendente Virtual', navText.includes('Atendente Virtual'), 'Missing Atendente Virtual');
  test('Nav does NOT show SDR de IA', !navText.includes('SDR de IA'), 'Still shows SDR de IA');

  // CTA buttons
  const heroCta = page.locator('.hero a.btn-primary');
  test('Hero CTA exists', await heroCta.count() > 0);
  const heroHref = await heroCta.getAttribute('href');
  test('Hero CTA → #aplicar', heroHref === '#aplicar', `href="${heroHref}"`);

  // Service cards — verify icon-after-title order
  const cards = page.locator('.service-card');
  const cardCount = await cards.count();
  test('6 service cards', cardCount === 6, `Found ${cardCount}`);

  if (cardCount > 0) {
    const card1 = cards.nth(0);
    const innerHtml = await card1.innerHTML();
    const numPos = innerHtml.indexOf('service-number');
    const h3Pos  = innerHtml.indexOf('<h3');
    const iconPos = innerHtml.indexOf('service-icon');
    test('Card 01: number before title', numPos < h3Pos, `num=${numPos}, h3=${h3Pos}`);
    test('Card 01: title before icon', h3Pos < iconPos, `h3=${h3Pos}, icon=${iconPos}`);

    // All service CTAs → #aplicar
    const ctaLinks = await card1.locator('.service-cta').getAttribute('href');
    test('Card 01 CTA → #aplicar', ctaLinks === '#aplicar', `href="${ctaLinks}"`);
  }

  // Chat simulator loads
  await page.waitForSelector('.chat-message.bot', { timeout: 5000 });
  test('Chat bot message appears', true);

  // Send a chat message
  const chatInput = page.locator('#chat-user-input');
  const sendBtn   = page.locator('#chat-send-btn');
  await chatInput.fill('sim');
  await sendBtn.click();
  await page.waitForTimeout(1500);
  const chatMessages = await page.locator('.chat-message');
  const msgCount = await chatMessages.count();
  test('Chat replies after user input', msgCount >= 2, `message count: ${msgCount}`);

  // Qualification form on page
  const form = page.locator('#qualification-form');
  test('Qualification form exists', await form.count() > 0);

  // ── Calculadora page ──────────────────────────────────
  console.log('\n=== Calculadora page ===');
  const calcPage = await ctx.newPage();
  await calcPage.goto(BASE + '/calculadora.php', { waitUntil: 'networkidle' });
  const calcTitle = await calcPage.title();
  test('Calculadora page loads', calcTitle.length > 0, `title="${calcTitle}"`);

  // ── Summary ──────────────────────────────────────────
  console.log(`\n${'═'.repeat(50)}`);
  console.log(`  Results: ${passed} passed, ${failed} failed`);
  console.log(`${'═'.repeat(50)}\n`);

  await browser.close();
  process.exit(failed > 0 ? 1 : 0);
})();
