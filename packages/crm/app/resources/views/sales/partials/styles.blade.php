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
    .sales-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 14px; }
    .sales-grid .label { font-size: .64rem; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); margin-bottom: 3px; }
    .sales-grid .value { font-size: .9rem; }
    .sales-form { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .sales-form label { display: flex; flex-direction: column; gap: 6px; font-size: .66rem; text-transform: uppercase; letter-spacing: .8px; color: var(--muted); }
    .sales-form input, .sales-form select, .sales-form textarea {
        background: var(--bg); border: 1px solid var(--border); border-radius: 8px;
        color: var(--ink); padding: 9px 12px; font-family: 'Montserrat', sans-serif; font-size: .85rem;
    }
    .sales-form input:focus, .sales-form select:focus, .sales-form textarea:focus { outline: none; border-color: var(--gold); }
    .sales-form textarea { min-height: 84px; resize: vertical; }
    .sales-form .full { grid-column: 1 / -1; }
    .sales-errors { background: #2a0a0a; border: 1px solid var(--danger); color: #fecaca; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: .85rem; }
    .sales-errors ul { margin: 0; padding-left: 18px; }
    .activity { display: flex; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border); }
    .activity:last-child { border-bottom: 0; }
    .activity-type { flex: 0 0 auto; font-size: .62rem; text-transform: uppercase; letter-spacing: .6px; color: var(--gold); border: 1px solid var(--border); border-radius: 999px; padding: 4px 8px; height: fit-content; }
    .activity-body { flex: 1; min-width: 0; }
    .activity-body strong { font-size: .88rem; }
    .activity-body p { color: var(--ink); font-size: .84rem; margin: 4px 0 0; white-space: pre-wrap; }
    .activity-meta { color: var(--muted); font-size: .68rem; margin-top: 5px; }
</style>
