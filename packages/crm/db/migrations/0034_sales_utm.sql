-- =============================================================================
-- Internal sales CRM — UTM attribution on prospects
--
-- The calculator sends utm_* values; they are stored on the prospect and
-- resolved to a campaign by campaigns.utm_campaign on ingest.
-- =============================================================================

alter table public.prospects
  add column if not exists utm_source   text,
  add column if not exists utm_medium   text,
  add column if not exists utm_campaign text,
  add column if not exists utm_content  text,
  add column if not exists utm_term     text;

create index if not exists prospects_utm_campaign on public.prospects (utm_campaign);

comment on column public.prospects.utm_campaign is 'Raw utm_campaign; resolved to campaigns.utm_campaign on ingest.';
