# Repo + local-tracker setup

Type: task
Status: resolved
Blocked by: none

## Question

Set up the repo skeleton per the "Monorepo layout for VAIF projects" decision: git init, AGENTS.md with the agent-skills block, `docs/agents/issue-tracker.md` recording the local-markdown tracker (`.scratch/`), and the package directories. This ticket *does* rather than decides — it earns its place by giving every later ticket and the final spec a home. The answer records what was created and any conventions later tickets depend on.

## Answer

Repo skeleton set up per the monorepo layout decision:

- **Git repo** — initialized at `/var/www/vaif`, remote origin `github.com/g-dias-maciel/vaif.git`.
- **AGENTS.md** — updated with repo layout section (`packages/`, `design/`, `docs/`, `.scratch/`), plus existing issue-tracker, triage-labels, and domain-docs blocks.
- **Package directories** created: `packages/{crm,flows,infra,lp}/`. Empty ones ready for future products.
- **Landing page migrated** from `/var/www/vaif-lp` into `packages/lp/`. PHP + CSS + JS + assets + API + tests. Duplicate agent docs and tooling files removed.
- **`design/` directory** created for contracts and ADRs.
- **Issue tracker doc** (`docs/agents/issue-tracker.md`) now records the local-markdown tracker conventions (`.scratch/`): file structure, ticket body format, blocking, claim, resolve, and frontier query.
- **README.md** updated to describe the monorepo and its layout.
- **CONTEXT.md** updated to note the monorepo scope.
- **`.gitignore`** created at root: node_modules, IDE/OS artifacts, .env, agent tooling (.agents/, .claude/). LP-specific `.gitignore` at `packages/lp/` covers the same set.
- **`docs/agents/triage-labels.md`** and `docs/agents/domain.md` exist from charting session — no changes needed.

Conventions later tickets depend on:
- All wayfinding work is in `.scratch/<effort>/` with the structure recorded in `issue-tracker.md`.
- Products live in `packages/<name>/`, each with its own `.gitignore` and agent docs (AGENTS.md/CLAUDE.md) as needed.
- Agent sessions read `AGENTS.md` at root for tooling instructions and repo layout.
