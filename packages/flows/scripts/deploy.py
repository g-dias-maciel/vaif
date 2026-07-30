#!/usr/bin/env python3
"""n8n workflow deployment utility.

Usage:
  python3 scripts/deploy.py create definitions/<name>.json
  python3 scripts/deploy.py update <workflow_id> definitions/<name>.json
  python3 scripts/deploy.py activate <workflow_id>
  python3 scripts/deploy.py deactivate <workflow_id>
  python3 scripts/deploy.py list
"""

import json
import os
import sys
import urllib.request
import urllib.error

N8N_API = "https://n8n.vaif.com.br/api/v1"
API_KEY = os.environ.get("N8N_API_KEY", "")

if not API_KEY:
    API_KEY_PATH = os.path.expanduser("~/.n8n-api-key")
    if os.path.exists(API_KEY_PATH):
        with open(API_KEY_PATH) as f:
            API_KEY = f.read().strip()

def request(method, path, data=None):
    url = f"{N8N_API}{path}"
    body = json.dumps(data).encode() if data else None
    req = urllib.request.Request(url, data=body, method=method)
    req.add_header("X-N8N-API-KEY", API_KEY)
    req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req) as resp:
            return json.loads(resp.read())
    except urllib.error.HTTPError as e:
        error_body = e.read().decode()
        print(f"HTTP {e.code}: {error_body}", file=sys.stderr)
        sys.exit(1)
def _clean_payload(wf):
    """Strip auto-generated fields and build a clean API payload."""
    for node in wf.get("nodes", []):
        for field in ["id", "webhookId", "onError", "notes"]:
            node.pop(field, None)

    payload = {}
    for key in ["name", "nodes", "connections", "settings"]:
        if key in wf:
            payload[key] = wf[key]
    if "description" in wf:
        payload["description"] = wf["description"]
    return payload


def create_workflow(def_path):
    with open(def_path) as f:
        wf = json.load(f)

    payload = _clean_payload(wf)
    result = request("POST", "/workflows", payload)
    wf_id = result.get("id", "unknown")
    print(f"Created: {result.get('name')} (ID: {wf_id})")
    return wf_id


def update_workflow(wf_id, def_path):
    with open(def_path) as f:
        wf = json.load(f)

    payload = _clean_payload(wf)
    result = request("PUT", f"/workflows/{wf_id}", payload)
    print(f"Updated: {result.get('name')} (ID: {wf_id})")

def activate(wf_id):
    request("POST", f"/workflows/{wf_id}/activate", {})
    print(f"Activated: {wf_id}")

def deactivate(wf_id):
    request("POST", f"/workflows/{wf_id}/deactivate", {})
    print(f"Deactivated: {wf_id}")

def list_workflows():
    result = request("GET", "/workflows?limit=50")
    for w in result.get("data", []):
        active = "ACTIVE" if w.get("active") else "inactive"
        arc = " [ARCHIVED]" if w.get("isArchived") else ""
        print(f"  {w['id']}  {active:8s}  {w['name']}{arc}")

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: deploy.py <create|update|activate|deactivate|list> [args...]")
        sys.exit(1)

    cmd = sys.argv[1]

    if cmd == "create" and len(sys.argv) >= 3:
        create_workflow(sys.argv[2])
    elif cmd == "update" and len(sys.argv) >= 4:
        update_workflow(sys.argv[2], sys.argv[3])
    elif cmd == "activate" and len(sys.argv) >= 3:
        activate(sys.argv[2])
    elif cmd == "deactivate" and len(sys.argv) >= 3:
        deactivate(sys.argv[2])
    elif cmd == "list":
        list_workflows()
    else:
        print("Usage: deploy.py <create|update|activate|deactivate|list> [args...]")
        sys.exit(1)
