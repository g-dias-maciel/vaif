# Calculadora refactor — extract shared CSS/JS to external files

Labels: implementation

## Parent

[Build Spec: Blog + Artist Landing Pages for vaif-lp](https://github.com/g-dias-maciel/vaif/issues/20)

## What to build

`calculadora.php` currently inlines ~1,200 lines of CSS and all JavaScript, duplicating code from `style.css`, `js/main.js`, and `js/calculator.js`. Build `calculadora-v2.php` that loads shared external files instead, then swap it in.

## Acceptance criteria

- [ ] `calculadora-v2.php` loads `style.css` + `css/calculadora.css` (zero inline CSS beyond page-specific overrides)
- [ ] `calculadora-v2.php` loads `main.js` + `calculator.js` + `calculadora-page.js` (zero inline JS beyond tracking pixels)
- [ ] `css/calculadora.css` contains the ~47 calculadora-specific selectors extracted from the inline `<style>` block
- [ ] `js/calculadora-page.js` wraps shared core functions with calculadora-specific DOM (analyzing overlay, progress bar, confirmation page)
- [ ] Three CSS/JS incompatibilities fixed: `--text-muted` value unified, fade-in uses IntersectionObserver on both pages, marquee uses consistent wrapper structure
- [ ] All existing acceptance tests (`tests/acceptance_test.php`, `tests/e2e.mjs`) pass on `calculadora-v2.php`
- [ ] Existing `calculadora.php` remains unchanged and live while v2 is built
- [ ] Only swap to v2 after all tests pass

## Blocked by

None — can start immediately.
