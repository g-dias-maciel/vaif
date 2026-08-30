#!/usr/bin/env python3
"""Validate and normalize the Beatriz Telegram testbed workflow JSON.

The deployed n8n workflow ("Beatriz Telegram + Postgres") is the canonical
source of truth — edits happen in the n8n UI (or via deploy.py), and this
script keeps the checked-in `beatriz-telegram.json` in sync by:

  1. Loading the canonical JSON from the repo.
  2. Validating integrity (all connection targets and AI sub-node references
     exist, and every embedded jsCode block parses with `node --check`).
  3. Re-writing it normalized (indent=2, ensure_ascii=False).

There is intentionally NO generation logic here: n8n rewrites/normalizes
workflow JSON on save (prunes default parameters, converts node schemas,
shifts canvas positions), so a from-scratch generator drifts from what is
actually deployed. The exported JSON is the artifact to commit.

Deploy the canonical file with:
  python3 packages/flows/scripts/deploy.py update <workflow_id> packages/flows/definitions/beatriz-telegram.json
"""
import json
import os
import subprocess
import sys
import tempfile

BASE = os.path.dirname(os.path.abspath(__file__))
JSON_PATH = os.path.join(BASE, "beatriz-telegram.json")

with open(JSON_PATH, encoding="utf-8") as f:
    wf = json.load(f)

nodes = wf["nodes"]
names = {n["name"] for n in nodes}
failures = []

# ── 1. Connection targets exist ──
for src, targets in wf.get("connections", {}).items():
    if src not in names:
        failures.append(f"connection source '{src}' is not a node")
    for kind, branches in targets.items():
        for branch in branches:
            for conn in branch:
                if conn.get("node") not in names:
                    failures.append(f"connection {src}->{conn.get('node')} ({kind}) references missing node")

# ── 2. AI sub-node references exist ──
for kind in ("ai_languageModel", "ai_memory", "ai_tool", "ai_outputParser"):
    for src, targets in wf.get("connections", {}).items():
        for conn in targets.get(kind, [[]])[0]:
            if conn.get("node") not in names:
                failures.append(f"ai ref {src}->{conn.get('node')} ({kind}) references missing node")

# ── 3. Embedded jsCode parses ──
for n in nodes:
    js = n.get("parameters", {}).get("jsCode")
    if not js:
        continue
    with tempfile.NamedTemporaryFile("w", suffix=".js", delete=False) as tf:
        tf.write(js)
        tmp = tf.name
    r = subprocess.run(["node", "--check", tmp], capture_output=True, text=True)
    if r.returncode != 0:
        failures.append(f"node '{n['name']}' jsCode does not parse: {r.stderr.strip()[:200]}")
    os.unlink(tmp)

# ── 4. Credential references present (id + name) ──
for n in nodes:
    for cred_type, cred in (n.get("credentials") or {}).items():
        if not cred.get("id") or not cred.get("name"):
            failures.append(f"node '{n['name']}' credential '{cred_type}' missing id/name")

if failures:
    print("VALIDATION FAILED:")
    for f in failures:
        print("  -", f)
    sys.exit(1)

with open(JSON_PATH, "w", encoding="utf-8") as f:
    json.dump(wf, f, ensure_ascii=False, indent=2)
print(f"OK — {len(nodes)} nodes, connections valid, jsCode parses. Wrote {JSON_PATH}")