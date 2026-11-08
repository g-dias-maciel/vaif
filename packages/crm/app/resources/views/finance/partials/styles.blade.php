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
    .fin-print-only { display: none; }
    @media print {
        .nav, .sidebar, .topbar, .fin-no-print { display: none !important; }
        body { background: #fff !important; color: #000 !important; }
        .panel { border: 0 !important; background: #fff !important; box-shadow: none !important; }
        .fin-print-only { display: block; }
    }
</style>
