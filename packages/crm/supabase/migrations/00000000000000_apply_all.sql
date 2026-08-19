-- Combined migration for n8n database.
-- Apply this to the database your 'main-db' Postgres credential connects to.
-- Paste into SQL editor or run: psql -h <host> -U <user> -d <db> -f this_file.sql

\echo '=== Applying schema ==='
\i 20240601000000_schema.sql

\echo '=== Applying Notion config ==='
\i 20240603000000_notion_config.sql

\echo '=== Applying message buffer ==='
\i 20240604000000_message_buffer.sql

\echo '=== Applying CRUD functions ==='
\i 20240602000000_crud_functions.sql

\echo '=== Applying seed data ==='
\i ../seed.sql

\echo '=== Done ==='
