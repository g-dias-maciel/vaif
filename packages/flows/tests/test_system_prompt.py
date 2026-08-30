#!/usr/bin/env python3
"""
Unit test for the dynamic Beatriz system prompt (#30).

The system prompt (prompts/beatriz-system.md) is a template filled at runtime
by the "Build System Prompt" node in the WhatsApp workflow. This test guards
against Bruno-specific values leaking back into the template and against the
runtime render leaving un-filled placeholders.

No DB required. Run: python3 packages/flows/tests/test_system_prompt.py
"""

import json
import os
import re
import sys

FLOWS_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEFS = os.path.join(FLOWS_ROOT, "definitions")

failures = []


def check(label, cond, detail=""):
    if cond:
        print(f"  PASS: {label}")
    else:
        failures.append(label)
        print(f"  FAIL: {label} {detail}")


def load_json(name):
    with open(os.path.join(DEFS, name)) as f:
        return json.load(f)


# ── 1. Template has the expected placeholders and no Bruno hardcodes ──
with open(os.path.join(FLOWS_ROOT, "prompts", "beatriz-system.md")) as f:
    template = f.read()

print("=== Template placeholders ===")
expected = ["{{NOME}}", "{{INSTAGRAM}}", "{{PIX}}", "{{SINAL}}", "{{PISO}}", "{{DESCONTO_MAX}}", "{{TABELA_PRECOS}}"]
for ph in expected:
    check(f"placeholder {ph} present", ph in template)

print("=== No Bruno-specific hardcodes ===")
check("no 'Bruno'", "Bruno" not in template)
check("no '@bruno.tattoo'", "@bruno.tattoo" not in template)
check("no 'bruno.tattoo@pix.com.br'", "bruno.tattoo@pix.com.br" not in template)
check("no '80%'", "80%" not in template)
check("no '20%'", "20%" not in template)
check("no '30%'", "30%" not in template)
check("no static 'Parcelado (6x)' column", "Parcelado (6x)" not in template)
check("no leftover placeholders besides expected",
      set(re.findall(r"\{\{[A-Z_]+\}\}", template)) == set(expected))

# ── 2. Runtime render fills every placeholder with artist data ──
print("=== Runtime render (Bruno seed data) ===")
artist = {
    "nome": "Bruno",
    "instagram_handle": "@bruno.tattoo",
    "pix_key": "bruno.tattoo@pix.com.br",
    "deposit_type": "percent",
    "deposit_value": "30",          # NUMERIC/INTEGER may arrive as strings
    "floor_pct": "80.00",           # NUMERIC may arrive as a string
}
pricing = [
    {"placement": "antebraco", "body_zone": "pequeno", "table_price": "30000"},
    {"placement": "costas", "body_zone": "fechamento", "table_price": "200000"},
]

def fmt_brl(cents):
    return f"R$ {float(cents)/100:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")

rows = "\n".join(f"| {p['placement']} | {p['body_zone']} | {fmt_brl(p['table_price'])} |" for p in pricing)
table = "| Local | Tamanho | À Vista |\n|---|---|---|\n" + rows
sinal = f"R$ {int(artist['deposit_value'])}" if artist["deposit_type"] == "fixed" else f"{int(artist['deposit_value'])}%"
piso = f"{int(float(artist['floor_pct']))}%"
desconto_max = f"{100 - int(float(artist['floor_pct']))}%"

repl = {
    "{{NOME}}": artist["nome"],
    "{{INSTAGRAM}}": artist["instagram_handle"],
    "{{PIX}}": artist["pix_key"],
    "{{SINAL}}": sinal,
    "{{PISO}}": piso,
    "{{DESCONTO_MAX}}": desconto_max,
    "{{TABELA_PRECOS}}": table,
}
rendered = template
for k, v in repl.items():
    rendered = rendered.replace(k, v)

check("no placeholders remain after render", not re.findall(r"\{\{[A-Z_]+\}\}", rendered))
check("artist name injected", "assistente do tatuador Bruno" in rendered)
check("PIX injected", "PIX: bruno.tattoo@pix.com.br" in rendered)
check("Instagram injected", "@bruno.tattoo" in rendered)
check("sinal derived from deposit_value", "Sinal: 30%" in rendered)
check("piso derived from floor_pct", "Piso negociação: 80%" in rendered)
check("max discount derived from floor_pct", "Desconto MÁXIMO: 20%" in rendered)
check("price table row rendered (R$ 300,00)", "| antebraco | pequeno | R$ 300,00 |" in rendered)
check("price table row rendered (R$ 2.000,00)", "| costas | fechamento | R$ 2.000,00 |" in rendered)
check("installment derivation note present", "6x = valor à vista / 6" in rendered)

