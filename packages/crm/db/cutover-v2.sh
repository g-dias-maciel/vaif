#!/usr/bin/env bash
# =============================================================================
# VAIF CRM — v1 → v2 cutover (database stages)
#
# Builds the fresh `vaif_crm` database, migrates the v1 tenant config, creates
# the login roles and verifies the result. It does NOT touch the CRM container
# or n8n: repointing DATABASE_URL happens in Coolify after this succeeds.
#
# The v1 database (`postgres` by default) is left untouched, and a full dump is
# taken before anything is dropped, so rollback is a restore.
#
# Run on the Coolify host, from a checkout of this repo:
#
#   CONFIRM=yes \
#   CRM_APP_PASSWORD='...' AGENT_PASSWORD='...' ANALYTICS_PASSWORD='...' \
#   ./packages/crm/db/cutover-v2.sh
#
# Stages:
#   1. pg_dump the v1 database
#   2. drop/create the target database
#   3. apply 0001, 0002, 0003 and the v2 contract tests
#   4. copy artists/settings/price_book from v1
#   5. create the login roles
#   6. verify counts and grants
#
# After the app image is deployed with the v2 code, run the second stage:
#
#   STAGE=app CONFIRM=yes ./packages/crm/db/cutover-v2.sh
#
# which runs Laravel migrations (users/notes) against the target database and
# copies the v1 users/notes.
# =============================================================================
set -euo pipefail

CONFIRM="${CONFIRM:-}"
STAGE="${STAGE:-db}"
PG_CONTAINER="${PG_CONTAINER:-}"
APP_CONTAINER="${APP_CONTAINER:-}"
SOURCE_DB="${SOURCE_DB:-postgres}"
TARGET_DB="${TARGET_DB:-vaif_crm}"
DB_USER="${DB_USER:-postgres}"
BACKUP_DIR="${BACKUP_DIR:-/tmp}"
MIGRATIONS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

say()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
fail() { printf '\033[31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$CONFIRM" = "yes" ] || fail "set CONFIRM=yes — this rebuilds the $TARGET_DB database"
case "$STAGE" in db|app) ;; *) fail "STAGE must be db or app" ;; esac

# --- locate containers -------------------------------------------------------
if [ -z "$PG_CONTAINER" ]; then
  PG_CONTAINER="$(docker ps --format '{{.ID}} {{.Image}}' \
    | awk '$2 ~ /^postgres:17/ {print $1; exit}')"
fi
[ -n "$PG_CONTAINER" ] || fail "no postgres:17 container found; set PG_CONTAINER"
say "postgres container: $PG_CONTAINER"

psql_v1()     { docker exec -i "$PG_CONTAINER" psql -U "$DB_USER" -d "$SOURCE_DB" "$@"; }
psql_target() { docker exec -i "$PG_CONTAINER" psql -U "$DB_USER" -d "$TARGET_DB" "$@"; }

# =============================================================================
# STAGE=app — after the v2 image is running (or from a v2 checkout)
# =============================================================================
if [ "$STAGE" = "app" ]; then
  if [ -z "$APP_CONTAINER" ]; then
    APP_CONTAINER="$(docker ps --format '{{.ID}} {{.Image}}' \
      | awk '$2 ~ /crm|fwkkkccgg8ko4sswg0ccw4ww/ {print $1; exit}')"
  fi
  [ -n "$APP_CONTAINER" ] || fail "no CRM container found; set APP_CONTAINER"
  say "CRM app container: $APP_CONTAINER"

  # Refuse to run the v1 migration set against the v2 database: the v2 image is
  # the one that has App\Models\Project (v1 had Lead/Pricing/CalendarSlot).
  docker exec "$APP_CONTAINER" test -f /app/app/Models/Project.php \
    || fail "container is not the v2 image; deploy it first"

  say "Laravel migrations (users, notes) against $TARGET_DB"
  # The schema owner runs DDL. By default use the owner credentials already in
  # the container; set OWNER_DB_PASSWORD (and optionally OWNER_DB_USER) if the
  # container has already been repointed at crm_app_login.
  env_args="-e DB_DATABASE=$TARGET_DB -e DB_USERNAME=${OWNER_DB_USER:-postgres}"
  if [ -n "${OWNER_DB_PASSWORD:-}" ]; then
    env_args="$env_args -e DB_PASSWORD=$OWNER_DB_PASSWORD"
  fi
  # shellcheck disable=SC2086
  docker exec $env_args "$APP_CONTAINER" \
    sh -lc 'php artisan config:clear >/dev/null && php artisan migrate --force --no-interaction'

  say "copying v1 users and notes"
  psql_target -v ON_ERROR_STOP=1 -q < "$MIGRATIONS_DIR/migrate/v1_to_v2_users.sql"

  say "done — users/notes migrated into $TARGET_DB"
  exit 0
fi

