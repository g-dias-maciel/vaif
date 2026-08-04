# VAIF LP — Lucro Oculto Landing Page

## Project Overview
A multi-step lead generation funnel for "Lucro Oculto" — a financial diagnostic + free consultation booking aimed at high-end realism tattoo artists in Brazil.

**Funnel flow:** Hero → Calculator → Result (loss number) → Lead capture (name/WhatsApp/Insta) → Conditional:
- High-ticket (faturamento > R$ 7k) → Calendar booking for specialist call
- Low-ticket (≤ R$ 7k) → Ebook discount offer (coupon: `TATTOO10K`)

**Target audience:** Brazilian tattoo artists, realism/high-end, already earning 5-figures monthly.

---

## Tech Stack
| Layer | Technology |
|---|---|
| Frontend | Pure HTML + CSS + Vanilla JS (single file) |
| Backend | PHP 8+ (no framework) — 3 API files |
| Database | MySQL/MariaDB (via PDO) |
| Hosting | Coolify (env vars for DB + n8n) |
| Automation | **n8n** webhooks (replaced Make.com) |
| Analytics | **Matomo** (self-hosted at analytics.vaif.com.br) + **Facebook Pixel** (id: `752550821217294`) |
| Fonts | Cormorant Garamond (headings) + Montserrat (body) — Google Fonts |

## File Structure
```
/var/www/vaif-lp/
├── index.php                  # Single-page app (HTML + CSS + JS) ~1320 lines
├── api/leads/
│   ├── submit.php             # Lead capture POST — inserts DB + fires n8n webhook
│   ├── get_horarios.php       # GET — returns occupied calendar datetimes
│   └── update_agendamento.php # POST — saves selected calendar slot, fires n8n
├── .claude/
│   └── settings.local.json
└── .git/
```

---

## Funnel Flow (Detailed)

### Step 1: Hero + Calculator (lines 695–797)
- Hero with CTA → scrolls to calculator
- 4 fields: monthly revenue, ticket/session, sessions/month, hours/day on admin
- Submit → hides calculator, shows result

### Step 2: Result (lines 799–936)
- Shows calculated monthly loss (`R$ X`) with animated counter
- "Promessa" box shows potential revenue after 70% recovery
- **Locked action** area (dashed pattern with lock icon)
- Personalized curiosity text injects the user's loss number dynamically
- Lead form: Nome, WhatsApp (BR mask `(99) 99999-9999`), Instagram

### Step 3: Conditional Post-Submit (lines 1238–1265)
| Condition | Action |
|---|---|
| `faturamento > 7000` | Show `#nativeCalendarBlock` — 2-day grid (today/tomorrow) with slots at 10:00, 14:00, 17:00 BRT. Confirm → saves to DB + n8n webhook |
| `faturamento <= 7000` | Show `#ebookBlock` — premium box with coupon code `TATTOO10K`, links to `https://ebook.vaif.com.br/tatuador-10k` |

### Step 4: Success (lines 1287–1305)
- `#successMessage` shown with personalized text based on whether they booked or skipped

---

## Calculation Logic (lines 976–1022)
```js
valor_hora = faturamento / (sessoes * 8)         // assumes 8h per session
horas_secretario_mes = horas_admin * 26           // 26 working days
prejuizo_mensal = horas_secretario_mes * valor_hora
potencial_lucro = faturamento + (prejuizo_mensal * 0.7)  // 70% recovery
```

**Tracking events:**
- `handleCalculate()` → Matomo: `Calculadora / Clique_Calcular / Viu_o_Prejuizo`
- `confirmarAgendamento()` → Facebook `Schedule` + Matomo event
- `pularAgendamento()` → Facebook `ScheduleSkippedToWhatsapp` + Matomo event
- `trackEbookClick()` → Facebook `InitiateCheckout` + Matomo event

---

## Backend API

### `POST /api/leads/submit.php`
- Receives JSON with all calc data + lead info
- Inserts into `leads` table
- Forwards to n8n webhook (`N8N_LEAD_WEBHOOK_URL`) — non-blocking, 5s timeout
- Returns `{ success: true }` or `{ success: false, error: "..." }`

### `GET /api/leads/get_horarios.php`
- Returns all non-null `data_agendamento` values as array
- Returns `{ success: true, ocupados: ["2026-07-02 10:00:00", ...] }`
- On DB error returns `{ success: false, ocupados: [] }` (graceful degradation)

### `POST /api/leads/update_agendamento.php`
- Receives `{ whatsapp, data_agendamento }`
- Updates lead's `data_agendamento` field (matched by whatsapp, latest id)
- Fetches lead name + instagram, forwards to n8n (`N8N_CALENDAR_WEBHOOK_URL`)
- Returns `{ success: true }`

---

## Database (`leads` table columns)
`id`, `nome`, `whatsapp`, `instagram`, `faturamento`, `ticket`, `sessoes`, `horas_admin`, `valor_hora`, `horas_secretario`, `prejuizo_mensal`, `potencial_lucro`, `data_agendamento`, `created_at`

---

