-- VAIF — Notion sync config per artist
-- Adds Notion integration token + database IDs to the artists table.

ALTER TABLE artists
  ADD COLUMN notion_token                  TEXT,
  ADD COLUMN notion_clientes_database_id   TEXT,
  ADD COLUMN notion_projects_database_id   TEXT;

COMMENT ON COLUMN artists.notion_token IS 'Notion internal integration secret for this artist workspace';
COMMENT ON COLUMN artists.notion_clientes_database_id IS 'UUID of the Clientes data source in this artist workspace';
COMMENT ON COLUMN artists.notion_projects_database_id IS 'UUID of the Projects data source in this artist workspace';
