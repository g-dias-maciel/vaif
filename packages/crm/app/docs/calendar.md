# Team calendar

Staff (admin) users get an internal calendar at **Calendário** (`/calendar`).

## Sources

| Source | Behaviour |
|---|---|
| `team_events` | Meetings created in the CRM (sales, strategy, other) |
| `prospects.booked_call_at` | Sales calls already recorded on a prospect; read-only, links to the prospect |

Prospect calls are not duplicated into `team_events`. They are merged at render
time, so the calendar always reflects the current `booked_call_at`.

## Types

- **Vendas** (`sales`)
- **Estratégia** (`strategy`)
- **Outro** (`other`)

## Views and filters

- Month grid, Sunday–Saturday, with previous/next month and “Hoje”.
- Filters: month, type, responsible user, and free-text search (title, location,
  notes, prospect name/Instagram/phone).
- Month list with date, time, type, title, owner, location/meeting link and the
  edit/prospect action.
- Calls whose `booked_call_at` has no time (imported date-only rows) show
  “Horário a confirmar” instead of 00:00.

## Create and edit

**Novo compromisso** stores:

- title, type, start/end (`datetime-local`), responsible user;
- optional prospect link;
- location, meeting URL, notes.

Staff can edit or delete any team event. Sales users cannot access the calendar,
so strategic/other internal meetings stay admin-only. Artist appointments remain
in the artist-specific calendar.

## Data model

`team_events`: `id`, `title`, `type`, `starts_at`, `ends_at`, `location`,
`meeting_url`, `notes`, `owner_id`, `prospect_id`, timestamps. Deleting a user
reassigns their team events to the admin performing the deletion.
