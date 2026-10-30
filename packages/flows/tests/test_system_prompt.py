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
expected = ["{{NOME}}", "{{INSTAGRAM}}", "{{PIX}}", "{{SINAL}}", "{{PISO}}", "{{DESCONTO_MAX}}", "{{TABELA_PRECOS}}", "{{OFFER}}"]
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
    "name": "Bruno",
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
    "{{NOME}}": artist["name"],
    "{{INSTAGRAM}}": artist["instagram_handle"],
    "{{PIX}}": artist["pix_key"],
    "{{SINAL}}": sinal,
    "{{PISO}}": piso,
    "{{DESCONTO_MAX}}": desconto_max,
    "{{TABELA_PRECOS}}": table,
    "{{OFFER}}": "_Nenhuma promoção ativa no momento._",
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

check("the WAHA webhook requires header authentication",
      nodes["WAHA Webhook"]["parameters"].get("authentication") == "headerAuth"
      and nodes["WAHA Webhook"].get("credentials", {}).get("httpHeaderAuth", {}).get("name") == "VAIF Webhook Auth")
AGENT_TEXT_JS = nodes["AI Agent"]["parameters"]["text"]

with open(os.path.join(FLOWS_ROOT, "prompts", "classify-conversation.md")) as f:
    CLASSIFY_TEMPLATE = f.read()

agent = nodes["AI Agent"]
check("AI Agent systemMessage comes from the reference/vision step",
      agent["parameters"]["options"]["systemMessage"] == "={{ $json.system_message }}")

bsp = nodes["Build System Prompt"]
check("Build System Prompt embeds the template",
      "assistente do tatuador {{NOME}}" in bsp["parameters"]["jsCode"])
check("Build System Prompt coerces NUMERIC strings",
      "Number(artist.floor_pct)" in bsp["parameters"]["jsCode"])

check("Artist Found? routes through the group-chat guard and then Route Event",
      wf["connections"]["Artist Found?"]["main"][0][0]["node"] == "Group Chat?"
      and wf["connections"]["Group Chat?"]["main"][0] == []
      and wf["connections"]["Group Chat?"]["main"][1][0]["node"] == "Route Event")
check("group chats are detected by @g.us / isGroup and dropped",
      "@g.us" in nodes["Group Chat?"]["parameters"]["conditions"]["conditions"][0]["leftValue"]
      and "isGroup" in nodes["Group Chat?"]["parameters"]["conditions"]["conditions"][0]["leftValue"])
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
check("Patch Chat State → humanize → Split Reply → Send WAHA → Aggregate → Stop Typing → handoff",
      wf["connections"]["Ensure Quote"]["main"][0][0]["node"] == "Patch Chat State"
      and wf["connections"]["Patch Chat State"]["main"][0][0]["node"] == "Build Humanize Delay"
      and wf["connections"]["Build Humanize Delay"]["main"][0][0]["node"] == "Send Typing"
      and wf["connections"]["Send Typing"]["main"][0][0]["node"] == "Humanize Delay"
      and wf["connections"]["Humanize Delay"]["main"][0][0]["node"] == "Split Reply"
      and wf["connections"]["Split Reply"]["main"][0][0]["node"] == "Send WAHA Message"
      and wf["connections"]["Send WAHA Message"]["main"][0][0]["node"] == "Aggregate Sent"
      and wf["connections"]["Aggregate Sent"]["main"][0][0]["node"] == "Stop Typing"
      and wf["connections"]["Stop Typing"]["main"][0][0]["node"] == "Build Handoff Message")
check("Clear Buffer → Check Chat Owner → Start Turn → Agent Blocked? → Build References → Save References → Load Offer → Load Pricing → Build System Prompt → Analyze Audio?",
      wf["connections"]["Clear Buffer"]["main"][0][0]["node"] == "Check Chat Owner"
      and wf["connections"]["Chat Owned by Artist?"]["main"][1][0]["node"] == "Start Turn"
      and wf["connections"]["Start Turn"]["main"][0][0]["node"] == "Agent Blocked?"
      and wf["connections"]["Agent Blocked?"]["main"][0] == []
      and [c["node"] for c in wf["connections"]["Agent Blocked?"]["main"][1]] == ["Build References"]
      and wf["connections"]["Build References"]["main"][0][0]["node"] == "Save References"
      and wf["connections"]["Save References"]["main"][0][0]["node"] == "Load Offer"
      and wf["connections"]["Load Offer"]["main"][0][0]["node"] == "Load Pricing"
      and wf["connections"]["Load Pricing"]["main"][0][0]["node"] == "Build System Prompt"
      and wf["connections"]["Build System Prompt"]["main"][0][0]["node"] == "Analyze Audio?")
