# Coolify deploys for this monorepo

The repository builds several Coolify applications from `main`. Without path
filtering, every push rebuilds all of them, which is how the production server
ran out of memory on 2026-11-03.

## Applications

| App | Domain | Base directory | Watch paths |
|---|---|---|---|
| 7 | `vaif.com.br` | see the app's General tab | mirror the base directory |
| 8 | `dev.vaif.com.br` | `/packages/lp` | `packages/lp/**` (or manual only) |
| 12 | LP production | `/packages/lp` | `packages/lp/**` |
| 13 | `crm.vaif.com.br` | `/packages/crm/app` | `packages/crm/app/**` |

## Watch Paths rules

Set per application in **General → Build → Watch Paths**.

- One pattern per line. Commas do not separate patterns.
- Globs: `*` (stays inside a directory), `**` (crosses directories), `?`.
- `!pattern` excludes. Patterns are evaluated top to bottom and the **last
  matching pattern wins**, so negations must come after the inclusion.
- A leading `/` is stripped on save; paths are relative to the repository root.
- Only **webhook** deploys are filtered. The dashboard's Deploy/Redeploy and
  the deploy webhook always run.
- If no pattern matches, Coolify creates no deployment at all. A stale app with
  an empty deployment log usually means the pattern is wrong.

## Server limits

Server → **Advanced**:

- **Concurrent builds: 1.** The box has no swap; two parallel PHP/composer
  builds exhausted RAM and took down every container.
- **Deployment timeout:** leave at the default.

## Verifying a deploy

```sh
docker inspect <container> --format '{{.Config.Image}}'   # tag is the commit SHA
curl -sf https://crm.vaif.com.br/api/up                    # {"status":"ok"}
```
