# Issue tracker: Local markdown (`.scratch/`)

Maps, research, design contracts, and PRDs live as markdown files under `.scratch/<effort>/`. **Implementation tickets are tracked on GitHub Issues** — see `docs/agents/issue-tracker-github.md` for the GitHub conventions.

## Conventions

An effort (wayfinding map) lives in `.scratch/<effort-name>/`:

```
.scratch/<effort-name>/
  map.md                -- the canonical map artifact
  issues/               -- child tickets, named NN-slug.md
    NN-slug.md
  research/             -- AFK research findings
  design/               -- contracts, ADRs, spec documents
```

- **Create a ticket**: write a `.md` file in `issues/` with a numeric prefix.
- **Ticket body convention**: YAML-style header for metadata, `## Question` for content.
  ```md
  # Title
  Type: <research|grilling|prototype|task>
  Status: <open|resolved>
  Blocked by: <NN> or none

  ## Question
  <body>
  ```
- **Blocking**: `Blocked by:` line in the header, comma-separated ticket numbers. A ticket is unblocked when every listed blocker has `Status: resolved`.
- **Claim**: no formal claim mechanism — pick only unblocked `Status: open` tickets not currently being worked in another session.
- **Resolve**: set `Status: resolved`, append `## Answer` section with one-line gist + link to any assets, then add a context pointer (gist + link) to the map's `## Decisions so far`.
- **Frontier query**: list open issues in `issues/`, drop any with unresolved `Blocked by` references; first in numeric order wins.