# ── 3. WhatsApp workflow wires the dynamic prompt ──
print("=== Workflow wiring ===")
wf = load_json("beatriz-whatsapp-agent.json")
nodes = {n["name"]: n for n in wf["nodes"]}

agent = nodes["AI Agent"]
check("AI Agent systemMessage references Build System Prompt",
      agent["parameters"]["options"]["systemMessage"] == "={{ $('Build System Prompt').first().json.system_message }}")

bsp = nodes["Build System Prompt"]
check("Build System Prompt embeds the template",
      "assistente do tatuador {{NOME}}" in bsp["parameters"]["jsCode"])
check("Build System Prompt coerces NUMERIC strings",
      "Number(artist.floor_pct)" in bsp["parameters"]["jsCode"])

check("Artist Found? routes through Check AI Window",
      wf["connections"]["Artist Found?"]["main"][0][0]["node"] == "Check AI Window")
check("In AI Window? routes through Debounce Start",
      wf["connections"]["In AI Window?"]["main"][0][0]["node"] == "Debounce Start")
check("Clear Buffer → Upsert Lead → Load Pricing → Build System Prompt → AI Agent",
      wf["connections"]["Clear Buffer"]["main"][0][0]["node"] == "Upsert Lead"
      and wf["connections"]["Upsert Lead"]["main"][0][0]["node"] == "Load Pricing"
      and wf["connections"]["Load Pricing"]["main"][0][0]["node"] == "Build System Prompt"
      and wf["connections"]["Build System Prompt"]["main"][0][0]["node"] == "AI Agent")
check("AI Agent → Build Classification Prompt → Classify → Parse → Build Update Query → Update Lead",
      wf["connections"]["AI Agent"]["main"][0][0]["node"] == "Build Classification Prompt"
      and wf["connections"]["Build Classification Prompt"]["main"][0][0]["node"] == "Classify Conversation"
      and wf["connections"]["Classify Conversation"]["main"][0][0]["node"] == "Parse Classification"
      and wf["connections"]["Parse Classification"]["main"][0][0]["node"] == "Build Update Query"
      and wf["connections"]["Build Update Query"]["main"][0][0]["node"] == "Update Lead")
check("Log Event reads Build Update Query output (fixes events never logging)",
      "$('Build Update Query').first().json" in nodes["Log Event"]["parameters"]["options"]["queryReplacement"]
      and "$('Update Lead').first().json" not in nodes["Log Event"]["parameters"]["options"]["queryReplacement"])

# ── 4. Onboarding form captures the full agent config ──
print("=== Onboarding form fields ===")
form = load_json("artist-onboarding-form.json")
field_names = {f["fieldName"] for f in form["nodes"][0]["parameters"]["formFields"]["values"]}
for fname in ["timezone", "working_seg", "working_ter", "working_qua", "working_qui",
              "working_sex", "working_sab", "working_dom", "ai_active_start", "ai_active_end", "pricing_csv"]:
    check(f"form field {fname}", fname in field_names)

trigger = form["nodes"][0]
check("Form Trigger v2.4+ (output keyed by fieldName)",
      trigger["typeVersion"] >= 2.4)
check("Form Trigger responseMode is top-level",
      trigger["parameters"].get("responseMode") == "onReceived")
check("Form Trigger path lives in options (v2.2+ schema)",
      trigger["parameters"].get("options", {}).get("path") == "onboard")

form_nodes = {n["name"] for n in form["nodes"]}
check("form writes pricing rows", "INSERT Pricing" in form_nodes)
check("form builds pricing items", "Build Pricing Items" in form_nodes)

print("=== WAHA session body ===")
waha = next(n for n in form["nodes"] if n["name"] == "Create WAHA Session")
params = waha["parameters"]
check("WAHA session uses raw JSON body (specifyBody json)",
      params.get("sendBody") is True and params.get("specifyBody") == "json")
check("WAHA session config sent as object (webhooks array)",
      "webhooks" in params.get("jsonBody", "") and "JSON.stringify" in params.get("jsonBody", ""))
check("WAHA webhook path matches Beatriz node (waha-webhook)",
      "waha-webhook" in params.get("jsonBody", ""))
check("WAHA session form response uses Form Ending node",
      next(n["parameters"].get("pageType") for n in form["nodes"] if n["name"] == "Form Response") == "end")

# ── Summary ──
print(f"\n=== Results: {len([f for f in []])} — {len(expected)} placeholders, "
      f"{len(failures)} failures ===")
sys.exit(1 if failures else 0)