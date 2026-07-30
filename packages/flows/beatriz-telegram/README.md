# Beatriz — Telegram + Postgres v2

Production SDR agent workflow for the Telegram testbed, with full Postgres persistence.

**Workflow ID**: `JyNwRrqonIEExg2m` (n8n.vaif.com.br)
**Status**: Active

## Architecture

```
Telegram Trigger → Set Artist Context → Upsert Lead (create-or-load)
  → AI Agent (GPT-4o-mini, Postgres Chat Memory) → Parse & Extract Fields
  → Update Lead → Log Event → Send Telegram Reply
```

Sub-nodes:
- **OpenRouter Chat Model**: gpt-4o-mini
- **Postgres Chat Memory**: keyed by `lead_id`, auto-trimmed

## Database operations

| Node | Operation |
|------|-----------|
| Set Artist Context | `SELECT set_artist_context('b0000000-...')` |
| Upsert Lead | CTE: SELECT existing OR INSERT new (`pipeline_status='novo'`) |
| Update Lead | Parameterized UPDATE: qualification fields, pipeline status, pricing |
| Log Event | INSERT INTO events on state transitions |

## Field extraction

The `Parse & Extract Fields` Code node uses pattern matching:
- **Placement**: body-part keywords (braço, costas, etc.)
- **Body zone**: pequeno, médio, grande, fechamento
- **Style**: realismo, old school, blackwork, etc.
- **First tattoo**: sim/não patterns
- **Price**: R$ regex in Beatriz's response
- **Handoff**: cover-up detection, artist requests, Beatriz patterns

## Pipeline transitions

```
novo → qualificando → orcamento_enviado → aguardando_deposito
       (first qual)   (price detected)
                              → aguardando_artista (handoff)
```

## Deploy

```bash
# Build the JSON definition from source
python3 packages/flows/definitions/build-workflow.py

# Create or update the workflow
python3 packages/flows/scripts/deploy.py create packages/flows/definitions/beatriz-telegram.json
python3 packages/flows/scripts/deploy.py update JyNwRrqonIEExg2m packages/flows/definitions/beatriz-telegram.json

# Activate
python3 packages/flows/scripts/deploy.py activate JyNwRrqonIEExg2m
```

## Testing

```bash
# Unit tests for the field extraction logic
node packages/flows/definitions/code/test-parse-extract.js
```

## Test scripts

Run the 10 conversation fixtures from `packages/flows/test/conversation-fixtures.md` via the Telegram bot. Check Beatriz's responses against the contract and verify:
- Leads appear in the `leads` table with correct `pipeline_status`
- Qualification fields are written
- Events are logged on transitions
- Postgres Chat Memory persists conversation by `lead_id`
