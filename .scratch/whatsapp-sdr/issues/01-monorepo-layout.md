# Monorepo layout for VAIF projects

Type: grilling
Status: resolved
Blocked by: none

## Question

Should `/var/www/vaif` be the monorepo for all VAIF projects, and with what layout? Decide: the package structure (n8n workflows exported as JSON, docs/specs, the future CRM app), whether the PHP landing page at `/var/www/vaif-lp` migrates in or stays its own repo, and what "keeping an agentic context" means concretely (AGENTS.md, CONTEXT.md, .scratch/).

## Answer

Four decisions locked:

- **Monorepo, yes**: `/var/www/vaif` is the single repo for all VAIF products, planning docs, and infrastructure config.
- **Layout**: `packages/{crm,flows,infra,lp}/` for runnable products, `design/` for contracts + ADRs, `docs/` for agent tooling (existing), `.scratch/` for wayfinding (existing).
- **Landing page migrates in** as `packages/lp/` from its current separate `/var/www/vaif-lp` repo. Actual migration is part of "Repo + local-tracker setup" (02), now unblocked.
- **Agentic context stays simple**: single root `CONTEXT.md` with `CONTEXT-MAP.md` upgrade deferred until terms actually conflict across products; single root `AGENTS.md` with per-package supplements deferred until a package has its own test/lint commands; single `.scratch/` scoped by effort subdirectory.
