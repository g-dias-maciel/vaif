-- Which WhatsApp media messages a staff/AI description covers. Used to attach
-- the description to the exact artist bubble instead of every media message in
-- a ten-minute window.
alter table agent_drafts
  add column if not exists artist_media_ids jsonb;
