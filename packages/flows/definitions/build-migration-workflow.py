#!/usr/bin/env python3
"""Build the DB Migration workflow JSON definition.

Splits 001_idempotent_schema.sql into individual statements,
each in its own Postgres node chained sequentially.
n8n Postgres nodes only support one statement per executeQuery call.
"""
import json
import os
import re

BASE = os.path.dirname(os.path.abspath(__file__))
MIGRATIONS_DIR = os.path.join(
    os.path.dirname(os.path.dirname(BASE)),
    "crm", "supabase", "migrations"
)

sql_path = os.path.join(MIGRATIONS_DIR, "001_idempotent_schema.sql")
with open(sql_path) as f:
    raw = f.read()

def split_sql(text):
    """Split SQL text into individual statements.

    Handles:
    - PL/pgSQL $$ function bodies ($$ is a toggle)
    - Comments and blank lines are bundled with their preceding statement
    - Returns list of statement strings
    """
    statements = []
    current = []
    in_dollar = False  # inside a $$ function body

    for line in text.split("\n"):
        stripped = line.strip()

        # Toggle $$ state
        dollar_count = stripped.count("$$")
        for _ in range(dollar_count):
            in_dollar = not in_dollar

        current.append(line)

        if not in_dollar and stripped.endswith(";"):
            statements.append("\n".join(current))
            current = []

    # Flush any trailing lines (shouldn't happen for valid SQL)
    if current:
        remaining = "\n".join(current).strip()
        if remaining:
            statements.append(remaining)

    # Filter out pure comment/blank "statements"
    result = []
    for s in statements:
        # Strip leading comments and blank lines
        lines = s.split("\n")
        while lines and (lines[0].strip().startswith("--") or lines[0].strip() == ""):
            lines.pop(0)
        body = "\n".join(lines).strip()
        if body:
            result.append(body)

    return result

statements = split_sql(raw)

CREDS = {"postgres": {"id": "nngaQDfXHYQ1Q43P", "name": "main-db"}}

def short_name(idx, stmt):
    """Derive a short node name from the statement's first keyword."""
    first = stmt.strip().split("\n")[0].strip()
    first = first.replace("CREATE OR REPLACE FUNCTION ", "FN_")
    first = first.replace("CREATE TABLE IF NOT EXISTS ", "TBL_")
    first = first.replace("CREATE INDEX IF NOT EXISTS ", "IDX_")
    first = first.replace("INSERT INTO ", "SEED_")
    # Trim to function/table name
    first = re.split(r"[\s(]", first)[0]
    return f"Mig_{idx:02d}_{first}"[:32]

nodes = [
    {
        "parameters": {
            "httpMethod": "POST",
            "path": "db-migration",
            "responseMode": "lastNode",
            "options": {},
        },
        "type": "n8n-nodes-base.webhook",
        "typeVersion": 1,
        "position": [0, 0],
        "name": "Webhook Trigger",
    }
]

connections = {}
prev = "Webhook Trigger"

for i, stmt in enumerate(statements):
    name = short_name(i, stmt)
    col = i % 5
    row = i // 5
    node = {
        "parameters": {
            "operation": "executeQuery",
            "query": stmt.strip(),
        },
        "type": "n8n-nodes-base.postgres",
        "typeVersion": 2.6,
        "position": [260 + col * 260, row * 100],
        "name": name,
        "credentials": CREDS,
    }
    nodes.append(node)
    connections[prev] = {"main": [[{"node": name, "type": "main", "index": 0}]]}
    prev = name

WF = {
    "name": "DB Migration (001_idempotent_schema)",
    "nodes": nodes,
    "connections": connections,
    "settings": {"executionOrder": "v1"},
}

out_path = os.path.join(BASE, "migration-beatriz.json")
with open(out_path, "w", encoding="utf-8") as f:
    json.dump(WF, f, indent=2, ensure_ascii=False)
print(f"Wrote {out_path} — {len(statements)} SQL statements in {len(nodes)} nodes")
