# VAIF

VAIF is a Brazilian marketing agency serving tattoo artists exclusively. This repo holds its agentic context: wayfinding maps, specs, and (per the monorepo decision) its workflows and products.

## Language

### The business

**VAIF**:
The agency itself — Brazilian, serves tattoo artists only. Products: ads management, CRM, the SDR agent.
_Avoid_: the agency, the company

**Artist**:
A tattoo artist who pays VAIF for services. VAIF's unit of client.
_Avoid_: client, customer (both collide with the tattoo customer, who is a Lead)

**Lead**:
A person who messages an Artist's WhatsApp interested in getting a tattoo — VAIF's ads or organic sent them.
_Avoid_: prospect, contact, client, customer

**Close**:
Converting a Lead into a booked tattoo appointment. The SDR agent's goal.
_Avoid_: sale, conversion

### The product

**SDR agent**:
The AI agent that works an Artist's inbound Leads on WhatsApp and Closes them. The product this map specs.
_Avoid_: bot, chatbot, assistant

**CRM**:
The system of record for an Artist's Leads and bookings. Today: a per-artist Notion template the Artist uses directly. Possibly a self-built product later.
_Avoid_: database

**Transport**:
The WhatsApp API layer the SDR agent sends/receives through — self-hosted WAHA, or Meta's official Cloud API.
_Avoid_: WhatsApp API, provider

**Testbed**:
The Telegram-bot harness used to trial SDR agent behavior without touching WhatsApp.
_Avoid_: staging, sandbox
