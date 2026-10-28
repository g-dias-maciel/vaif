#!/usr/bin/env bash
# =============================================================================
# VAIF CRM — nightly logical backup (v2)
#
# Dumps `vaif_crm` in custom format plus the cluster roles (the login roles and
# their SCRAM hashes are not part of a database dump). Keeps 30 days by default.
#
# Install on the Coolify host (cron runs it as root):
#
#   install -m 700 backup-v2.sh /opt/vaif-crm/backup-v2.sh
#   cat >/etc/cron.d/vaif-crm-backup <<'CRON'
#   15 3 * * * root /opt/vaif-crm/backup-v2.sh >> /var/log/vaif-crm-backup.log 2>&1
#   CRON
#
# Env knobs:
#   PG_CONTAINER     postgres:17 container (auto-detected)
#   DB               default vaif_crm
#   DEST             default /var/backups/vaif-crm
#   RETENTION_DAYS   default 30
#
# A dump on the same host does not survive losing the host. Add an off-host
# copy (rsync/S3/rclone) once the destination is chosen.
# =============================================================================
set -euo pipefail

DB="${DB:-vaif_crm}"
DEST="${DEST:-/var/backups/vaif-crm}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
PG_CONTAINER="${PG_CONTAINER:-}"

log() { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
fail() { log "ERROR: $*" >&2; exit 1; }

[ -n "$PG_CONTAINER" ] || PG_CONTAINER="$(docker ps --format '{{.ID}} {{.Image}}' \
  | awk '$2 ~ /^postgres:17/ {print $1; exit}')"
[ -n "$PG_CONTAINER" ] || fail "no postgres:17 container found; set PG_CONTAINER"

umask 077
mkdir -p "$DEST"

ts="$(date -u +%Y%m%dT%H%M%SZ)"
dump="$DEST/vaif_crm-$ts.dump"
roles="$DEST/roles-$ts.sql"
part="$DEST/.vaif_crm-$ts.dump.part"

log "dumping $DB from $PG_CONTAINER → $dump"
docker exec "$PG_CONTAINER" pg_dump -U postgres -d "$DB" -Fc > "$part"
[ -s "$part" ] || fail "pg_dump produced an empty file"

# Cheap integrity check: the archive must be listable. pg_restore needs a
# seekable file, so copy it into the container first.
docker cp "$part" "$PG_CONTAINER:/tmp/vaif-backup-check.dump" >/dev/null
docker exec "$PG_CONTAINER" pg_restore -l /tmp/vaif-backup-check.dump >/dev/null \
  || { docker exec "$PG_CONTAINER" rm -f /tmp/vaif-backup-check.dump; fail "pg_restore cannot read the new dump"; }
docker exec "$PG_CONTAINER" rm -f /tmp/vaif-backup-check.dump

mv "$part" "$dump"
sha256sum "$dump" > "$dump.sha256"

log "dumping cluster roles → $roles"
docker exec "$PG_CONTAINER" pg_dumpall -U postgres --roles-only > "$roles"

# Retention: only this directory, only our filenames.
find "$DEST" -maxdepth 1 -type f -name '.vaif_crm-*.part' -mtime +1 -delete
find "$DEST" -maxdepth 1 -type f \
  \( -name 'vaif_crm-*.dump' -o -name 'vaif_crm-*.dump.sha256' \
     -o -name 'roles-*.sql' \) \
  -mtime +"$RETENTION_DAYS" -print -delete | while read -r f; do log "pruned $f"; done

log "done: $(du -h "$dump" | cut -f1) $dump"
