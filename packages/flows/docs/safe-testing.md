# Safe WhatsApp testing

Never send a test message to a number that might belong to a real person.

## Webhook authentication

Every internal n8n webhook (`/webhook/waha-webhook`, `/webhook/reset-leads`,
`/webhook/calendar`, `/webhook/onboard-api`, `/webhook/promotions`) requires
the shared header:

```
X-VAIF-Webhook-Token: <value of the "VAIF Webhook Auth" n8n credential>
```

WAHA sends it automatically for each session (`customHeaders`). If you replay a
WAHA payload by hand, include the header or n8n returns `403 Authorization data
is wrong!`. The public lead forms (`/webhook/novo-lead`, `/webhook/diagnostico`,
`/webhook/agendamento-confirmado`) stay open by design and are rate limited.

## Rules

1. Use only numbers that are guaranteed not to exist on WhatsApp.
   - Preferred shape: `+55 11 0000-00000` (area code 11, subscriber starting
     with `0`, which is not a valid Brazilian mobile).
   - Examples: `+5511000000001`, `+5511000000002`.
2. Verify the number with WhatsApp before sending anything:

   ```bash
   WAHA_API_KEY=... python3 packages/flows/scripts/check-test-number.py 5511000000001
   ```

   The script exits `0` only when WhatsApp reports `numberExists: false`.
3. Never reuse a phone number or `@lid` taken from real traffic.
   - Do not use the `remoteJidAlt` of a real lead.
   - Do not fabricate an `@lid` either: WAHA may accept the send and deliver it
     if the identifier happens to exist.
4. Prefer `@s.whatsapp.net` (phone) payloads over `@lid` payloads in simulations,
   so a bad test send fails instead of reaching someone.
5. Delete the test lead, its chat session and its agent memory after the test:

   ```sql
   delete from agent_memory.chat_memory where session_id in (
     select id::text from leads where phone_e164 = '+5511000000001'
   );
   delete from leads where phone_e164 = '+5511000000001';
   ```

6. Restore the artist status (`suspended` unless the artist is live in
   production) and remove any media copied into the WAHA volume.

## Audio fixtures

Generate a voice note inside the WAHA container so the codec matches real
WhatsApp traffic:

```bash
ffmpeg -i /tmp/test-audio.mp3 -c:a libopus -b:a 32k /app/.media/teste/test-audio.oga
```

Then simulate the webhook with `mimetype: "audio/ogg; codecs=opus"` and the real
WAHA media host in `media.url` (the workflow rewrites the host).
