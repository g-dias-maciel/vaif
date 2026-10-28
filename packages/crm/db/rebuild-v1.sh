#!/usr/bin/env bash
# =============================================================================
# Rebuild the v1 CRM database from the repo migrations.
#
# Destructive: drops schema public. Takes a full pg_dump first and rolls back
# automatically if the rebuild fails.
#
# Run this on the Coolify host (it talks to Docker), with this directory copied
# next to it:
#
#   scp -r packages/crm/db root@host:/tmp/vaif-rebuild
#   scp packages/crm/supabase/migrations/001_idempotent_schema.sql \
#       packages/crm/supabase/migrations/20261022000000_offers_and_chat_control.sql \
#       root@host:/tmp/vaif-rebuild/
#   cd /tmp/vaif-rebuild && CONFIRM=yes ./rebuild-v1.sh
#
# Env:
#   CONFIRM=yes          required, guards against accidental runs
#   PG_CONTAINER=...     default: first postgres container
#   APP_CONTAINER=...    default: first container whose name/image contains crm
#   DB_USER=postgres
#   DB_NAME=postgres
#   RESTORE_USERS=yes    preserve existing CRM logins (users table only)
#   SEED_DEMO=yes        load seed.sql (demo Artist "Bruno") after the rebuild
#   AUTO_ROLLBACK=yes    restore the backup if any step fails (default yes)
# =============================================================================
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# The SQL can sit next to this script (when copied to the host) or in the repo.
for candidate in "$DIR" "$DIR/../supabase/migrations" "$DIR/../../supabase/migrations"; do
  if [ -f "$candidate/001_idempotent_schema.sql" ]; then
    SUPABASE_DIR="$candidate"
    break
  fi
done
SUPABASE_DIR="${SUPABASE_DIR:-$DIR}"
SQL_MAIN="$SUPABASE_DIR/001_idempotent_schema.sql"
SQL_OFFERS="$SUPABASE_DIR/20261022000000_offers_and_chat_control.sql"

CONFIRM="${CONFIRM:-}"
DB_USER="${DB_USER:-postgres}"
DB_NAME="${DB_NAME:-postgres}"
RESTORE_USERS="${RESTORE_USERS:-yes}"
AUTO_ROLLBACK="${AUTO_ROLLBACK:-yes}"

log() { printf '[rebuild] %s\n' "$*"; }
die() { printf '[rebuild] ERROR: %s\n' "$*" >&2; exit 1; }

[ "$CONFIRM" = "yes" ] || die "Refusing to drop the database. Re-run with CONFIRM=yes."
[ -f "$SQL_MAIN" ] || die "missing $SQL_MAIN"
[ -f "$SQL_OFFERS" ] || die "missing $SQL_OFFERS"

command -v docker >/dev/null || die "docker not found; run this on the Coolify host"
timeout 10 docker info >/dev/null 2>&1 || die "cannot talk to the Docker daemon (try sudo)"

# Auto-detection is deliberately conservative: this host runs several Postgres
# containers (Coolify's own, n8n, ...) and picking the wrong one drops the wrong
# database. Only a container that actually has public.leads is the CRM.
if [ -z "${PG_CONTAINER:-}" ]; then
  for name in $(docker ps --format '{{.Names}}'); do
    image="$(docker inspect -f '{{.Config.Image}}' "$name" 2>/dev/null || true)"
    case "$image" in
      *postgres*) ;;
      *) continue ;;
    esac
    if docker exec "$name" psql -U "$DB_USER" -d "$DB_NAME" -Atc \
        "select to_regclass('public.leads') is not null" 2>/dev/null | grep -qx t; then
      PG_CONTAINER="$name"
      break
    fi
  done
fi
[ -n "${PG_CONTAINER:-}" ] || die "no postgres container has table public.leads; set PG_CONTAINER"

# Same for the app: prefer the container Coolify labels as the crm.vaif.com.br
# site, never a container that merely has 'crm' somewhere in its name.
if [ -z "${APP_CONTAINER:-}" ]; then
  for name in $(docker ps --format '{{.Names}}'); do
    if docker inspect -f '{{range $k, $v := .Config.Labels}}{{$k}}={{$v}}{{"\n"}}{{end}}' \
        "$name" 2>/dev/null | grep -q 'crm\.vaif\.com\.br'; then
      APP_CONTAINER="$name"
      break
    fi
  done
fi
[ -n "${APP_CONTAINER:-}" ] || log "WARNING: no crm container found; set APP_CONTAINER to run Laravel migrations"

TS="$(date +%Y%m%d-%H%M%S)"
BACKUP="/tmp/crm-pre-rebuild-$TS.dump"
USERS_DUMP="/tmp/crm-users-$TS.sql"

log "postgres container: $PG_CONTAINER"
log "app container:     ${APP_CONTAINER:-<none>}"

# --- 1. Full backup (data included) -----------------------------------------
log "backing up to $BACKUP"
docker exec "$PG_CONTAINER" pg_dump -U "$DB_USER" -d "$DB_NAME" -Fc > "$BACKUP"
[ -s "$BACKUP" ] || die "backup is empty; aborting before any change"
log "backup size: $(du -h "$BACKUP" | cut -f1)"

