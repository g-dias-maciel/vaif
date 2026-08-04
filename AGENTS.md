## Agent skills

### Repo layout

Monorepo — `/var/www/vaif` holds all VAIF products and planning:

```
packages/    -- runnable products (crm, flows, infra, lp)
design/      -- contracts, ADRs, spec documents
docs/        -- agent tooling (issue-tracker, domain, triage-labels)
.scratch/    -- wayfinding maps, tickets, research
```

### Issue tracker

Issues and PRDs live as GitHub issues, managed via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default vocabulary — `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context — `CONTEXT.md` + `docs/adr/` at the repo root. See `docs/agents/domain.md`.
