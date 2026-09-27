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
AGENT_TEXT_JS = nodes["AI Agent"]["parameters"]["text"]

with open(os.path.join(FLOWS_ROOT, "prompts", "classify-conversation.md")) as f:
    CLASSIFY_TEMPLATE = f.read()

agent = nodes["AI Agent"]
check("AI Agent systemMessage references Build System Prompt",
      agent["parameters"]["options"]["systemMessage"] == "={{ $('Build System Prompt').first().json.system_message }}")

bsp = nodes["Build System Prompt"]
check("Build System Prompt embeds the template",
      "assistente do tatuador {{NOME}}" in bsp["parameters"]["jsCode"])
check("Build System Prompt coerces NUMERIC strings",
      "Number(artist.floor_pct)" in bsp["parameters"]["jsCode"])

check("Artist Found? routes through Route Event",
      wf["connections"]["Artist Found?"]["main"][0][0]["node"] == "Route Event")
check("Route Event routes presence → Presence Start and message → Check AI Window",
      wf["connections"]["Route Event"]["main"][0][0]["node"] == "Presence Start"
      and wf["connections"]["Route Event"]["main"][1][0]["node"] == "Check AI Window")
check("Presence Start → Presence Upsert",
      wf["connections"]["Presence Start"]["main"][0][0]["node"] == "Presence Upsert")
check("In AI Window? routes through Debounce Start",
      wf["connections"]["In AI Window?"]["main"][0][0]["node"] == "Debounce Start")
check("Debounce Start fans out to Debounce Accumulate + Subscribe Presence",
      [c["node"] for c in wf["connections"]["Debounce Start"]["main"][0]] == ["Debounce Accumulate", "Subscribe Presence"])
check("Debounce Resolve → Debounce Decision (process → Clear Buffer, wait → Wait)",
      wf["connections"]["Debounce Resolve"]["main"][0][0]["node"] == "Debounce Decision"
      and wf["connections"]["Debounce Decision"]["main"][0][0]["node"] == "Clear Buffer"
      and wf["connections"]["Debounce Decision"]["main"][1][0]["node"] == "Wait")
check("Enqueue Notion Sync → humanize → Send WAHA Message → Stop Typing → handoff",
      wf["connections"]["Enqueue Notion Sync"]["main"][0][0]["node"] == "Build Humanize Delay"
      and wf["connections"]["Build Humanize Delay"]["main"][0][0]["node"] == "Send Typing"
      and wf["connections"]["Send Typing"]["main"][0][0]["node"] == "Humanize Delay"
      and wf["connections"]["Humanize Delay"]["main"][0][0]["node"] == "Send WAHA Message"
      and wf["connections"]["Send WAHA Message"]["main"][0][0]["node"] == "Stop Typing"
      and wf["connections"]["Stop Typing"]["main"][0][0]["node"] == "Build Handoff Message")
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

# ── 3b. Rule 1: mandatory self-introduction ──
print("=== Mandatory introduction ===")
check("context exposes primeira_mensagem",
      "primeira_mensagem=SIM/NAO" in template)
check("prompt forbids skipping the introduction",
      "NUNCA vá direto para perguntas sobre a tatuagem sem antes se apresentar" in template)
check("prompt requires intro even if the lead already described the tattoo",
      "AINDA QUE o lead já tenha descrito a tatuagem na primeira mensagem" in template)
check("agent text threads primeira_mensagem",
      "primeira_mensagem=" in AGENT_TEXT_JS and "is_new" in AGENT_TEXT_JS)

# ── 3c. Rule 2: hard price gate ──
print("=== Price gate ===")
check("context exposes processo_explicado",
      "processo_explicado=SIM/NAO" in template)
check("prompt has the GATE DE PREÇO section",
      "## GATE DE PREÇO (regra inviolável)" in template)
check("prompt requires process explained before price",
      "`processo_explicado=SIM` no contexto" in template)
check("prompt requires explicit no-doubts before price",
      "disse EXPLICITAMENTE, na mensagem dele, que não tem mais nenhuma dúvida" in template)
check("prompt forbids quoting while processo_explicado=nao",
      "NUNCA mencione valores (R$, parcelas, 6x, sinal) enquanto `processo_explicado=nao`" in template)
check("checklist references the price gate",
      "GATE DE PREÇO: só fale de valores quando `processo_explicado=SIM`" in template)
check("classifier emits price_gate.duvidas_eliminadas",
      '"duvidas_eliminadas": true or false' in CLASSIFY_TEMPLATE)
check("classifier blocks orcamento_enviado without duvidas_eliminadas",
      "SÓ marque `orcamento_enviado` se `duvidas_eliminadas=true`" in CLASSIFY_TEMPLATE)