check("audio turns are transcribed, text turns bypass audio and both reach Analyze Reference?",
      [c["node"] for c in wf["connections"]["Analyze Audio?"]["main"][0]] == ["Fetch Audio"]
      and [c["node"] for c in wf["connections"]["Analyze Audio?"]["main"][1]] == ["No Audio"]
      and wf["connections"]["Fetch Audio"]["main"][0][0]["node"] == "Extract Audio Base64"
      and wf["connections"]["Extract Audio Base64"]["main"][0][0]["node"] == "Build Audio Request"
      and wf["connections"]["Build Audio Request"]["main"][0][0]["node"] == "Audio Ready?"
      and [c["node"] for c in wf["connections"]["Audio Ready?"]["main"][0]] == ["Transcribe Audio"]
      and [c["node"] for c in wf["connections"]["Audio Ready?"]["main"][1]] == ["Parse Audio"]
      and wf["connections"]["Transcribe Audio"]["main"][0][0]["node"] == "Parse Audio"
      and wf["connections"]["Parse Audio"]["main"][0][0]["node"] == "Analyze Reference?"
      and wf["connections"]["No Audio"]["main"][0][0]["node"] == "Analyze Reference?")
check("a failed audio download still reaches the agent instead of killing the turn",
      nodes["Fetch Audio"].get("onError") == "continueRegularOutput"
      and nodes["Extract Audio Base64"].get("onError") == "continueRegularOutput"
      and nodes["Transcribe Audio"].get("onError") == "continueRegularOutput")
check("WAHA media URLs are rewritten without the URL global missing from expressions",
      "new URL(" not in nodes["Fetch Audio"]["parameters"]["url"]
      and "waha.vaif.com.br" in nodes["Fetch Audio"]["parameters"]["url"]
      and "replace(" in nodes["Fetch Audio"]["parameters"]["url"])
check("the agent text uses the audio transcript when available",
      "$json.transcript" in AGENT_TEXT_JS
      and "$('Parse Audio')" in nodes["Build Classification Prompt"]["parameters"]["jsCode"])
check("stickers and audio are detected from WAHA message payloads, not only type",
      "stickerMessage" in nodes["Debounce Start"]["parameters"]["jsCode"]
      and "audioMessage" in nodes["Debounce Start"]["parameters"]["jsCode"]
      and "isSticker" in nodes["Debounce Start"]["parameters"]["jsCode"]
      and "[STICKER RECEBIDO]" in template
      and "type === 'sticker'" not in nodes["Debounce Start"]["parameters"]["jsCode"].split("const mediaUrl =")[1].split(";")[0])
check("Analyze Reference? sends images through vision and text-only turns straight to the agent",
      [c["node"] for c in wf["connections"]["Analyze Reference?"]["main"][0]] == ["Build Vision Request"]
      and [c["node"] for c in wf["connections"]["Analyze Reference?"]["main"][1]] == ["No Reference"]
      and wf["connections"]["Build Vision Request"]["main"][0][0]["node"] == "Analyze Reference"
      and wf["connections"]["Analyze Reference"]["main"][0][0]["node"] == "Parse Reference Analysis"
      and [c["node"] for c in wf["connections"]["Parse Reference Analysis"]["main"][0]] == ["AI Agent", "Save Reference Analysis"]
      and wf["connections"]["No Reference"]["main"][0][0]["node"] == "AI Agent")
check("vision request uses the public CRM image URL and a multimodal model",
      "image_url" in nodes["Build Vision Request"]["parameters"]["jsCode"]
      and "gpt-4o-mini" in nodes["Build Vision Request"]["parameters"]["jsCode"]
      and "image_url" in nodes["Build Vision Request"]["parameters"]["jsCode"])
check("vision prompt forbids using the image to calculate price",
      "NUNCA use esta análise para calcular preço" in nodes["Parse Reference Analysis"]["parameters"]["jsCode"])
check("vision analysis is persisted for later turns",
      nodes["Save Reference Analysis"]["parameters"]["url"].endswith("/api/references/analysis")
      and "reference_analysis" in nodes["Save Reference Analysis"]["parameters"]["jsonBody"])
check("agent context exposes referencia_visual from start_turn",
      "referencia_visual=" in AGENT_TEXT_JS
      and "reference_analysis" in AGENT_TEXT_JS
      and "referencia_visual" in template)