if [ "$RESTORE_USERS" = "yes" ]; then
  log "saving users table to $USERS_DUMP"
  docker exec "$PG_CONTAINER" pg_dump -U "$DB_USER" -d "$DB_NAME" \
    --data-only --table=public.users > "$USERS_DUMP" || log "WARNING: could not dump users; will skip restore"
fi

rollback() {
  [ "$AUTO_ROLLBACK" = "yes" ] || { log "AUTO_ROLLBACK=no, leaving database as-is"; return; }
  log "rolling back from $BACKUP"
  docker exec -i "$PG_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 -c \
    'select pg_terminate_backend(pid) from pg_stat_activity where datname = current_database() and pid <> pg_backend_pid();' >/dev/null 2>&1 || true
  docker exec -i "$PG_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 \
    -c 'drop schema public cascade; create schema public;' || true
  docker exec -i "$PG_CONTAINER" pg_restore -U "$DB_USER" -d "$DB_NAME" --no-owner --no-privileges \
    < "$BACKUP" || log "WARNING: rollback restore reported errors; inspect manually"
  [ -n "${APP_CONTAINER:-}" ] && docker start "$APP_CONTAINER" >/dev/null 2>&1 || true
  log "rollback finished; backup kept at $BACKUP"
}
trap 'log "failed at line $LINENO"; rollback' ERR

# --- 2. Stop the app so entrypoint migrations cannot race --------------------
if [ -n "${APP_CONTAINER:-}" ]; then
  log "stopping $APP_CONTAINER"
  docker stop "$APP_CONTAINER" >/dev/null
fi

# --- 3. Rebuild --------------------------------------------------------------
log "dropping schema public"
docker exec -i "$PG_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 <<'SQL'
-- n8n and pgAdmin hold idle connections to the same database; they would block
-- the DROP. They reconnect on their own.
select pg_terminate_backend(pid)
from pg_stat_activity
where datname = current_database() and pid <> pg_backend_pid();
drop schema public cascade;
create schema public;
alter schema public owner to current_user;
SQL

log "applying 001_idempotent_schema.sql"
docker exec -i "$PG_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 < "$SQL_MAIN"

log "applying 20261022000000_offers_and_chat_control.sql"
docker exec -i "$PG_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 < "$SQL_OFFERS"

if [ "${SEED_DEMO:-no}" = "yes" ] && [ -f "$SUPABASE_DIR/seed.sql" ]; then
  log "seeding demo Artist (Bruno)"
  docker exec -i "$PG_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 \
    < "$SUPABASE_DIR/seed.sql" || log "WARNING: demo seed failed"
fi

# --- 4. Start the app (entrypoint runs Laravel migrations) -------------------
if [ -n "${APP_CONTAINER:-}" ]; then
  log "starting $APP_CONTAINER"
  docker start "$APP_CONTAINER" >/dev/null

  log "waiting for the container healthcheck"
  for _ in $(seq 1 60); do
    status="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$APP_CONTAINER" 2>/dev/null || echo unknown)"
    [ "$status" = "healthy" ] && break
    [ "$status" = "none" ] && break
    sleep 2
  done
  log "healthcheck: $status"
fi

# --- 5. Restore logins (non-fatal) -------------------------------------------
if [ "$RESTORE_USERS" = "yes" ] && [ -s "$USERS_DUMP" ]; then
  log "restoring users"
  if docker exec -i "$PG_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 < "$USERS_DUMP"; then
    # The dump has explicit ids but no sequence value (--table selects only the
    # table), so advance users_id_seq past the restored rows.
    docker exec "$PG_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 -q -c \
      "select setval(pg_get_serial_sequence('users','id'), coalesce((select max(id) from users), 1))" >/dev/null
    log "users restored"
  else
    log "WARNING: users restore failed; create a login with: php artisan crm:make-staff you@example.com"
  fi
fi

trap - ERR

# --- 6. Verify ---------------------------------------------------------------
log "verifying"
docker exec "$PG_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 <<'SQL'
select 'artists' as check, count(*) as n from artists
union all select 'leads', count(*) from leads
union all select 'pricing', count(*) from pricing;

select count(*) as tenant_fks
from pg_constraint c
join pg_class t on t.oid = c.conrelid
where c.contype = 'f'
  and t.relname in ('leads','pricing','calendar','events')
  and pg_get_constraintdef(c.oid) like '%artists(id)%';

select count(*) as missing_columns
from information_schema.columns
where table_schema = 'public'
  and ((table_name = 'pricing' and column_name = 'region')
    or (table_name = 'artists' and column_name = 'pricing_extras'));
SQL

if [ -n "${APP_CONTAINER:-}" ]; then
  code="$(curl -s -o /dev/null -w '%{http_code}' https://crm.vaif.com.br/api/up || true)"
  log "https://crm.vaif.com.br/api/up -> $code"
fi

log "done. backup kept at $BACKUP"
if [ "${SEED_DEMO:-no}" = "yes" ]; then
  log "demo Artist: Bruno (b0000000-0000-0000-0000-000000000001)"
fi
if [ -n "${APP_CONTAINER:-}" ]; then
  log "create a staff login with: docker exec -w /app $APP_CONTAINER php artisan crm:make-staff you@example.com"
fi
