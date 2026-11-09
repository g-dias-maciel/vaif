@include('sales.partials.styles')
<style>
    .cal-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .cal-toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; }
    .cal-toolbar input, .cal-toolbar select {
        background: var(--bg); border: 1px solid var(--border); border-radius: 8px;
        color: var(--ink); padding: 9px 12px; font-family: 'Montserrat', sans-serif; font-size: .82rem;
    }
    .cal-toolbar input:focus, .cal-toolbar select:focus { outline: none; border-color: var(--gold); }
    .cal-toolbar input[type="search"] { flex: 1; min-width: 180px; }
    .cal-month-nav { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .cal-month-title { font-size: 1.05rem; min-width: 150px; }
    .cal-grid-wrap { overflow-x: auto; }
    .cal-grid { min-width: 840px; display: grid; grid-template-columns: repeat(7, 1fr); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
    .cal-dow { background: var(--panel-2); padding: 8px 6px; font-size: .62rem; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); text-align: center; }
    .cal-day { min-height: 128px; padding: 7px; border-top: 1px solid var(--border); border-right: 1px solid var(--border); background: var(--panel); }
    .cal-day:nth-child(7n) { border-right: 0; }
    .cal-day--muted { opacity: .45; }
    .cal-day--today { background: rgba(212, 175, 55, .07); }
    .cal-day-head { display: flex; justify-content: space-between; align-items: baseline; color: var(--muted); font-size: .68rem; margin-bottom: 4px; }
    .cal-day--today .cal-day-head span:first-child { color: var(--gold); font-weight: 700; }
    .cal-event { display: block; margin-top: 4px; padding: 4px 6px; border-left: 3px solid var(--border); border-radius: 4px; background: var(--panel-2); color: var(--ink); font-size: .68rem; line-height: 1.25; }
    .cal-event:hover { color: var(--gold); }
    .cal-event--sales { border-left-color: #4ade80; }
    .cal-event--strategy { border-left-color: #60a5fa; }
    .cal-event--other { border-left-color: #fbbf24; }
    .cal-event-time { display: block; color: var(--muted); font-size: .6rem; text-transform: uppercase; letter-spacing: .4px; }
    .cal-event-title { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cal-event-kind { color: var(--muted); font-size: .58rem; }
    .cal-legend { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; margin: 14px 0; color: var(--muted); font-size: .74rem; }
    .cal-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; }
    .cal-dot--sales { background: #4ade80; }
    .cal-dot--strategy { background: #60a5fa; }
    .cal-dot--other { background: #fbbf24; }
    .cal-badge { display: inline-block; padding: 2px 7px; border-radius: 999px; border: 1px solid var(--border); font-size: .6rem; text-transform: uppercase; letter-spacing: .5px; white-space: nowrap; }
    .cal-badge--sales { color: #4ade80; border-color: #4ade80; }
    .cal-badge--strategy { color: #60a5fa; border-color: #60a5fa; }
    .cal-badge--other { color: #fbbf24; border-color: #fbbf24; }
    .cal-list-title { margin: 24px 0 10px; font-size: 1rem; }
</style>
