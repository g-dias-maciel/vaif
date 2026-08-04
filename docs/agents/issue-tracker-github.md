# Issue tracker: GitHub Issues (`gh` CLI)

The canonical issue tracker lives at `g-dias-maciel/vaif` on GitHub. Local markdown (`.scratch/`) is retained for wayfinding maps, research notes, design contracts, and long-form PRDs — but tickets and their dependencies are tracked as GitHub Issues.

## Conventions

- **Create a ticket**: `gh issue create --title "<title>" --body "<body>" --label <label>`
- **Blocking edges**: List blocking issues in the issue body under `## Blocked by` using `#<number>` references. The author sets these manually — GitHub Issues has no native blocking relationship.
- **Triage labels**: All issues get one of: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. Create labels before first use: `gh label create <name> --description "..."`.
- **Agent-grabbable**: Issues with `ready-for-agent` are fully specified and can be picked up by an agent session. An agent resolves them by closing with a comment linking to the PR or committed changes.
- **PRD tracking**: A spec/PRD gets its own issue. Implementation tickets reference it in their body and link to the PRD's markdown file in `.scratch/`.
- **Frontier query**: `gh issue list --label ready-for-agent --state open --json number,title,body` — pick any issue whose `Blocked by` references are all closed.

## Local ↔ GitHub mapping

Local `.scratch/` files are the source of truth for long-form content (maps, research, design contracts, PRDs). GitHub Issues are the source of truth for actionable work (tickets, their status, their dependencies).

When using both:
- A wayfinding map lives in `.scratch/<effort>/map.md` and references GitHub issue numbers.
- Research and design docs stay in `.scratch/` — they aren't tickets.
- The PRD is a local markdown file; a GitHub issue is created as a reference point that links to it.
- Implementation tickets exist only as GitHub Issues, not as local `.md` files. (Pre-existing local ticket files are archived or deleted after migration.)
