-- =============================================================================
-- Let the CRM append messages to the agent transcript
--
-- n8n owns `agent_memory.chat_memory`, but some messages are sent by the CRM
-- outside the workflow (the deposit/appointment confirmation). Those must be
-- recorded so they appear in the Lead's Conversa panel and in the agent's
-- history on the next turn.
--
-- crm_app already has SELECT on the table (0003_roles_v2.sql); this adds
-- INSERT and sequence usage. Wrapped in a guard because a fresh database may
-- not have the n8n schema yet.
-- =============================================================================

do $$
begin
  if to_regclass('agent_memory.chat_memory') is not null then
    execute 'grant usage on schema agent_memory to crm_app';
    execute 'grant select, insert on agent_memory.chat_memory to crm_app';

    if to_regclass('agent_memory.chat_memory_id_seq') is not null then
      execute 'grant usage, select on sequence agent_memory.chat_memory_id_seq to crm_app';
    end if;
  end if;
end
$$;
