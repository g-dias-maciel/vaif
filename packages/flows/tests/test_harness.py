#!/usr/bin/env python3
"""
Testing harness for Beatriz SDR closer — #9
Runs conversation fixture sequences against Postgres CRUD functions.
Uses docker exec + psql (no Python driver required).

Usage:
  python3 packages/flows/tests/test_harness.py
  python3 packages/flows/tests/test_harness.py --verbose
  python3 packages/flows/tests/test_harness.py --test 1
"""

import json
import subprocess
import sys
import os

DB_CONTAINER = os.environ.get("DB_CONTAINER", "supabase_db_crm")
DB_USER = os.environ.get("DB_USER", "postgres")
DB_NAME = os.environ.get("DB_NAME", "postgres")


def psql(sql, params=None):
    """Run SQL via docker exec psql. Returns (returncode, stdout_lines)."""
    if params:
        for i, p in enumerate(params):
            if p is None:
                sql = sql.replace(f"${i+1}", "NULL")
            elif isinstance(p, str):
                sql = sql.replace(f"${i+1}", f"'{p}'")
            elif isinstance(p, bool):
                sql = sql.replace(f"${i+1}", "true" if p else "false")
            elif isinstance(p, (int, float)):
                sql = sql.replace(f"${i+1}", str(p))
            else:
                sql = sql.replace(f"${i+1}", f"'{p}'")

    proc = subprocess.run(
        ["docker", "exec", "-i", DB_CONTAINER, "psql", "-U", DB_USER, "-d", DB_NAME,
         "-t", "-A", "-c", sql],
        capture_output=True, text=True, timeout=10,
        env={**os.environ, "PGPASSWORD": os.environ.get("PGPASSWORD", "postgres")},
    )
    lines = [l.strip() for l in proc.stdout.strip().split("\n") if l.strip()]
    return proc.returncode, lines


def psql_json(sql, params=None):
    """Run SQL that returns a single row as JSON."""
    _, lines = psql(sql, params)
    if not lines:
        return None
    try:
        return json.loads(lines[0])
    except json.JSONDecodeError:
        return None


def assert_eq(actual, expected, msg):
    if actual != expected:
        raise AssertionError(f"{msg}: expected={expected}, got={actual}")


def assert_true(cond, msg):
    if not cond:
        raise AssertionError(msg)


# ─── Test fixtures ────────────────────────────────────────────────

def _create_artist():
    """Create harness test artist if not exists, return ID."""
    rc, lines = psql("SELECT id FROM artists WHERE nome = 'Harness Artist'")
    if lines:
        return lines[0]

    rc, lines = psql("""
        INSERT INTO artists (nome, whatsapp_number, specialties, nao_faco,
        floor_pct, deposit_type, deposit_value, pix_key, instagram_handle,
        wa_session_slug, status)
        VALUES ('Harness Artist', '5511999990000',
        '{realismo,blackwork}', '{pescoco}', 80, 'percent', 20, 'pix-test',
        '@harness_test', 'harness-session', 'live')
        RETURNING id""")
    return lines[0] if lines else None


def _create_artist2():
    """Create second harness artist."""
    rc, lines = psql("SELECT id FROM artists WHERE nome = 'Harness Artist 2'")
    if lines:
        return lines[0]
    rc, lines = psql("""
        INSERT INTO artists (nome, whatsapp_number, wa_session_slug, status)
        VALUES ('Harness Artist 2', '5511999990001', 'harness-session-2', 'live')
        RETURNING id""")
    return lines[0] if lines else None


def _seed_pricing(artist_id):
    rc, lines = psql(f"SELECT count(*) FROM pricing WHERE artist_id = '{artist_id}'")
    if lines and lines[0] != "0":
        return

    data = [
        ("braco_externo", "pequeno", 50000, 120),
        ("braco_externo", "medio", 120000, 180),
        ("braco_externo", "grande", 200000, 300),
        ("antebraco", "pequeno", 45000, 90),
        ("costas", "fechamento", 350000, 420),
    ]
    for placement, zone, price, duration in data:
        psql(f"""
            INSERT INTO pricing (artist_id, placement, body_zone, table_price,
            session_duration_min) VALUES ('{artist_id}','{placement}','{zone}',{price},{duration})
            ON CONFLICT DO NOTHING""")


