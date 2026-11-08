-- =============================================================================
-- Keep `agent_rw` on the RPC surface only
--
-- 0027 granted the agent role direct SELECT/INSERT/UPDATE on
-- `agent_turn_actions`. The idempotency wrapper is SECURITY DEFINER, so the
-- agent only needs EXECUTE. Direct table grants break the v2 contract that the
-- agent role cannot read or write domain tables.
-- =============================================================================

revoke all on agent_turn_actions from agent_rw;

grant select, insert, update on agent_turn_actions to crm_app;
