-- =============================================================================
-- Studio address for the SDR agent
--
-- The agent used to invent the studio city ("São Paulo") or emit a placeholder
-- ("[inserir endereço completo do estúdio aqui]") because the artist's address
-- was not in the prompt. These fields are injected into the system prompt; when
-- empty, the agent must say the artist will send the address instead of
-- guessing.
-- =============================================================================

alter table artist_settings
  add column if not exists address text;

alter table artist_settings
  add column if not exists city text;

comment on column artist_settings.address is
  'Full studio address shown by the SDR agent; null means the agent must not invent one.';

comment on column artist_settings.city is
  'Studio city shown by the SDR agent; null means the agent must not invent one.';
