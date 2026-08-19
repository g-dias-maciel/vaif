Type: grilling
Status: open
Blocked by: none

## Question

What's the refactoring plan for calculadora.php code duplication?

`calculadora.php` (~2125 lines) duplicates significant portions of:
- CSS from `style.css` (inline `<style>` block with ~60% overlap)
- JavaScript from `calculator.js` and `main.js` (redefined functions)
- The inline CSS also contains rules for components that exist only in `index.php`

The refactoring needs to:
1. Extract shared CSS into `style.css` or a new shared stylesheet
2. Extract shared JS into the existing `.js` files
3. Make `calculadora.php` include these shared assets instead of inlining them
4. Preserve the standalone behavior (calculadora.php can be loaded independently)
5. Not break anything — the calculator funnel is the primary lead gen engine

What's the safe, incremental approach? What should be extracted vs what stays page-specific? How do we verify nothing breaks?
