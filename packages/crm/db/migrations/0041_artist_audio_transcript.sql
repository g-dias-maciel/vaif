-- =============================================================================
-- Append the transcript of an artist's WhatsApp audio to the conversation
--
-- Artist audio is recorded as "[ÁUDIO ENVIADO]" in `agent_memory.chat_memory`.
-- The transcription job then calls this RPC with the WAHA message id so the
-- lead page (and the agent's next turn) see what the artist actually said,
-- without giving `crm_app` blanket UPDATE on the n8n memory table.
-- =============================================================================

create or replace function append_artist_audio_transcript(
  p_wa_message_id text,
  p_transcript     text
)
returns void
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_row record;
begin
  if p_wa_message_id is null or btrim(p_wa_message_id) = ''
     or p_transcript is null or btrim(p_transcript) = '' then
    return;
  end if;

  select id, message->>'content' as content
    into v_row
    from agent_memory.chat_memory
   where message->'additional_kwargs'->>'wa_message_id' = p_wa_message_id
     and message->'additional_kwargs'->>'author' = 'artist'
   order by id desc
   limit 1;

  if v_row.id is null then
    return;
  end if;

  -- Retries must not append the same transcript twice.
  if position('Áudio do artista:' in coalesce(v_row.content, '')) > 0 then
    return;
  end if;

  update agent_memory.chat_memory
     set message = jsonb_set(
       message,
       '{content}',
       to_jsonb(btrim(coalesce(v_row.content, '') || E'\n' || 'Áudio do artista: ' || btrim(p_transcript))),
       true
     )
   where id = v_row.id;
end;
$$;

revoke all on function append_artist_audio_transcript(text, text) from public;
grant execute on function append_artist_audio_transcript(text, text) to crm_app;
