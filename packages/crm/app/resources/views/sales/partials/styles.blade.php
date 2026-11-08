<style>
    .sales-table { width: 100%; border-collapse: collapse; }
    .sales-table th, .sales-table td { padding: 10px 8px; border-bottom: 1px solid var(--border); text-align: left; font-size: .82rem; vertical-align: top; }
    .sales-table th { color: var(--muted); font-size: .65rem; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
    .sales-table tr:hover td { background: var(--panel-2); }
    .sales-table a { color: var(--ink); }
    .sales-table a:hover { color: var(--gold); }
    .sales-toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; }
    .sales-toolbar input, .sales-toolbar select {
        background: var(--bg); border: 1px solid var(--border); border-radius: 8px;
        color: var(--ink); padding: 9px 12px; font-family: 'Montserrat', sans-serif; font-size: .82rem;
    }
    .sales-toolbar input { flex: 1; min-width: 200px; }
    .sales-toolbar input:focus, .sales-toolbar select:focus { outline: none; border-color: var(--gold); }
    .sales-empty { color: var(--muted); font-size: .85rem; padding: 18px 4px; }
    .sales-check { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); font-size: .78rem; }
    .sales-check input { accent-color: var(--gold); }
    .band { display: inline-block; padding: 3px 8px; border-radius: 999px; border: 1px solid var(--border); font-size: .66rem; text-transform: uppercase; letter-spacing: .5px; white-space: nowrap; }
    .band--full { border-color: var(--gold); color: var(--gold); }
    .band--retainer { border-color: #4ade80; color: #4ade80; }
    .band--entry { border-color: #60a5fa; color: #60a5fa; }
    .band--nurture { border-color: var(--muted); color: var(--muted); }
    .sales-sub { color: var(--muted); font-size: .78rem; margin-top: 3px; }
</style>
