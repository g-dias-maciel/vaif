#!/usr/bin/env bash
#
# Concurrency regression test: book_slot must not double-book the same window
# when two enquiries arrive at the same time.
#
# Before the fix, book_slot was a check-then-insert with no locking, so two
# concurrent bookings for the same slot both passed the overlap check and both
# inserted a 'booked' calendar row. The fix takes a per-artist advisory
# transaction lock (pg_advisory_xact_lock) before the overlap check, serializing
# concurrent bookings for the same artist.
#
# This test reproduces the race deterministically:
#   * Session A opens a transaction, books a slot, and holds it open (uncommitted).
#   * Session B (a different connection) books the same slot while A is open.
#     Under READ COMMITTED, B's overlap check cannot see A's uncommitted row —
#     exactly the race window. With the advisory lock, B blocks until A commits,
#     then its overlap check sees A's row and rejects.
#
# Run against the same DB as test_crud_functions.sql, e.g.:
#   PSQL='docker exec -i supabase_db_crm psql -U postgres -d postgres' ./test_concurrent_booking.sh
#
# Exit 0 on pass, 1 on fail.

set -euo pipefail

PSQL=${PSQL:-"docker exec -i supabase_db_crm psql -U postgres -d postgres"}

# Bruno (seed artist id, stable across the repo's seed data)
AID="b0000000-0000-0000-0000-000000000001"
SLOT="2027-01-15 15:00:00+00"          # fixed far-future instant
PHONE_A="5599999993101"
PHONE_B="5599999993102"

# Helpers
q()  { $PSQL -tAq -c "$1"; }
qq() { $PSQL -q -c "$1" >/dev/null; }

# Clean slate for this slot + these two test phones.
qq "DELETE FROM calendar WHERE start_at = '$SLOT'::timestamptz;"
qq "DELETE FROM events WHERE lead_id IN (SELECT id FROM leads WHERE telefone IN ('$PHONE_A','$PHONE_B'));"
qq "DELETE FROM leads WHERE telefone IN ('$PHONE_A','$PHONE_B');"

A=$(q "WITH ins AS (INSERT INTO leads (artist_id, telefone, pipeline_status) VALUES ('$AID','$PHONE_A','novo') RETURNING id) SELECT id FROM ins;")
B=$(q "WITH ins AS (INSERT INTO leads (artist_id, telefone, pipeline_status) VALUES ('$AID','$PHONE_B','novo') RETURNING id) SELECT id FROM ins;")

if [ -z "$A" ] || [ -z "$B" ]; then
  echo "FAIL: could not create test leads"
  exit 1
fi

# Session A: book the slot and hold the transaction open (uncommitted) briefly.
( $PSQL -q -c "BEGIN; SELECT book_slot('$A'::uuid, '$SLOT'::timestamptz, 60, 30); SELECT pg_sleep(2); COMMIT;" >/dev/null 2>&1 ) &
BG_PID=$!
sleep 1

# Session B: attempt the same slot while A is uncommitted.
B_COUNT=$(q "SELECT count(*) FROM book_slot('$B'::uuid, '$SLOT'::timestamptz, 60, 30);")

wait "$BG_PID"

BOOKED_COUNT=$(q "SELECT count(*) FROM calendar WHERE start_at = '$SLOT'::timestamptz AND type = 'booked';")

# Cleanup
qq "DELETE FROM calendar WHERE start_at = '$SLOT'::timestamptz;"
qq "DELETE FROM events WHERE lead_id IN (SELECT id FROM leads WHERE telefone IN ('$PHONE_A','$PHONE_B'));"
qq "DELETE FROM leads WHERE telefone IN ('$PHONE_A','$PHONE_B');"

echo "session B booked rows: $B_COUNT (expect 0)"
echo "booked rows for slot:  $BOOKED_COUNT (expect 1)"

if [ "$B_COUNT" = "0" ] && [ "$BOOKED_COUNT" = "1" ]; then
  echo "PASS: no double booking under concurrency"
  exit 0
else
  echo "FAIL: double booking detected (B=$B_COUNT, booked=$BOOKED_COUNT)"
  exit 1
fi
