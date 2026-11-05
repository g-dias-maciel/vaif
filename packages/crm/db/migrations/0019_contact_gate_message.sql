-- =============================================================================
-- Keep the held message so "Responder" can replay it
--
-- When the saved-contact gate holds a first message, the flow never reaches the
-- AI, so the message is only in WAHA's store. Storing it on the Lead lets the
-- artist's "Responder" replay it through the workflow so Beatriz sends her
-- greeting immediately, instead of waiting for the contact to write again.
-- =============================================================================

alter table leads
  add column if not exists contact_gate_message text;

comment on column leads.contact_gate_message is
  'The first inbound text held by the saved-contact gate, replayed when the artist allows the contact.';
