# Notion pipeline sync

The Notion **Pipeline** database is the source of truth for the existing client
base and for the historical lead backlog. The CRM ingests it in two ways:

| Path | Use |
|---|---|
| `php artisan sales:import-notion <file.csv>` | One-off bootstrap from a Notion CSV export; safe to re-run |
| `POST /api/sales/notion-sync` | Daily upsert from the n8n workflow (reuses the n8n Notion credential) |

Both paths use the same importer, so the routing rules are identical.

## Routing rule

| Notion row | CRM result |
|---|---|
| `Status Contrato = Ativo` | Artist record + active prospect in `ganho` |
| Any other status | Prospect in `backlog`, with `legacy_status` and flags |
| Pipeline `Cliente` but status not active | Backlog + `cliente_sem_status_ativo` |
| Status `Pausado` | Backlog + `cliente_pausado` |
| Duplicate phone | One canonical row, `duplicado_mesclado` |
| Invalid phone | Skipped; listed in the `--report` file |
| Existing suspended Artist | Linked; status stays `suspended`; flag `artista_suspenso` |
| Existing backlog prospect | Promoted to `ganho`; owner, notes and stage history preserved |

**No contracts are created** for imported clients. Link the commercial contract
manually in the CRM. **No logins or invites are created** for imported Artists;
the member area is released separately.

## CSV command

```bash
# Read and report without writing
php artisan sales:import-notion /path/to/pipeline.csv --dry-run --report=/tmp/notion.json

# Import
php artisan sales:import-notion /path/to/pipeline.csv --owner=sales@vaif.com.br

# Re-run safely; it updates instead of duplicating
php artisan sales:import-notion /path/to/pipeline.csv
```

The command dedupes by normalized WhatsApp. If several rows share a phone, it
prefers the row with `Status Contrato = Ativo`, then the most complete row, then
the latest `Last edited time`, filling gaps from the other rows.

## n8n payload

The n8n workflow owns the Notion property mapping. It should send flat pages,
not raw Notion property JSON:

```json
{
  "pages": [
    {
      "notion_page_id": "…",
      "name": "…",
      "whatsapp": "(11) 99999-9999",
      "instagram": "handle",
      "email": "artist@example.com",
      "status_contrato": "Ativo",
      "pipeline": "Cliente",
      "valor_fechamento": "R$1,800.00",
      "inicio_contrato": "2026-06-01",
      "proximo_contato": "10/11/2026",
      "last_edited_time": "2026-11-09T12:00:00Z",
      "address": "…"
    }
  ]
}
```

`notion_page_id` is optional, but recommended: it makes the upsert idempotent
even if the phone changes. The endpoint matches by page id, then by normalized
WhatsApp.

Authentication:

```http
Authorization: Bearer <NOTION_SYNC_TOKEN or SALES_INGEST_TOKEN>
Content-Type: application/json
```

Response:

```json
{
  "ok": true,
  "stats": {
    "processed": 10,
    "created_client": 1,
    "promoted_client": 0,
    "updated_client": 6,
    "created_backlog": 2,
    "updated_backlog": 1,
    "kept_active": 0,
    "skipped": 0,
    "failed": 0
  },
  "errors": []
}
```

## Data quality flags

| Flag | Meaning |
|---|---|
| `notion_cliente` | Active client from Notion |
| `notion_backlog` | Imported as backlog |
| `sem_nome` | No title; name fell back to Instagram/phone |
| `sem_email` | Active client without e-mail (needed for the future login) |
| `sem_valor_fechamento` | Active client without the closing value |
| `sem_instagram` | No Instagram handle |
| `cliente_sem_status_ativo` | Pipeline says Cliente, status is not Ativo |
| `cliente_pausado` | Status Contrato = Pausado |
| `pipeline_telefone_invalido` | Pipeline stage marks the phone as invalid |
| `duplicado_mesclado` | Several Notion rows collapsed into one phone |
| `artista_suspenso` | Matched an existing suspended Artist; not reactivated |
| `artista_existente` | Linked to an existing Artist |
| `promovido_do_backlog` | A backlog prospect was promoted to a client |
| `email_invalido` | E-mail failed validation and was dropped |

## Operational notes

- The importer never auto-churns a client. If a contract ends, mark it in the
  CRM; do not let a Notion status change silently end it.
- The 4 pre-existing Artists are `suspended`; importing an active client that
  matches one of them links the records but leaves the Artist suspended for a
  human decision.
- The Import is PII-heavy. Keep CSV exports and `--report` files out of git and
  delete them after use.