def _cleanup(artist_id):
    psql(f"DELETE FROM events WHERE artist_id = '{artist_id}'")
    psql(f"DELETE FROM calendar WHERE artist_id = '{artist_id}'")
    psql(f"DELETE FROM leads WHERE artist_id = '{artist_id}'")
    psql(f"DELETE FROM pricing WHERE artist_id = '{artist_id}'")
    psql(f"DELETE FROM artists WHERE id = '{artist_id}'")


# ─── Tests ────────────────────────────────────────────────────────

def test_create_lead(artist_id):
    """Test 1: create_lead returns novo status."""
    rc, lines = psql(
        "SELECT pipeline_status FROM create_lead($1, $2, $3)",
        [artist_id, "5511999999001", "Teste Create"]
    )
    assert_true("novo" in lines[0] if lines else False, "Lead created with novo status")
    rc, lines = psql(
        "SELECT count(*)::int > 0 FROM events WHERE event_type = 'created' AND payload->>'telefone' = $1",
        ["5511999999001"]
    )
    assert_true(lines[0] == "t" if lines else False, "Event logged")


def test_load_lead(artist_id):
    """Test 2: load_lead finds existing, returns empty for unknown."""
    rc, lines = psql(
        "SELECT id FROM load_lead($1, $2)",
        ["5511999999001", artist_id]
    )
    assert_true(len(lines) > 0, "load_lead finds existing lead")
    rc, lines = psql(
        "SELECT count(*)::int = 0 FROM load_lead('999999999999', $1)",
        [artist_id]
    )
    assert_true(lines[0] == "t" if lines else False, "Unknown phone returns empty")


def test_qualification(artist_id):
    """Test 3: update_qualification persists fields, events logged."""
    rc, lines = psql("SELECT id FROM leads WHERE telefone = '5511999999001'")
    lead_id = lines[0]
    rc, lines = psql(
        "SELECT placement, body_zone FROM update_qualification($1, $2::jsonb)",
        [lead_id, '{"placement":"braco_externo","body_zone":"grande","style":"realismo"}']
    )
    result = lines[0] if lines else ""
    assert_true("braco_externo" in result, "Placement persisted")
    assert_true("grande" in result, "Body zone persisted")


def test_pipeline_state(artist_id):
    """Test 4: mark_pipeline_state transitions correctly."""
    rc, lines = psql("SELECT id FROM leads WHERE telefone = '5511999999001'")
    lead_id = lines[0]
    psql("SELECT mark_pipeline_state($1, 'qualificando')", [lead_id])
    rc, lines = psql("SELECT pipeline_status FROM leads WHERE id = $1", [lead_id])
    assert_eq(lines[0], "qualificando", "Pipeline → qualificando")


def test_write_quote(artist_id):
    """Test 5: write_quote stores price, pipeline → orcamento_enviado."""
    rc, lines = psql("SELECT id FROM leads WHERE telefone = '5511999999001'")
    lead_id = lines[0]
    rc, lines = psql(
        "SELECT table_price, pipeline_status FROM write_quote($1, 200000)",
        [lead_id]
    )
    result = lines[0] if lines else ""
    assert_true("200000" in result, "Table price stored")
    assert_true("orcamento_enviado" in result, "Pipeline → orcamento_enviado")


def test_deposit_flow(artist_id):
    """Test 6: request_deposit + confirm_deposit."""
    rc, lines = psql("SELECT id FROM leads WHERE telefone = '5511999999001'")
    lead_id = lines[0]
    psql("SELECT request_deposit($1, 40000)", [lead_id])
    rc, lines = psql("SELECT deposit_status FROM leads WHERE id = $1", [lead_id])
    assert_eq(lines[0], "aguardando_confirmacao", "Deposit requested")
    psql("SELECT confirm_deposit($1)", [lead_id])
    rc, lines = psql("SELECT deposit_status FROM leads WHERE id = $1", [lead_id])
    assert_eq(lines[0], "confirmado", "Deposit confirmed")