check("AI Agent → Build Classification Prompt → Classify → Parse → Build Project Patch → Patch Project",
      wf["connections"]["AI Agent"]["main"][0][0]["node"] == "Build Classification Prompt"
      and wf["connections"]["Build Classification Prompt"]["main"][0][0]["node"] == "Classify Conversation"
      and wf["connections"]["Classify Conversation"]["main"][0][0]["node"] == "Parse Classification"
      and [c["node"] for c in wf["connections"]["Parse Classification"]["main"][0]] == ["Build Project Patch", "Sync Chat Memory"]
      and wf["connections"]["Build Project Patch"]["main"][0][0]["node"] == "Patch Project")
check("Patch Project fans out to quote persistence, promotion quote and name patch",
      {c["node"] for c in wf["connections"]["Patch Project"]["main"][0]}
      == {"Ensure Quote", "Write Promotion Quote", "Patch Lead"})
check("the Notion mirror is gone (RPCs write the audit trail)",
      "Log Event" not in nodes and "Enqueue Notion Sync" not in nodes
      and "Build Notion Sync Payload" not in nodes)

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
check("first reply asks only the name",
      "Antes de começarmos, como posso te chamar?" in template
      and "pergunte APENAS o nome" in template)

# ── 3d. Date offers: exactly two options, labels, preferred day fallback ──
print("=== Date offer ===")
check("Check Availability uses the two-option RPC",
      "booking_options(" in nodes["Check Availability"]["parameters"]["query"]
      and "label" in nodes["Check Availability"]["parameters"]["query"])
check("Check Availability reads the lead's own message for the day",
      "combined_text" in nodes["Check Availability"]["parameters"]["query"]
      or "combined_text" in str(nodes["Check Availability"]["parameters"].get("options", {}).get("queryReplacement", "")))
check("agent tools parameterise every value (no SQL injection via $fromAI)",
      all(
          "$fromAI" not in nodes[name]["parameters"]["query"]
          and "queryReplacement" in nodes[name]["parameters"].get("options", {})
          for name in ["Write Quote", "Request Deposit", "Check Availability", "Book Slot"]
      ))
check("prompt only offers two options",
      "REGRA DAS DUAS OPÇÕES" in template
      and "no máximo 2 opções" in template)
check("prompt asks for a day only after the two options are refused",
      "SE O LEAD RECUSAR AS DUAS OPÇÕES" in template
      and "Qual dia funciona melhor" in template)
check("prompt lets the tool resolve the day from the lead's message",
      "A ferramenta lê a mensagem do lead" in template
      and "não precisa calcular" in template)
check("data_hoje uses the artist timezone",
      "toLocaleDateString('en-CA'" in AGENT_TEXT_JS
      and "timeZone" in AGENT_TEXT_JS)

# ── 3c. Rule 2: hard price gate ──
print("=== Price gate ===")
check("context exposes processo_explicado and preco_liberado",
      "processo_explicado=SIM/NAO" in template and "preco_liberado=SIM/NAO" in template)
check("prompt has the one-time price gate section",
      "## GATE DE PREÇO (checkpoint ÚNICO" in template)
check("prompt requires process explained before price",
      "`processo_explicado=SIM` no contexto" in template)
check("prompt requires explicit no-doubts before price",
      "SÓ avance para o preço (Fase 6) DEPOIS que o lead disser explicitamente que não tem mais dúvidas" in template)
check("prompt once-gate is permanent and skips the doubts question",
      "o gate está ABERTO PARA SEMPRE" in template
      and "NUNCA mais pergunte \"Antes de falarmos de valores, ficou alguma dúvida?\"" in template)
check("negotiation phase forbids re-asking the doubts question",
      "NUNCA pergunte \"Antes de falarmos de valores, ficou alguma dúvida?\" nem \"Mais alguma dúvida?\" — isso já foi resolvido" in template)
check("classifier emits price_gate.duvidas_eliminadas",
      '"duvidas_eliminadas": true or false' in CLASSIFY_TEMPLATE)
check("classifier only needs duvidas before the first quote",
      "o gate já foi aberto" in CLASSIFY_TEMPLATE)
parse_js = nodes["Parse Classification"]["parameters"]["jsCode"]
check("parse gate is sticky once opened",
      "precoLiberadoJa" in parse_js and "priceGateOpen = precoLiberadoJa || gateJustOpened" in parse_js)
