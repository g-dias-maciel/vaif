# Team management

Staff (admin) users can manage internal logins at **Equipe** (`/team`).

## Roles

| Role | Access |
|---|---|
| `staff` (Administrador) | Everything: Artists, sales, finance, campaigns, team, settings |
| `sales` (Vendas) | Internal sales CRM: pipeline, backlog, campaigns; no finance or team |
| `artist` | Artist-facing CRM only; managed from **Artistas**, never from **Equipe** |

The Team screen lists only `staff` and `sales` users. Artist logins are tied to
an `artist_id` and stay in the Artists area.

## Create and edit

- Name, e-mail, role, password and password confirmation.
- Passwords are hashed by the `User` model; the admin must share the initial
  password through a secure channel.
- On edit, leaving the password blank keeps the current one.
- The e-mail is normalized to lowercase and must be unique.

## Safeguards

- A user cannot demote or delete their own account.
- The system refuses to remove the last administrator.
- Deleting a sales user transfers their prospects (`prospects.owner_id`) to the
  admin performing the deletion, so no leads are orphaned.
- A Staff user cannot edit or delete an artist login from `/team`; use
  **Artistas → Editar** instead.