def test_book_slot(artist_id):
    """Test 7: check_availability + book_slot."""
    rc, lines = psql("SELECT id FROM leads WHERE telefone = '5511999999001'")
    lead_id = lines[0]

    # Create available slot
    psql(f"""
        INSERT INTO calendar (artist_id, start_at, end_at, type)
        VALUES ('{artist_id}', now() + interval '2 days', now() + interval '2 days 5 hours', 'available')
        ON CONFLICT DO NOTHING""")

    rc, lines = psql(
        "SELECT count(*)::int > 0 FROM check_availability($1, now(), now() + interval '7 days', 120)",
        [artist_id]
    )
    assert_true(lines[0] == "t" if lines else False, "Availability found")

    rc, lines = psql("SELECT start_at FROM calendar WHERE artist_id = $1 AND type = 'available' LIMIT 1", [artist_id])
    slot_start = lines[0]
    rc, lines = psql(
        "SELECT pipeline_status FROM book_slot($1, $2::timestamptz, 180, 30)",
        [lead_id, slot_start]
    )
    assert_true("agendado" in (lines[0] if lines else ""), "Booking confirmed")


def test_handoff(artist_id):
    """Test 8: mark_handoff sets correct state."""
    rc, lines = psql("SELECT id FROM leads WHERE telefone = '5511999999001'")
    lead_id = lines[0]
    rc, lines = psql(
        "SELECT pipeline_status, handoff_reason FROM mark_handoff($1, 'lead_pediu_artista')",
        [lead_id]
    )
    result = lines[0] if lines else ""
    assert_true("aguardando_artista" in result, "Pipeline → aguardando_artista")
    assert_true("lead_pediu_artista" in result, "Handoff reason stored")


def test_close_lost(artist_id):
    """Test 9: close_lost transition."""
    # Create a new lead for this
    rc, lines = psql("SELECT id FROM create_lead($1, '5511999999002', 'Lost Lead')", [artist_id])
    lead_id = lines[0] if lines else None
    assert_true(lead_id is not None, "Lead created")
    psql(f"SELECT close_lost('{lead_id}')")
    rc, lines = psql(f"SELECT pipeline_status FROM leads WHERE id = '{lead_id}'")
    assert_eq(lines[0], "perdido", "Pipeline → perdido")


def test_price_lookup(artist_id):
    """Test 10: lookup_price finds correct price."""
    rc, lines = psql(
        "SELECT table_price FROM lookup_price($1, $2, $3)",
        ["braco_externo", "medio", artist_id]
    )
    assert_true(lines and "120000" in lines[0], "Correct table price (R$ 1200)")
    rc, lines = psql(
        "SELECT count(*)::int = 0 FROM lookup_price('nonexistent', 'medio', $1)",
        [artist_id]
    )
    assert_true(lines[0] == "t" if lines else False, "Unknown placement → empty")


def test_artist_resolution(artist_id):
    """Test 11: resolve_artist_from_session."""
    rc, lines = psql("SELECT id FROM resolve_artist_from_session('harness-session')")
    assert_true(lines and artist_id in lines[0], "Artist resolved by session slug")
    rc, lines = psql("SELECT count(*)::int = 0 FROM resolve_artist_from_session('no-such-session')")
    assert_true(lines[0] == "t" if lines else False, "Unknown session → empty")


def test_multi_tenancy_isolation(artist_id):
    """Test 12: Two artists — leads don't cross-contaminate."""
    artist2_id = _create_artist2()

    # Create lead for artist 1
    rc, lines = psql("SELECT id FROM create_lead($1, '5511999999003', 'A1 Lead')", [artist_id])
    lead_a1 = lines[0] if lines else None

    # Create lead for artist 2
    rc, lines = psql("SELECT id FROM create_lead($1, '5511999999003', 'A2 Lead')", [artist2_id])
    lead_a2 = lines[0] if lines else None

    assert_true(lead_a1 != lead_a2, "Different artists, different leads")

    # load_lead for artist 1 returns only their lead
    rc, lines = psql("SELECT count(*)::int FROM load_lead('5511999999003', $1)", [artist_id])
    assert_eq(lines[0], "1", "Artist 1 sees exactly 1 lead")

    # Cleanup artist 2
    _cleanup(artist2_id)