## Calendar Booking System (lines 1049–1204)
- Timezone: `America/Sao_Paulo` (BRT)
- Slots per day: `10:00`, `14:00`, `17:00`
- Shows 2 days at a time (today/tomorrow, or next available window)
- `encontrarProximaJanelaDisponivel()` scans forward up to 60 days for the first 2-day window with at least 1 free slot
- "Lotado" / "Encerrado" status tags on disabled slots
- `formatarParaBanco()` creates `YYYY-MM-DD HH:MM:00` format for DB comparison
- Selected slot gets `.selected` class (gold fill)

---

## Environment Variables (Coolify)
| Variable | Description |
|---|---|
| `DB_HOST` | MySQL host |
| `DB_NAME` | Database name |
| `DB_USER` | Database user |
| `DB_PASSWORD` | Database password |
| `N8N_LEAD_WEBHOOK_URL` | n8n webhook for new lead notifications (replaced Make.com) |
| `N8N_CALENDAR_WEBHOOK_URL` | n8n webhook for calendar booking confirmations |

---

## Analytics

### Facebook Pixel (lines 11-21)
- ID: `752550821217294`
- Tracks: `PageView`, `Schedule`, `InitiateCheckout`, `ScheduleSkippedToWhatsapp`

### Matomo (lines 22-34)
- Self-hosted at `//analytics.vaif.com.br/`
- Site ID: 1
- Events tracked: calculator CTA, calendar confirmation, ebook click, schedule skip

---

## Git Info
- **Default branch:** `main`
- **Remote:** `origin` → `https://github.com/g-dias-maciel/vaif-lp`
- **Recent merges:** PR #1 and PR #2 from `dev` branch into `main`
- **Branches:** `main` (prod) + `dev` (staging, occasionally merged)

---

## Design System

### Colors
```css
--gold: #D4B04C;
--bg-dark: #0A0A0A;
--bg-card: #121212;
--text-main: rgb(242, 237, 228);   /* Off-white for headers */
--text-muted: rgb(160, 154, 142);  /* Neutral for paragraphs */
--border-color: #222222;
```

### Typography
- **Headings:** Cormorant Garamond, serif (600 weight)
- **Body/Buttons:** Montserrat, sans-serif (300-700 weight, small caps for CTAs)
- **Style:** Luxury/premium aesthetic, gold accents, dark theme with radial gradient background

### Key UI Elements
- Diamond-shaped gold divider (`◆`)
- Fade-in-up scroll animations with staggered delays
- Hero background image from CloudFront CDN
- CTA buttons: gold fill, uppercase, letter-spaced
- Locked action area with repeating dashed pattern + gradient fade overlay
- Progress bar (0% → 50% → 80% → 100%) across funnel steps
- Calendar grid with time-slot selectors
- Success state with checkmark icon and centered card

---

## Key Technical Details

### Phone Mask
WhatsApp auto-formats to `(99) 99999-9999`, max 11 digits (DDD + 9 digits). Min 10 digits validated before submit.

### Instagram Input
Strips `@` and spaces on input (prefix shown visually in input wrapper).

### Number Parsing
`parseBrNumber()` handles Brazilian format: removes dots (thousands separator), replaces comma with decimal.

### Progress Bar States
| State | Width | Label |
|---|---|---|
| Initial (page load) | 50% | "Passo 1 de 2: Diagnóstico Inicial (50%)" |
| After calculator submit | 80% | "Passo 2 de 2: Liberação do Plano Estratégico (80%)" |
| After lead submit (ebook path) | 100% | "Processo Concluído (100%)" |
| After lead submit (calendar path) | 100% | "Processo Concluído (100%)" |

### Error Handling
- **Loading:** Button text changes to "Analisando perfil..." / "Agendando...", button disabled
- **Validation:** Alert + field focus
- **API error:** Alert, button resets
- **Network error:** Alert with generic message
- **Calendar API failure:** Falls back to empty occupied list (never blocks the UI)
- **DB error:** Error message returned in JSON

---

## Mandatory Implementation Rules

### Test-First Development (TDD)
You **MUST** always write tests **before** implementing any feature, fix, or refactor. This is non-negotiable.

1. **Understand the requirement** — clarify what needs to happen
2. **Write the test** — define the expected behavior with a failing test
3. **Implement** — write the minimum code to make the test pass
4. **Verify** — confirm the test passes
5. **Refactor** — clean up while keeping tests green

This applies to:
- New features
- Bug fixes
- Logic changes
- API endpoint modifications
- Any change that alters observable behavior

**Rationale:** Catches regressions early, documents expected behavior, and ensures the implementation is actually correct before shipping.

---

## Common Tasks

### Running locally
```bash
php -S localhost:8000
```

### Adding a new env var
Add to Coolify dashboard, reference via `getenv()` in the PHP file.

### Modifying calculation logic
Edit the JS inside `handleCalculate()` function (line 976 in `index.php`).

### Adding a calendar slot time
Edit the `slots` array in `encontrarProximaJanelaDisponivel()` and `gerarDiasCalendario()` (lines 1070, 1119).

### Adding a new form field
1. Add HTML input in the calculator or lead form
2. Include in `payload` object in JS
3. Add DB column + update SQL in submit.php

---

## Agent skills

### Issue tracker

Issues and PRDs live as GitHub issues — skills use the `gh` CLI (reads from `origin` remote automatically). See `docs/agents/issue-tracker.md`.

### Domain docs

Single-context repo — one `CONTEXT.md` + `docs/adr/` at the repo root. See `docs/agents/domain.md`.
