# VAIF

VAIF is a Brazilian marketing agency serving tattoo artists exclusively. This monorepo holds all its products (CRM, SDR agent, landing page, infrastructure) and planning.

## Language

### The business

**VAIF**:
The agency itself — Brazilian, serves tattoo artists only. Products: ads management, CRM, the SDR agent.
_Avoid_: the agency, the company

**Artist**:
A tattoo artist who pays VAIF for services. VAIF's unit of client.
_Avoid_: client, customer (both collide with the tattoo customer, who is a Lead)

**Staff**:
A VAIF internal user who can browse every Artist's CRM read-only to verify the pipeline and bookings.
_Avoid_: admin, operator, backoffice

**Lead**:
A person interested in getting a tattoo from an Artist — from any channel (WhatsApp, Instagram, referral, ads). One Lead per person per Artist, no matter how many channels or tattoo ideas they contact through.
_Avoid_: prospect, contact, client, customer

**Artist prospect**:
A tattoo artist VAIF is signing as a client — the "leads" in the internal NocoDB base. Distinct from a tattoo-customer Lead.
_Avoid_: lead (reserved for the tattoo customer)

**Close**:
Converting a Lead into a booked tattoo appointment. The SDR agent's goal.
_Avoid_: sale, conversion

**Booking**:
A scheduled tattoo appointment for a Lead, recorded in the Artist's calendar.
_Avoid_: appointment, session (session collides with session duration)

### The product

**SDR agent**:
The AI agent that works an Artist's inbound Leads on WhatsApp and Closes them. The product this map specs.
_Avoid_: bot, chatbot, assistant

**CRM**:
The system of record for an Artist's Leads and Bookings. Now a self-built Laravel app (`packages/crm/app`); previously a per-artist Notion template the Artist used directly.
_Avoid_: database

**Note**:
An internal note on a Lead, written in the CRM by the Artist or Staff. Visible to the Artist and Staff, never surfaced to the Lead.
_Avoid_: comment, remark

**Reschedule**:
Moving an existing Booking to a different free slot in the Artist's calendar.
_Avoid_: move, change

**Transport**:
The WhatsApp API layer the SDR agent sends/receives through — self-hosted WAHA, or Meta's official Cloud API.
_Avoid_: WhatsApp API, provider

**Testbed**:
The Telegram-bot harness used to trial SDR agent behavior without touching WhatsApp.
_Avoid_: staging, sandbox

**Promotion**:
A pre-approved offer the SDR agent presents alongside the price. Its mechanic is fixed centrally; a VAIF admin sets the Artist-specific price and conditions and controls activation. One Promotion is active per Artist at a time.
_Avoid_: discount (a discount is something the SDR negotiates; a Promotion is pre-approved), campaign

**Returning Lead**:
A Lead with a prior confirmed booking with that same Artist. Distinguished from a new Lead so Promotions can target one or the other, and so the Artist is notified when one returns.
_Avoid_: existing client, old client

**Chat ownership**:
Who answers a given WhatsApp chat — Beatriz or the Artist. The Artist takes a chat over by replying in WhatsApp; ownership persists until the Artist returns it from the dashboard.
_Avoid_: handoff (handoff is a one-time transfer to the Artist; ownership is persistent)
