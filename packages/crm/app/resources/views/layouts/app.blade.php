<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'CRM') — VAIF</title>
    <link rel="icon" href="{{ asset('img/favicon/favicon.ico') }}" sizes="any" type="image/x-icon">
    <link rel="icon" href="{{ asset('img/favicon/favicon-16x16.png') }}" sizes="16x16" type="image/png">
    <link rel="icon" href="{{ asset('img/favicon/favicon-32x32.png') }}" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('img/favicon/apple-touch-icon.png') }}">
    <link rel="icon" href="{{ asset('img/favicon/android-chrome-192x192.png') }}" sizes="192x192" type="image/png">
    <link rel="icon" href="{{ asset('img/favicon/android-chrome-512x512.png') }}" sizes="512x512" type="image/png">
    <link rel="manifest" href="{{ asset('img/favicon/site.webmanifest') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0A0A0A;
            --panel: #121212;
            --panel-2: #1a1a1a;
            --border: #222222;
            --ink: rgb(242, 237, 228);
            --muted: rgb(160, 154, 142);
            --gold: #D4B04C;
            --danger: #f87171;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: radial-gradient(ellipse at top, #1a1a1a 0%, var(--bg) 70%);
            color: var(--ink);
            font-family: 'Montserrat', sans-serif;
            min-height: 100vh;
            line-height: 1.5;
        }
        a { color: var(--gold); text-decoration: none; }
        a:hover { opacity: 0.85; }

        /* ---- App shell + sidebar ---- */
        .app-shell { display: flex; min-height: 100vh; }

        .sidebar {
            position: fixed; top: 0; bottom: 0; left: 0; z-index: 40;
            width: 258px; flex: 0 0 258px;
            display: flex; flex-direction: column;
            padding: 22px 16px 18px;
            background: linear-gradient(180deg, #0d0d0d 0%, #070707 100%);
            border-right: 1px solid var(--border);
            overflow-y: auto;
        }
        .sidebar-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 8px; padding: 0 0 22px 10px;
        }
        .brand {
            font-family: 'Montserrat', sans-serif; font-size: 1.35rem; font-weight: 600;
            letter-spacing: 1px; color: var(--ink); text-decoration: none;
        }
        .brand:hover { color: var(--ink); opacity: 1; }
        .brand span { color: var(--gold); font-size: 0.72rem; letter-spacing: 3px; text-transform: uppercase; margin-left: 6px; }

        .side-nav { display: flex; flex-direction: column; }
        .nav-group { display: flex; flex-direction: column; gap: 3px; margin-bottom: 20px; }
        .nav-group-label {
            font-size: 0.6rem; text-transform: uppercase; letter-spacing: 1.8px;
            color: var(--muted); opacity: 0.7; padding: 0 12px 8px;
        }
        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px; border-radius: 9px;
            border: 1px solid transparent;
            color: var(--ink); font-size: 0.85rem; font-weight: 500; text-decoration: none;
            transition: background .15s ease, color .15s ease, border-color .15s ease;
        }
        .nav-item svg { width: 17px; height: 17px; flex: 0 0 17px; fill: none; stroke: currentColor; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; opacity: 0.8; }
        .nav-item:hover { background: var(--panel-2); color: var(--gold); opacity: 1; }
        .nav-item:hover svg { opacity: 1; }
        .nav-item.is-active {
            background: linear-gradient(90deg, rgba(212,176,76,0.16), rgba(212,176,76,0.03));
            border-color: rgba(212,176,76,0.35); color: var(--gold);
        }
        .nav-item.is-active svg { opacity: 1; }
        .nav-item:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }
        .nav-item .nav-ext { margin-left: auto; font-size: 0.65rem; opacity: 0.55; letter-spacing: 1px; }

        .sidebar-foot { margin-top: auto; padding-top: 16px; border-top: 1px solid var(--border); }
        .who { display: flex; flex-direction: column; gap: 2px; padding: 0 12px 12px; }
        .who-avatar {
            display: none; width: 34px; height: 34px; border-radius: 50%;
            border: 1px solid var(--border); background: var(--panel-2); color: var(--gold);
            align-items: center; justify-content: center;
            font-size: 0.78rem; font-weight: 700; text-transform: uppercase;
        }
        .who-name { font-size: 0.83rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .who-role { font-size: 0.64rem; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted); }
        .who-context { font-size: 0.7rem; color: var(--gold); margin-top: 4px; }
        .logout-btn {
            display: flex; align-items: center; gap: 10px; width: 100%;
            padding: 10px 12px; border-radius: 9px; border: 1px solid transparent;
            background: none; color: var(--muted); cursor: pointer; text-align: left;
            font-family: 'Montserrat', sans-serif; font-size: 0.82rem;
        }
        .logout-btn svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
        .logout-btn:hover { background: var(--panel-2); color: var(--danger); }

        .app-main { flex: 1; min-width: 0; margin-left: 258px; }
        main { max-width: 1180px; margin: 0 auto; padding: 30px 30px 60px; }

        .mobile-bar { display: none; }
        .backdrop { display: none; }

        /* ---- Collapsible sidebar (desktop) ---- */
        .collapse-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 26px; height: 26px; flex: 0 0 26px; padding: 0;
            background: none; border: 1px solid transparent; border-radius: 7px;
            color: var(--muted); cursor: pointer;
        }
        .collapse-btn svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; transition: transform .18s ease; }
        .collapse-btn:hover { color: var(--gold); background: var(--panel-2); }
        .collapse-btn:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }

        @media (min-width: 901px) {
            .sidebar { transition: width .18s ease, padding .18s ease; }
            .app-main { transition: margin-left .18s ease; }

            html.is-collapsed .sidebar {
                width: 76px; flex-basis: 76px;
                padding-left: 12px; padding-right: 12px;
            }
            html.is-collapsed .app-main { margin-left: 76px; }
            html.is-collapsed .sidebar-head { justify-content: center; padding: 0 0 18px; }
            html.is-collapsed .sidebar-head .brand { display: none; }
            html.is-collapsed .brand span,
            html.is-collapsed .nav-group-label,
            html.is-collapsed .nav-item span,
            html.is-collapsed .who-name,
            html.is-collapsed .who-role,
            html.is-collapsed .who-context,
            html.is-collapsed .logout-btn span { display: none; }
            html.is-collapsed .nav-group { margin-bottom: 12px; }
            html.is-collapsed .nav-item { justify-content: center; padding: 11px 0; }
            html.is-collapsed .who { align-items: center; padding: 0 0 10px; }
            html.is-collapsed .who-avatar { display: flex; margin: 0 auto; }
            html.is-collapsed .collapse-btn svg { transform: rotate(180deg); }
            html.is-collapsed .logout-btn { justify-content: center; padding: 10px 0; }
        }

        @media (max-width: 900px) {
            .collapse-btn { display: none; }
        }
        h1.page-title, .panel h1 {
            font-family: 'Montserrat', sans-serif; font-weight: 600; font-size: 1.9rem; margin-bottom: 20px;
        }
        h2.section-title {
            font-family: 'Montserrat', sans-serif; font-weight: 600; font-size: 1.3rem;
            text-transform: uppercase; letter-spacing: 1.5px; color: var(--ink); margin: 28px 0 14px;
        }
        .flash {
            background: #0a2a0a; color: #4ade80; border: 1px solid #4ade80;
            padding: 12px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem;
        }
        .flash.error { background: #2a0a0a; color: var(--danger); border-color: var(--danger); }

        .promo-pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px; border-radius: 999px;
            border: 1px solid var(--border); color: var(--muted);
            font-size: 0.75rem; letter-spacing: 0.3px;
        }
        .promo-pill.is-active { border-color: var(--gold); color: var(--gold); background: rgba(212,176,76,0.08); }

        .promo-row {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            background: var(--panel-2); border: 1px solid var(--border); border-radius: 10px;
            padding: 12px 16px; margin-bottom: 10px;
        }
        .promo-row.is-active { border-color: var(--gold); }
        .promo-main { display: flex; flex-direction: column; gap: 2px; }
        .promo-main strong { font-size: 0.92rem; }

        .board { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px; align-items: start; }
        .column { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 14px; }
        .column-title {
            font-family: 'Montserrat', sans-serif; font-size: 1.05rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 1px; color: var(--gold); margin-bottom: 12px;
            display: flex; justify-content: space-between; align-items: baseline;
        }
        .column-title .count { color: var(--muted); font-family: 'Montserrat', sans-serif; font-size: 0.75rem; }
        .cards { display: flex; flex-direction: column; gap: 10px; }
        .card {
            background: var(--panel-2); border: 1px solid var(--border); border-radius: 10px; padding: 12px;
        }
        .card-head { display: flex; flex-direction: column; gap: 2px; margin-bottom: 6px; }
        .card-head strong { font-size: 0.95rem; }
        .card-head .phone { color: var(--muted); font-size: 0.78rem; }
        .meta { color: var(--muted); font-size: 0.75rem; margin-top: 2px; }
        .meta.gold { color: var(--gold); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .meta.price { color: var(--gold); font-weight: 700; font-size: 0.82rem; }

        .notes { margin-top: 10px; border-top: 1px dashed var(--border); padding-top: 8px; }
        .note { font-size: 0.8rem; color: var(--ink); margin-bottom: 6px; }
        .note-by { color: var(--muted); font-size: 0.68rem; }

        .actions { margin-top: 10px; display: flex; flex-direction: column; gap: 8px; }
        .note-form { display: flex; gap: 8px; }
        .note-form input {
            flex: 1; background: var(--bg); border: 1px solid var(--border); border-radius: 8px;
            color: var(--ink); padding: 8px 10px; font-family: 'Montserrat', sans-serif; font-size: 0.8rem; min-width: 0;
        }
        .note-form input:focus { outline: none; border-color: var(--gold); }

        .pricing-add, .pricing-edit { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .pricing-add input, .pricing-edit input {
            background: var(--bg); border: 1px solid var(--border); border-radius: 8px;
            color: var(--ink); padding: 8px 10px; font-family: 'Montserrat', sans-serif; font-size: 0.8rem;
        }
        .pricing-add input:focus, .pricing-edit input:focus { outline: none; border-color: var(--gold); }
        .pricing-add input { flex: 1; min-width: 110px; }
        .pricing-edit input { width: 76px; }
        .pricing-edit input[name="price"] { width: 100px; }

        .pricing-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .pricing-tab { background: var(--panel-2); border: 1px solid var(--border); color: var(--ink); border-radius: 8px; padding: 8px 16px; cursor: pointer; font-family: 'Montserrat', sans-serif; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 1px; }
        .pricing-tab.active { background: var(--gold); color: #0A0A0A; border-color: var(--gold); }
        .pricing-panel { display: none; }
        .pricing-panel.active { display: block; }
        .pricing-matrix { overflow-x: auto; margin-top: 6px; }
        .pricing-matrix table { width: 100%; border-collapse: collapse; min-width: 520px; }
        .pricing-matrix th, .pricing-matrix td { padding: 8px; text-align: left; border-bottom: 1px solid var(--border); vertical-align: top; }
        .pricing-matrix th { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted); }
        .pricing-matrix th.sub { font-size: 0.6rem; color: var(--gold); padding-top: 2px; text-align: center; white-space: nowrap; }
        .pricing-matrix .loc-label { font-weight: 600; font-size: 0.85rem; white-space: nowrap; }
        .pricing-matrix input { background: var(--bg); border: 1px solid var(--border); border-radius: 6px; color: var(--ink); padding: 6px 8px; font-family: 'Montserrat', sans-serif; font-size: 0.78rem; width: 76px; }
        .pricing-matrix input[name*="[duration]"] { width: 56px; }
        .pricing-matrix input:focus { outline: none; border-color: var(--gold); }
        .pricing-add-row { display: flex; flex-direction: column; gap: 10px; margin-top: 18px; }
        .pricing-add select { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; color: var(--ink); padding: 8px 10px; font-family: 'Montserrat', sans-serif; font-size: 0.8rem; }

        .stat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 14px; }
        .stat-card { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 18px; }
        .stat-value { font-family: 'Montserrat', sans-serif; font-size: 1.8rem; font-weight: 600; color: var(--gold); line-height: 1.1; }
        .stat-label { color: var(--muted); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 1px; margin-top: 6px; }
        .bar-row { margin-bottom: 14px; }
        .bar-label { display: flex; justify-content: space-between; font-size: 0.82rem; margin-bottom: 5px; }
        .bar-label .count { color: var(--muted); }
        .bar-track { background: var(--panel-2); border-radius: 6px; height: 6px; overflow: hidden; }
        .bar-fill { background: var(--gold); height: 6px; }

        .week-nav { display: flex; align-items: center; gap: 14px; margin-bottom: 18px; flex-wrap: wrap; }
        .week-range { font-family: 'Montserrat', sans-serif; font-size: 1.25rem; color: var(--ink); }
        .week-scroll { overflow-x: auto; }
        .week-grid { display: grid; grid-template-columns: repeat(7, minmax(140px, 1fr)); gap: 10px; }
        .week-day { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 10px; min-height: 130px; }
        .week-day.today { border-color: var(--gold); }
        .week-day-head { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); margin-bottom: 8px; display: flex; flex-direction: column; gap: 2px; }
        .week-day-head .date { color: var(--gold); font-weight: 700; font-size: 0.8rem; }
        .week-slot { display: block; color: var(--ink); text-decoration: none; background: var(--panel-2); border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; margin-bottom: 8px; font-size: 0.78rem; }
        .week-slot:hover { border-color: var(--gold); }
        .week-slot .time { color: var(--gold); font-weight: 600; font-size: 0.7rem; margin-bottom: 2px; }
        .week-slot .name { font-weight: 600; }
        .week-slot .place { color: var(--muted); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .week-slot.blocked { opacity: 0.5; }
        .week-empty { color: var(--muted); font-size: 0.75rem; padding: 6px 0; }

        .detail-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 14px; }
        .detail-grid .meta { display: block; margin-bottom: 2px; }

        .period-form { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
        .period-form label { font-size: 0.72rem; letter-spacing: 1px; text-transform: uppercase; color: var(--muted); }
        .period-form select {
            background: var(--panel); border: 1px solid var(--border); border-radius: 8px;
            color: var(--ink); padding: 9px 12px; font-family: 'Montserrat', sans-serif; font-size: 0.85rem;
        }
        .period-form select:focus { outline: none; border-color: var(--gold); }

        .ref-grid { display: flex; flex-wrap: wrap; gap: 10px; }
        .ref-grid img { width: 120px; height: 120px; object-fit: cover; border-radius: 10px; border: 1px solid var(--border); background: var(--panel-2); }

        .btn {
            display: inline-block; background: var(--gold); color: #0A0A0A; padding: 9px 18px; border-radius: 8px;
            text-decoration: none; border: none; cursor: pointer; font-family: 'Montserrat', sans-serif;
            font-weight: 700; font-size: 0.75rem; letter-spacing: 1px; text-transform: uppercase;
        }
        .btn:hover { opacity: 0.9; }
        .btn--ghost { background: transparent; color: var(--ink); border: 1px solid var(--gold); padding: 7px 14px; }
        .btn--ghost:hover { background: var(--gold); color: #0A0A0A; opacity: 1; }
        .btn--danger { background: transparent; color: var(--danger); border: 1px solid var(--danger); padding: 7px 14px; }
        .btn--danger:hover { background: var(--danger); color: #0A0A0A; opacity: 1; }
        .btn--small { padding: 6px 12px; font-size: 0.68rem; }
        .btn--whatsapp { background: #25D366; color: #0A0A0A; border: none; text-align: center; }
        .btn--whatsapp:hover { background: #1fb855; opacity: 1; }
        .inline { display: inline; }

        .slot-list { list-style: none; display: flex; flex-direction: column; gap: 10px; }
        .slot-list li {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px;
        }
        .slot-main { display: flex; flex-direction: column; gap: 2px; }
        .slot-main strong { font-size: 0.95rem; }
        .slot-main span { color: var(--muted); font-size: 0.8rem; }
        .slot-main .gold { color: var(--gold); text-transform: uppercase; letter-spacing: 0.5px; font-size: 0.7rem; font-weight: 600; }
        .empty { color: var(--muted); font-size: 0.85rem; padding: 12px 4px; }

        .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 14px; padding: 24px; }
        .back { display: inline-block; margin-bottom: 16px; font-size: 0.8rem; }

        .slots { display: flex; flex-direction: column; gap: 8px; margin: 16px 0; max-height: 420px; overflow-y: auto; }
        .slot-option {
            display: block; padding: 12px 14px; background: var(--panel-2); border: 1px solid var(--border);
            border-radius: 8px; cursor: pointer; font-size: 0.9rem;
        }
        .slot-option:has(input:checked) { border-color: var(--gold); }
        .slot-option input { margin-right: 10px; }

        .login-card { max-width: 380px; margin: 60px auto; background: var(--panel); border: 1px solid var(--border); border-radius: 16px; padding: 36px 30px; }
        .login-logo { display: block; width: 190px; max-width: 100%; height: auto; margin: 0 auto 18px; }
        .login-card h1 { font-family: 'Montserrat', sans-serif; font-weight: 600; font-size: 2rem; margin-bottom: 20px; }
        .login-card label { display: flex; flex-direction: column; gap: 6px; font-size: 0.75rem; letter-spacing: 0.5px; text-transform: uppercase; color: var(--muted); margin-bottom: 14px; }
        .login-card input { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; color: var(--ink); padding: 11px 12px; font-family: 'Montserrat', sans-serif; font-size: 0.9rem; }
        .login-card input:focus { outline: none; border-color: var(--gold); }

        .artist-list { list-style: none; display: flex; flex-direction: column; gap: 10px; }
        .artist-list li {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            background: var(--panel-2); border: 1px solid var(--border); border-radius: 10px; padding: 14px 18px;
        }
        .artist-list li a {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            flex: 1; color: var(--ink);
        }
        .artist-list li a:hover { color: var(--gold); opacity: 1; }
        .artist-list .badge { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 3px 10px; font-size: 0.68rem; letter-spacing: 1px; text-transform: uppercase; color: var(--gold); }
        .tag { display: inline-block; margin-left: 6px; padding: 2px 8px; border-radius: 10px; font-size: 0.6rem; letter-spacing: 0.5px; text-transform: uppercase; background: var(--panel); color: var(--gold); border: 1px solid var(--gold); vertical-align: middle; }
        .tag-success { color: #4ade80; border-color: #4ade80; }
        .tag-muted { color: var(--muted); border-color: var(--border); }

        .actions-row { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; margin-top: 16px; }
        .form-field { display: flex; flex-direction: column; gap: 6px; }
        .form-field label { font-size: 0.72rem; letter-spacing: 1px; text-transform: uppercase; color: var(--muted); }
        .form-field input, .form-field select { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; color: var(--ink); padding: 10px 12px; font-family: 'Montserrat', sans-serif; font-size: 0.9rem; }
        .form-field input:focus, .form-field select:focus { outline: none; border-color: var(--gold); }

        .nav-toggle { display: none; background: none; border: none; color: var(--ink); font-size: 1.6rem; line-height: 1; cursor: pointer; padding: 4px 8px; }
        .nav-toggle:hover { color: var(--gold); }

        @media (max-width: 900px) {
            .sidebar {
                width: 274px; flex-basis: 274px;
                transform: translateX(-100%);
                transition: transform .22s ease;
                box-shadow: 0 0 40px rgba(0,0,0,0.65);
            }
            .sidebar.open { transform: translateX(0); }
            .app-main { margin-left: 0; }
            .mobile-bar {
                display: flex; align-items: center; gap: 10px;
                position: sticky; top: 0; z-index: 30;
                padding: 10px 14px; border-bottom: 1px solid var(--border);
                background: rgba(10,10,10,0.88); backdrop-filter: blur(6px);
            }
            .mobile-bar .brand { font-size: 1.15rem; }
            .mobile-bar .nav-toggle { display: block; }
            .backdrop.open { display: block; position: fixed; inset: 0; z-index: 35; background: rgba(0,0,0,0.62); }
            body.nav-open { overflow: hidden; }
        }

        @media (max-width: 768px) {
            main { padding: 20px 16px 48px; }
            h1.page-title, .panel h1 { font-size: 1.5rem; margin-bottom: 14px; }
            h2.section-title { font-size: 1.15rem; margin: 22px 0 12px; }
            .panel { padding: 16px; }
            .board { grid-template-columns: 1fr; gap: 12px; }
            .week-grid { grid-template-columns: repeat(7, minmax(118px, 1fr)); }
            .stat-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-card { padding: 14px; }
            .stat-value { font-size: 1.4rem; }
            .detail-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .slot-list li { flex-direction: column; align-items: stretch; }
            .artist-list li { flex-direction: column; align-items: stretch; }
            .artist-list li a { flex-direction: column; align-items: flex-start; gap: 4px; }
            .promo-row { flex-direction: column; align-items: flex-start; }
            .note-form { flex-wrap: wrap; }
            .login-card { margin: 30px auto; padding: 26px 20px; }
            .ref-grid img { width: 96px; height: 96px; }
        }
    </style>
    <script>
        // Apply the saved sidebar state before paint to avoid a flash.
        try {
            if (localStorage.getItem('crm.sidebar.collapsed') === '1') {
                document.documentElement.classList.add('is-collapsed');
            }
        } catch (e) {}
    </script>
</head>
<body>
    @auth
        @php($navUser = auth()->user())

        <div class="app-shell">
            <aside class="sidebar" id="sidebar">
                <div class="sidebar-head">
                    <a class="brand" href="{{ $navUser->isArtist() ? route('dashboard') : route('artists.index') }}">VAIF <span>CRM</span></a>
                    <button type="button" class="collapse-btn" id="collapseBtn" aria-label="Recolher menu" title="Recolher menu">
                        <x-icon name="chevrons-left" />
                    </button>
                </div>

                <nav class="side-nav">
                    @if ($navUser->isArtist())
                        <div class="nav-group">
                            <span class="nav-group-label">Painel</span>
                            <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'is-active' : '' }}">
                                <x-icon name="grid" /> <span>Dashboard</span>
                            </a>
                            <a href="{{ route('bookings.index') }}" class="nav-item {{ request()->routeIs('bookings.*') ? 'is-active' : '' }}">
                                <x-icon name="calendar" /> <span>Agenda</span>
                            </a>
                            <a href="{{ route('analytics.index') }}" class="nav-item {{ request()->routeIs('analytics.*') ? 'is-active' : '' }}">
                                <x-icon name="chart" /> <span>Análises</span>
                            </a>
                        </div>

                        <div class="nav-group">
                            <span class="nav-group-label">Conta</span>
                            <a href="{{ route('settings.index') }}" class="nav-item {{ request()->routeIs('settings.*') ? 'is-active' : '' }}">
                                <x-icon name="settings" /> <span>Configuração</span>
                            </a>
                            <a href="{{ config('crm.member_area_url') }}" target="_blank" rel="noopener noreferrer" class="nav-item">
                                <x-icon name="external" /> <span>Área do Membro</span>
                                <span class="nav-ext">&#8599;</span>
                            </a>
                        </div>
                    @else
                        <div class="nav-group">
                            <span class="nav-group-label">Equipe VAIF</span>
                            <a href="{{ route('artists.index') }}" class="nav-item {{ request()->routeIs('artists.*') ? 'is-active' : '' }}">
                                <x-icon name="users" /> <span>Artistas</span>
                            </a>
                        </div>

                        @if (session('staff_artist_id') && $navArtist)
                            <div class="nav-group">
                                <span class="nav-group-label">{{ $navArtist->displayName() }}</span>
                                <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'is-active' : '' }}">
                                    <x-icon name="grid" /> <span>Dashboard</span>
                                </a>
                                <a href="{{ route('bookings.index') }}" class="nav-item {{ request()->routeIs('bookings.*') ? 'is-active' : '' }}">
                                    <x-icon name="calendar" /> <span>Agenda</span>
                                </a>
                                <a href="{{ route('analytics.index') }}" class="nav-item {{ request()->routeIs('analytics.*') ? 'is-active' : '' }}">
                                    <x-icon name="chart" /> <span>Análises</span>
                                </a>
                                <a href="{{ route('settings.index') }}" class="nav-item {{ request()->routeIs('settings.*') ? 'is-active' : '' }}">
                                    <x-icon name="settings" /> <span>Configuração</span>
                                </a>
                                <a href="{{ route('artists.index') }}" class="nav-item">
                                    <x-icon name="switch" /> <span>Trocar artista</span>
                                </a>
                            </div>
                        @endif
                    @endif
                </nav>

                <div class="sidebar-foot">
                    <div class="who">
                        <span class="who-avatar" title="{{ $navArtist?->displayName() ?: $navUser->name }}">{{ mb_substr($navArtist?->displayName() ?: $navUser->name, 0, 1) }}</span>
                        <span class="who-name">{{ $navUser->name }}</span>
                        <span class="who-role">{{ $navUser->isArtist() ? 'Artista' : 'Equipe VAIF' }}</span>
                        @if (! $navUser->isArtist() && $navArtist)
                            <span class="who-context">Visualizando: {{ $navArtist->displayName() }}</span>
                        @endif
                    </div>
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="logout-btn">
                            <x-icon name="logout" /> <span>Sair</span>
                        </button>
                    </form>
                </div>
            </aside>

            <div class="backdrop" id="backdrop"></div>

            <div class="app-main">
                <div class="mobile-bar">
                    <button class="nav-toggle" id="navToggle" aria-label="Abrir menu" aria-expanded="false" aria-controls="sidebar">&#9776;</button>
                    <a class="brand" href="{{ $navUser->isArtist() ? route('dashboard') : route('artists.index') }}">VAIF <span>CRM</span></a>
                </div>

                <main>
                    @if (session('status'))
                        <div class="flash">{{ session('status') }}</div>
                    @endif
                    @yield('content')
                </main>
            </div>
        </div>

        <script>
            var navToggle = document.getElementById('navToggle');
            var sidebar = document.getElementById('sidebar');
            var backdrop = document.getElementById('backdrop');

            function setNav(open) {
                if (!sidebar) return;
                sidebar.classList.toggle('open', open);
                backdrop.classList.toggle('open', open);
                document.body.classList.toggle('nav-open', open);
                navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }

            if (navToggle && sidebar) {
                navToggle.addEventListener('click', function () {
                    setNav(!sidebar.classList.contains('open'));
                });
            }
            if (backdrop) {
                backdrop.addEventListener('click', function () { setNav(false); });
            }
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') setNav(false);
            });

            // ---- Collapse / expand the sidebar (desktop) ----
            var collapseBtn = document.getElementById('collapseBtn');

            function syncCollapse() {
                var collapsed = document.documentElement.classList.contains('is-collapsed');

                if (collapseBtn) {
                    collapseBtn.setAttribute('aria-label', collapsed ? 'Expandir menu' : 'Recolher menu');
                    collapseBtn.setAttribute('title', collapsed ? 'Expandir menu' : 'Recolher menu');
                }

                if (sidebar) {
                    sidebar.querySelectorAll('.nav-item').forEach(function (el) {
                        var label = el.querySelector('span');
                        if (!label) return;
                        if (collapsed) el.setAttribute('title', label.textContent.trim());
                        else el.removeAttribute('title');
                    });
                }
            }

            syncCollapse();

            if (collapseBtn) {
                collapseBtn.addEventListener('click', function () {
                    var collapsed = document.documentElement.classList.toggle('is-collapsed');
                    try { localStorage.setItem('crm.sidebar.collapsed', collapsed ? '1' : '0'); } catch (e) {}
                    syncCollapse();
                });
            }
        </script>
    @else
        <main>
            @if (session('status'))
                <div class="flash">{{ session('status') }}</div>
            @endif
            @yield('content')
        </main>
    @endauth

    @stack('scripts')
</body>
</html>