check("rhetorical questions do not hide the doubts checkpoint",
      "rhetoricalQuestionCount" in parse_js
      and "tudo bem|tudo bom" in parse_js)
# Behavioural check of the same rule: "tudo bem?" before the checkpoint must not
# count as an earlier discovery question, otherwise "não" never clears the gate.
_rhetorical = re.compile(r"(?:tudo bem|tudo bom|pode ser|certo|ok|okay|beleza|combinado|entendeu)\s*\?", re.I)
_process = ("Agora, vou te explicar como funciona o processo de criação: "
            "ele vai conversar com você, tudo bem? "
            "Antes de falarmos de valores, ficou alguma dúvida?")
_earlier = _process[: _process.rfind("?")]
check("process explanation ends with a recognised checkpoint question",
      re.search(r"(?:mais alguma|ficou alguma|alguma (?:outra )?)(?:pergunta|d[uú]vida)\s*\?\s*$", _process, re.I) is not None
      and (_earlier.count("?") - len(_rhetorical.findall(_earlier))) == 0)
check("parse deterministically records a sent quote even if classifier missed it",
      "Deterministic quote state" in parse_js
      and "finalPipeline = 'orcamento_enviado'" in parse_js)
check("parse does not persist classifier booking dates (Book Slot is authoritative)",
      "booked_date_val" not in parse_js)
check("parse exposes the gate flags for agent_state",
      "chat_state:" in parse_js and "preco_liberado: precoLiberadoJa || quoteActuallySent" in parse_js)
check("redundant Lookup Price tool removed from the agent",
      "Lookup Price" not in nodes
      and not any(c.get("node") == "Lookup Price"
                  for t in wf["connections"].values()
                  for b in t.get("ai_tool", []) for c in b))
check("prompt treats the price table as authoritative",
      "A tabela abaixo é a fonte oficial dos preços" in template)
check("prompt blocks the deposit when the PIX key is missing",
      "(informe a chave PIX)" in template and "NÃO peça sinal" in template)
check("prompt has a hard deposit gate after booking",
      "GATE DO SINAL" in template
      and "depois que o lead escolheu um horário E o Book Slot confirmou" in template
      and "NUNCA peça sinal na mesma mensagem" in template)
check("output gate replaces a premature sinal with a date prompt",
      "bookedThisTurn" in parse_js
      and "depositGateOpen" in parse_js
      and "Primeiro vamos escolher o horário" in parse_js)
check("a vision turn is guaranteed to acknowledge the reference",
      "Vision acknowledgement" in parse_js
      and "Vi a referência que você mandou" in parse_js)
check("the final checkpoint is never sliced off after an earlier question",
      "const checkpointAt" not in parse_js
      and "Mais alguma dúvida?" in parse_js)
check("false cover-up / artist-request handoffs are suppressed",
      "leadSaysCover" in parse_js
      and "leadRequestedArtist" in parse_js
      and "handoffSuppressed" in parse_js)
check("a corrected new tattoo recovers from aguardando_artista",
      "recoveredFromHandoff" in parse_js
      and "finalPipeline = 'qualificando'" in parse_js
      and "nextDiscoveryQuestion" in parse_js)
check("multi-message acknowledgements clear the doubts gate",
      "const acknowledgement" in parse_js
      and "const noDoubt" in parse_js
      and "const doubtTalk" in parse_js)
check("prompt handles generic placements without handoff",
      "Se o local for GENÉRICO" in template and "NÃO faça handoff" in template)
check("parse enforces the doubt-loop follow-up while pre-price",
      "preGateDoubtLoop" in parse_js and "Mais alguma dúvida?'" in parse_js)
check("prompt states the last-line doubt rule",
      "REGRA DA ÚLTIMA LINHA" in template)
check("start_turn returns the scratch state",
      "start_turn" in nodes["Start Turn"]["parameters"]["query"])
check("agent text threads preco_liberado",
      "preco_liberado=" in AGENT_TEXT_JS)
check("agent text exposes a precomputed signal amount",
      "sinal_reais=" in AGENT_TEXT_JS and "Math.ceil(Number(base) * Number(dv) / 100)" in AGENT_TEXT_JS)
check("prompt tells Beatriz to use sinal_reais verbatim",
      "`sinal_reais`: valor EXATO do sinal em reais" in template
      and "NUNCA faça a conta você mesma" in template)
check("parse guard downgrades premature quote transitions",
      "!priceGateOpen && ['orcamento_enviado', 'aguardando_deposito', 'agendado'].includes(finalPipeline)" in parse_js)
