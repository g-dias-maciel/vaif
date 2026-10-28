# Google Calendar per Artist

The CRM mirrors tattoo bookings to each Artist's Google Calendar. It uses **one
Google service account** instead of OAuth per Artist: the Artist shares their
calendar with the service account's e-mail address, and the service account
writes the events.

This avoids Google's OAuth verification for sensitive calendar scopes and keeps
one credential for the whole company instead of one refresh token per Artist.

## What is synced

- Active bookings inside the window (`-30 days` to `+730 days`, configurable in
  `config/crm.php`) become events titled `Tatuagem — <cliente>`.
- Reschedules update the same Google event.
- Cancellations delete the Google event.
- Bookings created by Beatriz through `book_slot` are picked up too, because the
  sync reconciles `calendar_entries.google_sync_pending` every minute.
- Manual blocks are **not** mirrored, and personal Google events are **not**
  imported into the CRM (that would be a two-way phase).

The scheduler runs `php artisan calendar:sync-google` every minute. Pending
bookings can also be pushed from **Configuração → Google Agenda → Sincronizar
agora**.

## 1. Create the Google Cloud credentials

1. Open <https://console.cloud.google.com> and create (or select) a project,
   for example `VAIF CRM`.
2. Go to **APIs & Services → Library**, search for **Google Calendar API** and
   click **Enable**.
3. Go to **IAM & Admin → Service Accounts → Create service account**.
   - Name: `vaif-calendar`
   - Roles: none (the calendar sharing is the permission).
4. Open the new service account → **Keys → Add key → Create new key → JSON**.
   Download the JSON file. It contains `client_email` and `private_key`.
5. Copy the `client_email`, for example
   `vaif-calendar@vaif-crm.iam.gserviceaccount.com`. This is the address each
   Artist will share their calendar with.

## 2. Configure the CRM

Encode the JSON as a single line (recommended for Coolify env vars):

```bash
base64 -w0 vaif-crm-xxxx.json    # Linux
base64 -i vaif-crm-xxxx.json     # macOS
```

In Coolify → the CRM application → **Environment Variables**, add:

```
GOOGLE_SERVICE_ACCOUNT_JSON=<the base64 output>
```

Raw JSON and a file path are also accepted, but base64 avoids newline problems.

Redeploy or restart the container. `php artisan config:cache` runs on boot, so
the new value is picked up.

## 3. Share the Artist's calendar

Each Artist does this once, in Google Calendar:

1. **Settings → Settings for my calendars**.
2. Select the calendar that should receive the tattoos.
3. **Share with specific people → Add people**.
4. Paste the service-account e-mail.
5. Permission: **Make changes to events**.
6. Save.

The Artist then opens **CRM → Configuração → Google Agenda**:

- Click **Detectar agendas compartilhadas** and pick the calendar, or
- paste the **Calendar ID** from Google Calendar → Settings → **Integrate
  calendar** (for example `artist@gmail.com` or
  `abc123@group.calendar.google.com`).

Connected calendars show the calendar name, ID and timezone. Use
**Sincronizar agora** to push the waiting bookings immediately.

## 4. Verify

```bash
php artisan calendar:sync-google --artist=<artist-uuid>
```

Then open the shared calendar. The event contains the client name, phone,
placement/style and a link back to the lead in the CRM. Changing the booking
time or cancelling it in the CRM updates/deletes the Google event on the next
run.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `Google Agenda não configurada no servidor` | `GOOGLE_SERVICE_ACCOUNT_JSON` is empty or config was not rebuilt. |
| `Credenciais do Google Agenda inválidas` | Bad JSON / missing `client_email` or `private_key`. Recreate the key. |
| `Google Calendar API has not been used in project…` | Calendar API is not enabled in the Google Cloud project. |
| `Not Found` / `404` when connecting | Wrong Calendar ID, or the calendar is not shared with the service account. |
| `Forbidden` / `403` | Sharing permission is not **Make changes to events**, or the Workspace domain blocks sharing outside the organization. |
| Detect list is empty | Google does not always list shared calendars immediately. Wait a minute or paste the Calendar ID directly. |
| Event organizer shows the service account | Expected with this model. OAuth per Artist is required if the Artist must be the organizer. |

## Security notes

- The JSON key grants access to every calendar shared with that service
  account. Keep it only in Coolify secrets; never commit it.
- Rotate the key in Google Cloud and update `GOOGLE_SERVICE_ACCOUNT_JSON` if it
  is exposed.
- The token scope is limited to `calendar.events` + `calendar.readonly`.
- Disconnecting an Artist removes the local link but leaves already-created
  Google events in place.
