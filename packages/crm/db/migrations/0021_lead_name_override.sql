-- =============================================================================
-- Let the name the lead states replace a WhatsApp profile placeholder
--
-- `start_turn` stores the WhatsApp profile name (pushName/notifyName) as a
-- placeholder when the Lead is created. Migration 0018 made `upsert_lead` keep
-- the existing name, which also blocked the classifier from ever saving the
-- name the lead actually typed ("Bom dia criszander" -> name_val "criszander"
-- was discarded because the profile name was already non-null).
--
-- This function is the classifier path. It replaces a placeholder with the
-- stated name, but does not downgrade a fuller name with a shorter one:
-- "Gustavo Vilela" must not become "Gustavo", while "Deus No Controle De Tudo"
-- and "." are placeholders that the stated name should replace.
-- =============================================================================

create or replace function set_lead_name(
  p_artist_id uuid,
  p_phone     text,
  p_name      text
)
returns setof leads
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_phone          text := normalize_phone(p_phone);
  v_name           text := normalize_person_name(p_name);
  v_current        text;
  v_current_tokens text[];
  v_new_tokens     text[];
  v_lead           leads;
begin
  if v_phone is null or v_name is null then
    return;
  end if;

  select name into v_current
    from leads
   where artist_id = p_artist_id and phone_e164 = v_phone;

  if v_current is not null then
    -- Compare accent-insensitively, token by token. Keeping the existing name
    -- when it already contains every token of the new one stops "Gustavo"
    -- from replacing "Gustavo Vilela"; a genuinely different name ("Criszander"
    -- vs "Deus No Controle De Tudo", "Guilherme" vs ".") still wins.
    v_current_tokens := string_to_array(
      translate(lower(v_current), 'áàâãäéèêëíìîïóòôõöúùûüçñ', 'aaaaaeeeeiiiiooooouuuucn'),
      ' '
    );
    v_new_tokens := string_to_array(
      translate(lower(v_name), 'áàâãäéèêëíìîïóòôõöúùûüçñ', 'aaaaaeeeeiiiiooooouuuucn'),
      ' '
    );
    if v_current_tokens @> v_new_tokens then
      return;
    end if;
  end if;

  update leads
     set name = v_name
   where artist_id = p_artist_id and phone_e164 = v_phone
  returning * into v_lead;

  if v_lead.id is null then
    return;
  end if;
  return next v_lead;
end;
$$;

revoke all on function set_lead_name(uuid, text, text) from public;
grant execute on function set_lead_name(uuid, text, text) to agent_rw, crm_app;
