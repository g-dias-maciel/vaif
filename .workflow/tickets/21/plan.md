# Implementation Plan — Ticket #21: Calculadora Refactor

## Summary

`calculadora.php` currently inlines ~1,174 lines of CSS and ~460 lines of JavaScript, duplicating code from `style.css`, `js/main.js`, and `js/calculator.js`. Build `calculadora-v2.php` that loads shared external files, extract page-specific CSS/JS into new files, fix three known incompatibilities, then swap when all tests pass.

---

## Dependency-Ordered Steps

### Step 0 — Audit & Baseline

**What:** Run existing test suites against current `calculadora.php` to capture baseline.

- `php tests/acceptance_test.php` (curl-based, requires `php -S localhost:8000`)
- `node tests/e2e.mjs` (Playwright, requires same local server)

**Why:** We need a green baseline before making changes. Any pre-existing failures must be documented.

**Modifies:** Nothing.

---

### Step 1 — Create `css/calculadora.css`

**What:** New file. Extract calculadora-specific selectors and the three incompatibility fixes.

**New file:** `packages/lp/css/calculadora.css`

**Change:** Create `packages/lp/css/` directory (doesn't exist). Write `calculadora.css` containing:

**Selectors to extract (page-specific, NOT in `style.css`):**

General layout:
- `.calculator-section` — padding, background-color, text-align for the calculator form section
- `.section-header` — margin-bottom for section heading block
- `.section-title` — font-size, margin, flex centering for "A Calculadora do Lucro Oculto" heading

Track record / social proof (4-column stat grid):
- `.track-record`, `.track-record-title`, `.track-grid`, `.track-grid.track-grid-4`
- `.track-item`, `.track-number`, `.track-label`
- `.conviction-block`

Testimonial carousel (coverflow):
- `.testimonial-section`, `.carousel-viewport`, `.carousel-track`, `.carousel-slide`
- `.carousel-slide-inactive` + 3 descendant overrides for Instagram/result text color
- `.carousel-photo`, `.carousel-instagram`, `.carousel-instagram a`, `.carousel-instagram a:hover`
- `.carousel-result`, `.carousel-result span`, `.carousel-quote`
- `.carousel-dots`, `.carousel-dot`, `.carousel-dot.active`
- `.carousel-arrows`, `.carousel-arrow`, `.carousel-arrow.prev`, `.carousel-arrow.next`, `.carousel-arrow:hover`

Confirmation/success section:
- `.confirmation-page`, `.confirmation-checkmark`, `.confirmation-checkmark svg`
- `.confirmation-title`, `.confirmation-subtitle`, `.confirmation-subtitle strong`, `.confirmation-subtitle .highlight-gold`
- `.homework-card`, `.homework-label`, `.homework-video`, `.homework-video img`
- `.homework-play`, `.homework-video:hover .homework-play`
- `.confirmation-footer`, `.confirmation-footer svg`
- `.call-outcomes`, `.outcomes-label`, `.outcomes-list`, `.outcomes-list li`, `.outcomes-list li::before`
- `.specialist-card`, `.specialist-label`, `.specialist-row`, `.specialist-avatar`, `.specialist-info`, `.specialist-name`, `.specialist-role`
- `.homework-commitment`, `.homework-commitment span`, `.homework-whatsapp-btn`, `.homework-whatsapp-btn:hover`

Analyzing overlay (micro-transition):
- `.analyzing-content`, `.analyzing-spinner`, `@keyframes spin`
- `.analyzing-title`, `.analyzing-detail`, `.analyzing-detail strong`, `.analyzing-status`

Overrides / properties that differ from `style.css`:
- `#nativeCalendarBlock` — add `border-radius: 8px; animation: fadeInUp 0.6s ease forwards;` (style.css has neither)
- `.time-slot:disabled` — override style.css's `opacity: 0.3` with `opacity: 1; text-decoration: none;` (calculadora display convention differs)
- `.ebook-premium-box` — add `border-radius: 8px; animation: fadeInUp 0.6s ease forwards;`
- `.progress-wrapper` — add `padding-top: 80px;` override (style.css has `padding: 20px 0`)
- `.hero-header` (or use `.hero` + `.hero-content` specific to calculadora): hero padding `80px 24px`, hero-content `max-width: 650px` with specific margin-left; hero-links `flex-direction: column; align-items: flex-start;`

Calculadora-specific `@media (max-width: 768px)` rules:
- `.calc-card` padding
- `.hero-title` font-size
- `.track-grid` single-column
- `.carousel-viewport` max-width, `.carousel-slide` width, `.carousel-track` min-height, `.carousel-photo` dimensions, `.carousel-quote` max-width/font-size, `.carousel-arrow` sizing, `.carousel-arrows` top positioning, `.marquee-logo` height, `.marquee-track` gap, `.confirmation-title` font-size

`@media (max-width: 600px)`:
- `.calendar-grid` single-column

**Three incompatibilities addressed in this step:**

1. **`--text-muted` unified** — Style.css `:root` defines `--text-muted: #CCCCCC`. Calculadora inline used `rgb(160, 154, 142)` (`#A09A8E`). Since `calculadora-v2.php` loads `style.css` first, the `:root` block from style.css supplies all custom properties. No separate `:root` needed in `calculadora.css`. This means the `--text-muted` rendering will shift from `#A09A8E` to `#CCCCCC` on the calculadora page. This is intentional and matches `index.php`.

2. **Fade-in uses IntersectionObserver** — `style.css` defines `.fade-in-up` with `transition` (not `animation`), toggled by `.visible` class added by `setupScrollObserver()` in `main.js`. Inline calculadora CSS used `animation: fadeInUp 1.2s cubic-bezier(…) forwards` instead. `calculadora.css` must NOT redefine `.fade-in-up` or `@keyframes fadeInUp`. When `style.css` is loaded and `main.js` runs `setupScrollObserver()`, hero `.fade-in-up` elements get `.visible` immediately (main.js L116-118), and below-the-fold elements animate on scroll via IntersectionObserver. The `.delay-1`, `.delay-2`, `.delay-3` classes from style.css provide staggered `transition-delay`.

3. **Marquee wrapper structure** — `style.css` marquee uses `.marquee-set` wrapper divs and `translateX(calc(-50% - 30px))` keyframe. Calculadora inline CSS duplicated marquee styles with direct children and `translateX(-50%)`. `calculadora.css` must NOT redefine `.trusted-section`, `.trusted-label`, `.marquee-wrap`, `.marquee-track`, `.marquee-logo`, `.marquee-logo:hover`, or `@keyframes marqueeScroll`. The HTML in `calculadora-v2.php` must restructure marquee to use `.marquee-set` wrappers (see Step 4).

**Why:** Decouples calculadora-specific styles from the shared `style.css` design system. Makes the page load smaller by eliminating ~1,000 lines of duplicated CSS.

**Ambiguity:** The exact count of "~47" selectors depends on how you group compound selectors. The principle is: extract everything calculadora-specific that isn't in style.css. The list above is comprehensive.

**Verification:** After writing the file, no verification until `calculadora-v2.php` exists (Step 4).

---

### Step 2 — Create `js/calculadora-page.js`

**What:** New file. Page-specific JavaScript that wraps shared core functions with calculadora-specific DOM.

**New file:** `packages/lp/js/calculadora-page.js`

**Change:** Write JavaScript containing ONLY the functions unique to calculadora or that override `calculator.js`:

**Unique functions (not in calculator.js or main.js):**

1. `scrollToCalculator()` — scrolls to `#progressWrapper`. Referenced by hero CTA button and scroll indicator in HTML.

2. Carousel coverflow IIFE — reads `#carouselTrack`, lays out slides in 3D-rotated positions, exposes `window.moverCarrossel(dir)`. This is pure page-specific DOM behavior.

**Override functions (defined in calculator.js but calculadora needs enhanced versions):**

3. `handleLeadSubmit()` — Overrides the calculator.js version. Differences:
   - Hides `.conviction-block` element (not hidden by calculator.js)
   - Populates and shows `#analyzingOverlay` with lead name, faturamento, prejuizo
   - Runs micro-transition: 2s spinner → "Perfil qualificado" fade-in (1.5s) → hide overlay
   - Then calls the calendar/ebook branch AND sets progress bar to 80% label for high-ticket path (calculator.js leaves it at 80% but doesn't change the label text)
   - Note: this function calls `encontrarProximaJanelaDisponivel()`, `gerarDiasCalendario()`, and uses `window.calcData` — all provided by `calculator.js` which loads first.

4. `mostrarTelaSucessoFinal()` — Overrides the calculator.js version. Differences:
   - Uses DOM IDs `confirmationTitle`, `confirmationSubtitle`, `confNamePlaceholder`, `confDateTimePlaceholder`, `confLossPlaceholder` (calculadora's confirmation DOM) instead of `finalSuccessTitle`/`finalSuccessText` (which don't exist on calculadora)
   - Sets innerHTML directly on confirmation subtitle element

5. `handleCalculate()` — **Ambiguity decision.** The calculator.js version is identical in logic. However, there's a subtle difference: inline calculadora version applies `display:flex` on `.hero-label` elements inside result section via inline styles on the HTML elements themselves, not via JS. So calculator.js's version IS compatible. **Load calculator.js for this function.**

**Removed duplicates (handled by loading shared files):**
- `animateValue()` → from `main.js`
- `parseBrNumber()` → from `main.js`
- Input masks (`DOMContentLoaded`) → from `main.js`'s `setupInputMasks()`
- `obterDataEmBrasilia()`, `formatarParaBanco()`, `encontrarProximaJanelaDisponivel()`, `gerarDiasCalendario()`, `selecionarSlot()`, `confirmarAgendamento()` → from `calculator.js`
- `pularAgendamento()` → from `calculator.js`
- `trackEbookClick()` → from `calculator.js`

**Loader order (handled in Step 4 HTML):** `main.js` → `calculator.js` → `calculadora-page.js`. Since `calculadora-page.js` redefines `handleLeadSubmit` and `mostrarTelaSucessoFinal`, the page-specific versions take effect. `calculator.js` defines `handleCalculate` which is fine as-is.

**Why:** Eliminates ~300 lines of JS duplication while keeping calculadora-specific DOM manipulation (analyzing overlay, confirmation page IDs) in a dedicated file.

**Ambiguity:** `handleLeadSubmit` in calculator.js currently sets progressBar to 80% for high-ticket path. The inline calculadora version also sets it to 80% but with a different label ("Passo 2 de 2: Escolha seu horário (80%)" vs "Passo 2 de 2: Liberação do Plano Estratégico (80%)"). The overridden version should use the calculadora-specific label. VERIFY which label is intended.

**Verification:** No verification until `calculadora-v2.php` exists.

---

### Step 3 — Update `style.css` (three incompatibility fixes)

**What:** Ensure `style.css` is the single source of truth for shared styles, and fix any remaining incompatibilities.

**Modifies:** `packages/lp/style.css`

**Changes:**

1. **Unify `--text-muted`** — Already `#CCCCCC` in style.css. No change needed in style.css itself. The "fix" is that `calculadora.css` must NOT redefine `--text-muted`. The `:root` block from style.css provides the canonical value.

2. **Marquee structure** — `style.css` already uses `.marquee-set` wrappers and `translateX(calc(-50% - 30px))`. No CSS change needed. The "fix" is in the HTML (see Step 4).

3. **`@keyframes fadeInUp`** — `style.css` does NOT define this keyframe (it uses JS-driven IntersectionObserver). Since `calculadora.css` will NOT define it either, and `calculadora-v2.php` won't reference it, no CSS change needed.

4. **Scroll-indicator animation** — `@keyframes bounce` in style.css uses `3s infinite` with opacity animation; inline calculadora uses `2s infinite` with different transform values. The inline version also hits `translate(-50%, 0)` and `translate(-50%, 8px)`. Style.css uses `translate(-50%, 0)` and `translate(-50%, 6px)` with opacity animation. These are minor differences. Style.css's version is the source of truth — no override needed. VERIFY: visually check scroll indicator looks acceptable with style.css's bounce.

**Why:** No style.css changes strictly required for this step. The incompatibilities are resolved by CSS not being redefined in `calculadora.css` and HTML matching the `.marquee-set` convention.

**Verification:** After `calculadora-v2.php` loads, visual check.

---

### Step 4 — Create `calculadora-v2.php`

**What:** New file. HTML structure identical to `calculadora.php` but replaces inline CSS/JS blocks with external file loads, fixes marquee HTML structure, and removes duplicate `:root`.

**New file:** `packages/lp/calculadora-v2.php`

**Changes from `calculadora.php`:**

**Head (lines 1-44):**
- Keep: DOCTYPE, charset, viewport, title, Google Fonts preconnect + font link, favicon links
- Keep: Facebook Pixel `<script>` (lines 10-21) — tracking pixel, permitted inline
- Keep: Matomo `<script>` (lines 22-34) — analytics, permitted inline
- Keep: `<noscript>` Facebook fallback (lines 35-37)
- **ADD:** `<link rel="stylesheet" href="style.css">` — after font link, before any calculadora-specific CSS
- **ADD:** `<link rel="stylesheet" href="css/calculadora.css">` — after style.css
- **REMOVE:** Entire inline `<style>` block (lines 45-1219) — replaces with `css/calculadora.css`

**Body HTML (lines 1222-1661):**
- Keep: All HTML structure exactly as-is, except:

**Marquee HTML restructuring (two instances — inside `.calculator-section` and inside `.result-section`):**
- Current structure (inline approach):
  ```html
  <div class="marquee-track">
    <a><img class="marquee-logo" ...></a>
    <!-- 6 logos -->
    <a><img class="marquee-logo" ...></a>
    <!-- 6 duplicate logos for infinite loop -->
  </div>
  ```
- **REPLACE WITH** (style.css convention):
  ```html
  <div class="marquee-track">
    <div class="marquee-set">
      <a><img class="marquee-logo" ...></a>
      <!-- 6 logos -->
    </div>
    <div class="marquee-set" aria-hidden="true">
      <a><img class="marquee-logo" ...></a>
      <!-- 6 duplicate logos -->
    </div>
  </div>
  ```
- Both marquee instances need this restructuring (hero/calculator section and result section).

**Remove inline `:root` usage:**
- The `<style>` already removed. Style.css provides `:root`. No other changes.

**Before `</body>` (replace inline `<script>` lines 1663-2123):**
- **REMOVE:** Entire inline `<script>` block
- **ADD:**
  ```html
  <script src="js/main.js"></script>
  <script src="js/calculator.js"></script>
  <script src="js/calculadora-page.js"></script>
  ```

**Why:** `calculadora-v2.php` loads ~70 KB of CSS/JS from shared external files instead of embedding ~1,600 inline lines. This eliminates code duplication, enables browser caching, and matches the architecture of `index.php`.

**Verification:** Load `calculadora-v2.php` in browser, verify:
- Page renders visually identical to `calculadora.php` (side-by-side)
- All interactive elements work: calculator form submit, number animation, lead form, WhatsApp mask, Instagram mask, calendar slot selection, calendar confirmation, ebook redirect, skip scheduling, carousel arrows
- Progress bar steps through 50% → 80% → 100% correctly
- Analyzing overlay appears and transitions
- Confirmation page shows correct name/date/loss values
- Check browser console for no JS errors, no 404s on CSS/JS files

---

### Step 5 — Update Acceptance Tests

**What:** Update existing tests to cover `calculadora-v2.php` changes and verify the refactored page.

**Modifies:** `packages/lp/tests/acceptance_test.php`

**Changes:**

1. **Current tests that reference inline style/script (lines 158-163):**
   ```php
   test('Calculadora has inline style', str_contains($calc, '<style>'), ...)
   test('Calculadora has form step', str_contains($calc, 'step') || str_contains($calc, 'pergunta'), ...)
   test('Calculadora has lead form', ...)
   ```
   These test against `calculadora.php`. The first test (`has inline style`) will need to change since `calculadora-v2.php` removes the inline `<style>` block (tracking pixels still use inline `<script>` but the CSS is external).

   **ADD** a second fetch for `calculadora-v2.php` and add tests:
   - `calculadora-v2.php` loads `style.css` (check for `<link rel="stylesheet" href="style.css">`)
   - `calculadora-v2.php` loads `css/calculadora.css` (check for `<link rel="stylesheet" href="css/calculadora.css">`)
   - `calculadora-v2.php` loads `js/main.js` (check for `<script src="js/main.js">`)
   - `calculadora-v2.php` loads `js/calculator.js` (check for `<script src="js/calculator.js">`)
   - `calculadora-v2.php` loads `js/calculadora-page.js` (check for `<script src="js/calculadora-page.js">`)
   - `calculadora-v2.php` has NO inline `<style>` block longer than tracking-pixel scripts (search for `<style>` with minimum content length, or check for `calculadora-form` CSS class presence)
   - `calculadora-v2.php` has NO inline `<script>` block beyond tracking pixels (check for `</script>` count = 2 from Facebook + Matomo)
   - `calculadora-v2.php` still has the calculator form (`#calcForm`)
   - `calculadora-v2.php` still has the lead form (`#leadForm`)

2. **Keep existing `calculadora.php` tests** — they verify the old page remains unchanged and live.

**Modifies:** `packages/lp/tests/e2e.mjs`

**Changes:**

1. **Add a `calculadora-v2.php` test block** (after the existing `calculadora.php` block, around line 83):
   ```js
   const calc2Page = await ctx.newPage();
   await calc2Page.goto(BASE + '/calculadora-v2.php', { waitUntil: 'networkidle' });
   ```
   - Page title contains "Lucro Oculto"
   - No console errors
   - All 3 script tags present
   - Both CSS link tags present
   - Calculator form visible
   - Fill calculator form and submit, verify result section appears
   - Verify result section shows animated loss number within 3 seconds
   - Verify lead form is visible after result
   - Verify confirmation page renders after calendar/ebook flow (can mock fetch)

2. **Keep existing `calculadora.php` tests** untouched.

**Why:** Tests ensure the refactored page is functionally identical and confirm external files are loaded correctly. Old page tests remain to satisfy "`calculadora.php` remains unchanged and live".

**Ambiguity:** Playwright E2E tests for the full flow require the backend API (`/api/leads/submit.php`) and database to be available. If running in CI without DB, consider mocking `fetch` responses in the test or flagging calendar/ebook flow tests as requiring full stack.

---

### Step 6 — Verify All Tests Pass on `calculadora-v2.php`

**What:** Run both test suites against the new page.

**Commands:**
```bash
php -S localhost:8000 &
php tests/acceptance_test.php
node tests/e2e.mjs
```

**Expected:** All tests pass. If not, iterate on Steps 1-5.

**Why:** Gate before swapping to v2.

---

### Step 7 — Swap to v2 and Archive

**What:** Once all tests pass and visual QA is confirmed, replace `calculadora.php` with `calculadora-v2.php`.

**Changes:**
1. Rename `calculadora.php` → `calculadora.backup.php` (preserve as rollback)
2. Rename `calculadora-v2.php` → `calculadora.php` (activate v2)
3. Update any hardcoded links if they point to `calculadora-v2.php` (check `index.php`, `components/`, `onboard/` for calculator links)
4. Run acceptance + E2E tests once more against `calculadora.php` (now serving v2)
5. Commit with message: "refactor(lp): extract calculadora CSS/JS to external files"

**Why:** Atomic swap with rollback path. Old file not deleted until v2 is verified in production position.

**Ambiguity:** Are there external links (ads, email campaigns, Instagram bio) pointing directly to `calculadora.php`? If so, the swap is transparent since the URL doesn't change. If anything links to `calculadora-v2.php`, those need updating.

---

## Ambiguities & Open Questions

1. **`--text-muted` value:** Changing from `rgb(160, 154, 142)` to `#CCCCCC` will visibly lighten muted text on the calculadora page. Is this acceptable? If not, should we keep the original value in `:root` inside `calculadora.css`?

2. **Progress bar label for high-ticket path:** Calculator.js sets `progressLabel` to "Passo 2 de 2: Liberação do Plano Estratégico (80%)". The inline calculadora `handleLeadSubmit` sets it to "Passo 2 de 2: Escolha seu horário (80%)". Which label should the refactored page use?

3. **Scroll-indicator bounce animation:** Style.css uses a slightly different `@keyframes bounce` (3s, opacity-based, 6px translateY). Calculadora inline uses 2s, no opacity, 8px translateY. Should the calculadora page get the shared animation, or should `calculadora.css` override it?

4. **Carousel JS placement:** The carousel IIFE is currently in `calculadora-page.js`. Could it also be extracted to its own `js/carousel.js` if it becomes reusable on `index.php` in the future? For now, it stays page-specific.

5. **`#progressWrapper` `padding-top` override:** Style.css defines `padding: 20px 0`. Inline calculadora uses `padding-top: 80px; text-align: center; background-color: #0d0d0d`. The `80px` top padding creates visual breathing room above the progress bar on the isolated calculadora page (vs index.php where the progress wrapper sits below hero). Confirmed needed — goes in `calculadora.css`.

6. **`helper` vs `helper` selector naming:** Inline calculadora uses `.hero` with `background-image` and specific padding. Style.css's `.hero` also has `background-image` but different padding (`120px 24px 60px` vs `80px 24px`). Both target the same element. Since `calculadora.css` loads after `style.css`, we can either:
   - Override just the padding/background in `calculadora.css` (leave `.hero` mostly to style.css)
   - Or fully redeclare `.hero` in `calculadora.css` to be explicit
   Both work. Recommend: override only the differing properties for minimal duplication.

---

## Files Created

| File | Purpose |
|---|---|
| `packages/lp/css/calculadora.css` | Calculadora-specific CSS (~47 selectors) |
| `packages/lp/js/calculadora-page.js` | Page-specific JS (carousel, analyzing overlay, confirmation DOM) |
| `packages/lp/calculadora-v2.php` | Refactored page loading external CSS/JS |

## Files Modified

| File | Change |
|---|---|
| `packages/lp/tests/acceptance_test.php` | Add tests for calculadora-v2.php external file loads |
| `packages/lp/tests/e2e.mjs` | Add Playwright tests for calculadora-v2.php full flow |
| `packages/lp/calculadora.php` | Renamed to `calculadora.backup.php` in final swap step |

## Files Unchanged

| File | Reason |
|---|---|
| `packages/lp/style.css` | Already contains shared + calculator styles; no changes needed |
| `packages/lp/js/main.js` | Already has shared utilities; reused as-is |
| `packages/lp/js/calculator.js` | Already has calculator core logic; reused as-is |
| `packages/lp/index.php` | No changes needed; already loads external files correctly |
