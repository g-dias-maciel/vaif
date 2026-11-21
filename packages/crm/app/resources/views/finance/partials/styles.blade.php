@include('sales.partials.styles')
<style>
    .fin-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; }
    .fin-card { background: var(--panel-2); border: 1px solid var(--border); border-radius: 12px; padding: 16px; }
    .fin-card .label { color: var(--muted); font-size: .62rem; text-transform: uppercase; letter-spacing: 1px; }
    .fin-card .value { font-size: 1.35rem; margin-top: 6px; }
    .fin-card .hint { color: var(--muted); font-size: .72rem; margin-top: 5px; }
    .fin-tone-ok { color: #4ade80; }
    .fin-tone-danger { color: #f87171; }
    .fin-tone-pending { color: #fbbf24; }
    .fin-tone-muted { color: var(--muted); }
    .fin-badge { display: inline-block; padding: 3px 8px; border-radius: 999px; border: 1px solid var(--border); font-size: .64rem; text-transform: uppercase; letter-spacing: .5px; white-space: nowrap; }
    .fin-badge--ok { color: #4ade80; border-color: #4ade80; }
    .fin-badge--danger { color: #f87171; border-color: #f87171; }
    .fin-badge--pending { color: #fbbf24; border-color: #fbbf24; }
    .fin-badge--muted { color: var(--muted); }
    .fin-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .fin-section { margin-top: 22px; }
    .fin-section h2 { margin: 0 0 10px; font-size: 1rem; }
    .fin-note { color: var(--muted); font-size: .78rem; }
    .fin-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin: 22px 0 16px; flex-wrap: wrap; }
    .fin-tab { background: transparent; border: 0; color: var(--muted); padding: 9px 14px; cursor: pointer; font: inherit; font-size: .82rem; border-bottom: 2px solid transparent; }
    .fin-tab:hover { color: var(--ink); }
    .fin-tab.is-active { color: var(--ink); border-bottom-color: #fbbf24; font-weight: 600; }
    .fin-tab-panel { display: none; }
    .fin-tab-panel.is-active { display: block; }
    .fin-partner { border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 14px; background: var(--panel-2); }
    .fin-partner__head { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; flex-wrap: wrap; margin-bottom: 10px; }
    .fin-partner__name { font-weight: 600; }
    .fin-partner__total { font-size: 1.1rem; }
    .fin-expense-pick { display: flex; justify-content: space-between; gap: 10px; padding: 6px 0; border-bottom: 1px solid var(--border); font-size: .8rem; }
    .fin-expense-pick:last-child { border-bottom: 0; }
    .fin-expense-pick label { display: flex; gap: 8px; align-items: flex-start; flex: 1; cursor: pointer; }
    .fin-subtotal { display: flex; justify-content: space-between; gap: 12px; padding-top: 10px; margin-top: 8px; border-top: 1px dashed var(--border); font-size: .8rem; }
    .fin-item-row { display: grid; grid-template-columns: 1fr 90px 130px 34px; gap: 8px; margin-bottom: 8px; align-items: end; }
    .fin-item-row label { font-size: .7rem; color: var(--muted); display: flex; flex-direction: column; gap: 4px; }
    .fin-mini { font-size: .7rem; color: var(--muted); }
    .fin-print-only { display: none; }
    @media print {
        .nav, .sidebar, .topbar, .fin-no-print { display: none !important; }
        body { background: #fff !important; color: #000 !important; }
        .panel { border: 0 !important; background: #fff !important; box-shadow: none !important; }
        .fin-print-only { display: block; }
    }
</style>