# =============================================================================
# STAGE=db
# =============================================================================
ts="$(date -u +%Y%m%dT%H%M%SZ)"
dump="$BACKUP_DIR/crm-v1-pre-cutover-$ts.dump"

say "1/6 dump $SOURCE_DB → $dump"
docker exec "$PG_CONTAINER" pg_dump -U "$DB_USER" -d "$SOURCE_DB" -Fc > "$dump"
[ -s "$dump" ] || fail "dump is empty"
echo "    $(du -h "$dump" | cut -f1) $dump"

say "2/6 recreate $TARGET_DB"
if docker exec "$PG_CONTAINER" psql -U "$DB_USER" -d "$SOURCE_DB" -Atc \
     "select 1 from pg_database where datname = '$TARGET_DB'" | grep -q 1; then
  if docker exec "$PG_CONTAINER" psql -U "$DB_USER" -d "$TARGET_DB" -Atc \
       "select to_regclass('public.users')" 2>/dev/null | grep -q users; then
    [ "${ALLOW_EXISTING:-}" = "yes" ] || \
      fail "$TARGET_DB already has app data; set ALLOW_EXISTING=yes to rebuild it"
  fi
fi
docker exec "$PG_CONTAINER" psql -U "$DB_USER" -d "$SOURCE_DB" -q \
  -c "drop database if exists $TARGET_DB"
docker exec "$PG_CONTAINER" createdb -U "$DB_USER" "$TARGET_DB"

say "3/6 apply migrations"
for f in 0001_schema_v2.sql 0002_functions_v2.sql 0003_roles_v2.sql 0004_active_booking.sql 0005_deposit_stage.sql 0006_tattoo_completed.sql; do
  echo "    $f"
  psql_target -v ON_ERROR_STOP=1 -q < "$MIGRATIONS_DIR/migrations/$f"
done
echo "    contract tests"
psql_target -v ON_ERROR_STOP=1 -q < "$MIGRATIONS_DIR/tests/v2_contract.sql"

say "4/6 migrate v1 tenants and price book"
psql_target -v ON_ERROR_STOP=1 -q < "$MIGRATIONS_DIR/migrate/v1_to_v2_tenants.sql"

say "5/6 login roles"
for role in crm_app_login agent_login analytics_login; do
  case "$role" in
    crm_app_login)   pw="${CRM_APP_PASSWORD:-}" ;;
    agent_login)     pw="${AGENT_PASSWORD:-}" ;;
    analytics_login) pw="${ANALYTICS_PASSWORD:-}" ;;
  esac
  if [ -z "$pw" ]; then
    pw="$(openssl rand -hex 24)"
    echo "    generated password for $role: $pw"
  fi
  psql_target -v ON_ERROR_STOP=1 -q -c "
    do \$\$
    begin
      if exists (select 1 from pg_roles where rolname = '$role') then
        alter role $role with login password '$pw';
      else
        execute format('create role %I login password %L in role %I',
                       '$role', '$pw',
                       case '$role'
                         when 'crm_app_login'   then 'crm_app'
                         when 'agent_login'     then 'agent_rw'
                         else 'analytics_ro'
                       end);
      end if;
    end
    \$\$;"
done

# n8n's memory node uses the unqualified `chat_memory`; resolve it to the
# agent-owned schema, not `public`.
psql_target -v ON_ERROR_STOP=1 -q -c "
  alter role agent_login set search_path = agent_memory, public;"

say "6/6 verify"
psql_target -Atc "
  select 'artists=' || (select count(*) from artists)
      || ' settings=' || (select count(*) from artist_settings)
      || ' prices=' || (select count(*) from price_book where effective_to is null)
      || ' tables=' || (select count(*) from pg_tables where schemaname = 'public')
      || ' functions=' || (select count(*) from pg_proc p
                            join pg_namespace n on n.oid = p.pronamespace
                           where n.nspname = 'public')"

psql_target -Atc "
  select 'crm_app_login can select leads=' ||
         has_table_privilege('crm_app_login', 'leads', 'select')"
psql_target -Atc "
  select 'agent_login can select leads=' ||
         has_table_privilege('agent_login', 'leads', 'select') ||
         ' can execute start_turn=' ||
         has_function_privilege('agent_login', 'start_turn(uuid,text,text,text,text)', 'execute') ||
         ' can execute reset_leads=' ||
         has_function_privilege('agent_login', 'reset_leads(text)', 'execute')
"

cat <<EOF

Cutover database stage complete.

Next:
  1. Deploy the v2 CRM image (commit ad0772f or later) in Coolify.
  2. Run:  STAGE=app CONFIRM=yes $0
  3. Point the CRM container at DB_DATABASE=$TARGET_DB with DB_USERNAME=crm_app_login.
  4. Point the Beatriz workflow credential at agent_login (admin workflows: crm_app_login).
  5. Deploy the workflow JSONs, run the smoke test.
  6. Keep $dump until the restore has been tested.

The v1 database '$SOURCE_DB' is untouched.
EOF