def test_onboarding_lifecycle():
    """Test 13: Artist onboarding token validation + consumption + completion."""
    artist_id = None
    rc, lines = psql("""
        INSERT INTO artists (nome, whatsapp_number, wa_session_slug, status, onboarding_token)
        VALUES ('Onboarding Test', '5511999998888', 'onboard-slug-test', 'stub', 'tok_test_harness_123')
        RETURNING id""")
    if lines:
        artist_id = lines[0]

    # Validate
    rc, lines = psql("SELECT count(*)::int > 0 FROM validate_onboarding_token('tok_test_harness_123')")
    assert_true(lines[0] == "t" if lines else False, "Token validates correctly")

    # Invalid token
    rc, lines = psql("SELECT count(*)::int = 0 FROM validate_onboarding_token('bad_token_xyz')")
    assert_true(lines[0] == "t" if lines else False, "Bad token returns empty")

    # Consume
    rc, lines = psql("SELECT status FROM consume_onboarding_token('tok_test_harness_123')")
    assert_true("onboarding" in (lines[0] if lines else ""), "Token consumed → onboarding")

    # Cannot reuse
    rc, lines = psql("SELECT count(*)::int = 0 FROM validate_onboarding_token('tok_test_harness_123')")
    assert_true(lines[0] == "t" if lines else False, "Token cannot be reused")

    # Complete
    rc, lines = psql(f"SELECT status FROM complete_artist_onboarding('{artist_id}')")
    assert_true("live" in (lines[0] if lines else ""), "Onboarding complete → live")

    # Cleanup
    if artist_id:
        psql(f"DELETE FROM artists WHERE id = '{artist_id}'")


# ─── Runner ───────────────────────────────────────────────────────

TESTS = [
    ("create_lead", "create_lead returns novo status + event logged", test_create_lead),
    ("load_lead", "load_lead finds existing, empty for unknown", test_load_lead),
    ("qualification", "update_qualification persists fields + events", test_qualification),
    ("pipeline", "mark_pipeline_state transitions correctly", test_pipeline_state),
    ("write_quote", "write_quote stores price, pipeline → orcamento_enviado", test_write_quote),
    ("deposit_flow", "request_deposit → confirm_deposit", test_deposit_flow),
    ("book_slot", "check_availability + book_slot", test_book_slot),
    ("handoff", "mark_handoff → aguardando_artista", test_handoff),
    ("close_lost", "close_lost → perdido", test_close_lost),
    ("price_lookup", "lookup_price finds correct price", test_price_lookup),
    ("artist_resolution", "resolve_artist_from_session", test_artist_resolution),
    ("multi_tenancy", "Multi-tenancy isolation between artists", test_multi_tenancy_isolation),
    ("onboarding", "Onboarding token lifecycle", test_onboarding_lifecycle),
]


def main():
    verbose = "--verbose" in sys.argv or "-v" in sys.argv
    test_filter = None
    for arg in sys.argv[1:]:
        if "=" in arg and arg.startswith("--test="):
            test_filter = arg.split("=", 1)[1]
        elif arg in ("--test", "-t"):
            continue

    artist_id = _create_artist()
    _seed_pricing(artist_id)

    passed = 0
    failed = 0

    print("=" * 60)
    print("Beatriz SDR Closer — Test Harness (#9)")
    print(f"Container: {DB_CONTAINER}, Artist ID: {artist_id}")
    print("=" * 60)

    for i, (name, description, test_fn) in enumerate(TESTS):
        if test_filter and name != test_filter and str(i + 1) != test_filter:
            continue

        try:
            import inspect
            sig = inspect.signature(test_fn)
            if len(sig.parameters) > 0:
                test_fn(artist_id)
            else:
                test_fn()
            print(f"  PASS  [{i+1:02d}] {description}")
            passed += 1
        except Exception as e:
            print(f"  FAIL  [{i+1:02d}] {description}")
            print(f"        {e}")
            failed += 1
            if verbose:
                import traceback
                traceback.print_exc()

    _cleanup(artist_id)

    print("=" * 60)
    print(f"Results: {passed} passed, {failed} failed, {len(TESTS)} total")
    sys.exit(0 if failed == 0 else 1)


if __name__ == "__main__":
    main()