parse_js = nodes["Parse Classification"]["parameters"]["jsCode"]
check("parse guard downgrades premature quote transitions",
      "!priceGateOpen && ['orcamento_enviado', 'aguardando_deposito', 'agendado'].includes(finalPipeline)" in parse_js)
check("parse computes the price gate (open once quote stage reached)",
      "priceGateOpen" in parse_js and "gateAlreadyOpen" in parse_js)
check("parse replaces premature price replies with the doubts question",
      "priceGateViolation" in parse_js
      and "'Antes de falarmos de valores, ficou alguma dúvida?'" in parse_js
      and "final_reply" in parse_js)
check("premature replies don't persist pricing",
      "priceGateViolation ? null : (p.table_cents" in parse_js)
check("reply uses the guarded final_reply",
      nodes["Send WAHA Message"]["parameters"]["bodyParameters"]["parameters"][2]["value"]
      == "={{ $('Parse Classification').first().json.final_reply }}")
check("humanize scales to the guarded reply length",
      "$('Parse Classification').first().json.final_reply" in nodes["Build Humanize Delay"]["parameters"]["jsCode"])
check("parse detects the creative-process text deterministically",
      "processo de criação" in parse_js and "processoDeterministic" in parse_js)
check("update query persists processo_explicado",
      "processo_explicado = true" in nodes["Build Update Query"]["parameters"]["jsCode"])
check("upsert returns is_new",
      "true AS is_new" in nodes["Upsert Lead"]["parameters"]["query"]
      and "false AS is_new" in nodes["Upsert Lead"]["parameters"]["query"])

# ── 3d. Rule 3: typing-aware debounce + humanized response time ──
print("=== Typing debounce + humanize ===")
for n in ["Route Event", "Presence Start", "Presence Upsert", "Subscribe Presence",
          "Debounce Decision", "Build Humanize Delay", "Humanize Delay",
          "Send Typing", "Stop Typing"]:
    check(f"node '{n}' exists", n in nodes)
check("Route Event drops non message/presence events (3rd output)",
      nodes["Route Event"]["parameters"].get("numberOutputs") == 3
      and "'presence.update' ? 0" in nodes["Route Event"]["parameters"]["output"])
gs = nodes["Get Buffer State"]["parameters"]["query"]
check("buffer state reads typing columns",
      "last_typing_at" in gs and "typing_state" in gs)
dr = nodes["Debounce Resolve"]["parameters"]["jsCode"]
check("debounce waits on active typing",
      "typingActive" in dr and "typing_state === 'typing'" in dr)
check("debounce has a quiet period",
      "QUIET_MS" in dr and "quiet" in dr)
check("debounce caps the typing wait (safety)",
      "TYPING_GRACE_MS" in dr)
check("debounce stops stale executions (newer message owns reply)",
      "isStale" in dr and "lastMsgAt > msgTs" in dr)
check("buffer write is monotonic (out-of-order messages can't both win)",
      "GREATEST(message_buffer.last_msg_at" in nodes["Debounce Accumulate"]["parameters"]["query"])
check("clear buffer also resets typing state",
      "typing_state = NULL" in nodes["Clear Buffer"]["parameters"]["query"])
check("presence upsert records typing state",
      "typing_state" in nodes["Presence Upsert"]["parameters"]["query"])
check("presence events gate typing hold",
      "EXCLUDED.typing_state" in nodes["Presence Upsert"]["parameters"]["query"])
check("humanize delay scales with reply length",
      "delay_seconds" in nodes["Build Humanize Delay"]["parameters"]["jsCode"]
      and "words" in nodes["Build Humanize Delay"]["parameters"]["jsCode"])
check("humanize wait uses the computed delay",
      nodes["Humanize Delay"]["parameters"]["amount"] == "={{ $('Build Humanize Delay').first().json.delay_seconds }}")
check("outbound typing indicator is best-effort",
      nodes["Send Typing"].get("onError") == "continueRegularOutput"
      and nodes["Stop Typing"].get("onError") == "continueRegularOutput")
check("presence subscription is best-effort",
      "presence" in nodes["Subscribe Presence"]["parameters"]["url"]
      and nodes["Subscribe Presence"].get("onError") == "continueRegularOutput")
check("own outgoing messages are ignored",
      "fromMe === true" in nodes["Check AI Window"]["parameters"]["jsCode"])

print("=== Onboarding form webhook events ===")
check("onboarding form subscribes to presence.update",
      "presence.update" in next(n for n in load_json("artist-onboarding-form.json")["nodes"]
                                if n["name"] == "Create WAHA Session")["parameters"]["jsonBody"])

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