-- =============================================================================
-- Keep the lead's name once it is known
--
-- `start_turn` now passes the WhatsApp pushName as `p_name` so a brand-new
-- lead is stored with a placeholder name from the contact profile. On later
-- turns that pushName must NOT overwrite the real name the customer typed (or
-- the artist edited): prefer the name already on file and only fall back to
-- the incoming placeholder when the lead has none.
-- =============================================================================

create or replace function upsert_lead(
  p_artist_id uuid,
  p_phone     text,
  p_name      text default null,
  p_source    text default null
)
returns setof leads
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_phone text := normalize_phone(p_phone);
  v_lead  leads;
begin
  if v_phone is null then
    raise exception 'upsert_lead: invalid phone' using errcode = '22023';
  end if;
  if not exists (select 1 from artists where id = p_artist_id) then
    raise exception 'upsert_lead: unknown artist %', p_artist_id using errcode = '23503';
  end if;

  insert into leads (artist_id, name, phone_e164, phone_raw, source, last_message_at)
  values (p_artist_id, normalize_person_name(p_name), v_phone,
          nullif(btrim(coalesce(p_phone, '')), ''), p_source, now())
  on conflict (artist_id, phone_e164) do update set
    name            = coalesce(leads.name, excluded.name),
    phone_raw       = coalesce(excluded.phone_raw, leads.phone_raw),
    source          = coalesce(leads.source, excluded.source),
    last_message_at = now()
  returning * into v_lead;

  return next v_lead;
end;
$$;

revoke all on function upsert_lead(uuid, text, text, text) from public;
grant execute on function upsert_lead(uuid, text, text, text) to agent_rw, crm_app;