check("parse computes the price gate (open once quote stage reached)",
      "priceGateOpen" in parse_js and "priceGateOpen = precoLiberadoJa || gateJustOpened" in parse_js)
check("parse replaces premature price replies with the doubts question",
      "priceGateViolation" in parse_js
      and "'Antes de falarmos de valores, ficou alguma dúvida?'" in parse_js
      and "final_reply" in parse_js)
check("premature replies don't persist pricing",
      "const quoteBaseCents = !quoteActuallySent" in parse_js
      and "const quoteOfferedCents = !quoteActuallySent" in parse_js)
check("the reply is split into paragraph-sized messages",
      "$('Parse Classification').first().json.final_reply" in nodes["Split Reply"]["parameters"]["jsCode"]
      and "split(/\\n\\s*\\n+/)" in nodes["Split Reply"]["parameters"]["jsCode"]
      and nodes["Send WAHA Message"]["parameters"]["bodyParameters"]["parameters"][2]["value"] == "={{ $json.text }}")
check("the per-paragraph sends are throttled and aggregated",
      nodes["Send WAHA Message"]["parameters"]["options"]["batching"]["batch"]["batchSize"] == 1
      and nodes["Send WAHA Message"]["parameters"]["options"]["batching"]["batch"]["batchInterval"] > 0
      and "Aggregate Sent" in nodes)
check("the CRM memory is synced with the exact sent reply",
      "Sync Chat Memory" in nodes
      and "jsonb_set(message, '{content}'" in nodes["Sync Chat Memory"]["parameters"]["query"]
      and "$json.final_reply" in nodes["Sync Chat Memory"]["parameters"]["options"]["queryReplacement"])
check("the vision acknowledgement is short and skips the first-message intro",
      "assunto" in nodes["Parse Reference Analysis"]["parameters"]["jsCode"]
      and "slice(0, 90)" in parse_js
      and "lead.is_new !== true" in parse_js)
check("humanize scales to the guarded reply length",
      "$('Parse Classification').first().json.final_reply" in nodes["Build Humanize Delay"]["parameters"]["jsCode"])
check("parse detects the creative-process text deterministically",
      "processo de criação" in parse_js and "processoDeterministic" in parse_js)
check("chat_state persists processo_explicado",
      "processo_explicado: processoDeterministic" in parse_js)
check("start_turn owns is_new",
      "start_turn" in nodes["Start Turn"]["parameters"]["query"]
      and "primeira_mensagem=" in AGENT_TEXT_JS and "is_new" in AGENT_TEXT_JS)
check("handoff notification reads the Lead phone from Start Turn",
      "const leadData = $('Start Turn').first().json;" in nodes["Build Handoff Message"]["parameters"]["jsCode"]
      and "leadData.phone_e164" in nodes["Build Handoff Message"]["parameters"]["jsCode"])

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
check("buffer state reads the pending burst",
      "chat_buffer_state" in gs)
dr = nodes["Debounce Resolve"]["parameters"]["jsCode"]
check("debounce waits on active typing",
      "typingActive" in dr and "typing_state === 'typing'" in dr)
check("debounce has a quiet period",
      "QUIET_MS" in dr and "quiet" in dr)
check("debounce waits long enough to stack a burst",
      "QUIET_MS = 6000" in dr)
check("LID chats resolve the real phone from remoteJidAlt",
      "remoteJidAlt" in nodes["Debounce Start"]["parameters"]["jsCode"]
      and "phoneJid" in nodes["Debounce Start"]["parameters"]["jsCode"])
check("presence uses the same full chat key as messages",
      "session + ':' + chatId" in nodes["Presence Start"]["parameters"]["jsCode"])
check("debounce caps the typing wait (safety)",
      "TYPING_GRACE_MS" in dr)
check("debounce stops stale executions (newer message owns reply)",
      "isStale" in dr and "lastMsgAt > msgTs" in dr)
check("buffer write uses the accumulating RPC (monotonic timestamps)",
      "chat_buffer_accumulate" in nodes["Debounce Accumulate"]["parameters"]["query"])
check("clear buffer also resets typing state",
      "chat_buffer_clear" in nodes["Clear Buffer"]["parameters"]["query"])
check("presence upsert records typing state",
      "chat_buffer_typing" in nodes["Presence Upsert"]["parameters"]["query"])
check("presence events carry the typing state to the buffer",
      "$json.presence" in nodes["Presence Upsert"]["parameters"]["options"]["queryReplacement"])
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