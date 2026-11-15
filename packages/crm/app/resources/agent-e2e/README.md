# End-to-end fake conversations

These are multi-turn WhatsApp conversations with synthetic leads. They run
through the real pipeline:

- `start_turn` / `build_turn_facts` / `apply_grounded_turn_once` in Postgres
- the real preflight classifier
- the real Beatriz model
- the real prompt, claim extractor, validator and repair loop
- the real handoff silence (`agent_blocked`)

Only the transport is fake: an `ArrayTransport` records what would be sent and
never calls WAHA. Only the vision result is scripted (`media.analysis`), so a
photo case does not depend on a camera image.

## Safety

`agent:e2e` creates a synthetic artist, lead, project and messages, then
**rolls the whole transaction back**. Nothing stays in the CRM. Do not remove
the transaction: the command must never leave test data behind.

## Run it

Against the deployed container:

```bash
docker exec <crm-container> php artisan agent:e2e --dir=resources/agent-e2e
```

One conversation:

```bash
docker exec <crm-container> php artisan agent:e2e \
  --scenario=resources/agent-e2e/02-cover-up-photo-handoff.json
```

Reports are written to `storage/app/agent-e2e/`. The command exits non-zero
when a conversation fails, so it can gate a deploy.

## Scenario shape

```json
{
  "name": "cover-up-photo-handoff",
  "lead_name": "Grazi",
  "turns": [
    {
      "message": "Quero cobrir essa tatuagem, segue a foto",
      "media": {
        "analysis": {"papel": "tatuagem_atual", "assunto": "tatuagem antiga"}
      },
      "expect": {
        "sent": true,
        "flags": {"cover_photo_received": true},
        "requires": ["handoff", "no_price", "no_video", "no_photo_request"]
      }
    },
    {
      "message": "Oi, tudo bem?",
      "expect": {"sent": false, "skipped": "agent_blocked"}
    }
  ]
}
```

Per-turn expectations:

- `intent`, `resolution`, `skipped` / `skipped_in`, `sent`, `ok`
- `flags` — e.g. `cover_photo_received`
- `reply_contains` / `reply_not_contains`
- `requires` — `safe_resolution`, `handoff`, `no_handoff`, `no_price`,
  `no_video`, `no_photo_request`, `no_known_question`, `closed_new_tattoo`,
  `ignored`
- `max_latency_ms`

`price_book` seeds the synthetic artist's price table. `settings` overrides
the default artist settings.
