## Task: Create an implementation plan for ticket #22

Read the ticket below, explore the codebase to understand current state,
and write a concrete implementation plan.

### Ticket
# Nginx routing for /blog and /artists

Labels: implementation

## Parent

[Build Spec: Blog + Artist Landing Pages for vaif-lp](https://github.com/g-dias-maciel/vaif/issues/20)

## What to build

Add custom Nginx configuration in Coolify so that requests to `/blog/*` and `/artists/*` route to PHP front-controllers instead of looking for matching files on disk.

## Acceptance criteria

- [ ] Requests to `/blog` and `/blog/<any-path>` reach `/blog/index.php`
- [ ] Requests to `/artists/<any-path>` reach `/artists/index.php`
- [ ] Existing pages (`/`, `/calculadora/`, `/onboard/*`, `/api/*`) continue to work unchanged
- [ ] Tested with `php -S` local server (which ignores Nginx config — verify routing works with a temp `.htaccess` or front-controller boot)

## Blocked by

None — can start immediately.

### Rules
- Output ONLY the plan — no implementation code
- For each change: what file(s), what change, why
- List changes in dependency order
- Flag any ambiguity or missing information
- Note which existing tests will need updating

### Output
Write the plan to `.workflow/tickets/22/plan.md`
