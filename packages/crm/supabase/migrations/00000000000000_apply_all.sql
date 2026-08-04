-- Combined migration for n8n database.
-- Apply this to the database your 'main-db' Postgres credential connects to.
-- Paste into SQL editor or run: psql -h <host> -U <user> -d <db> -f this_file.sql

\echo '=== Applying schema ==='
\i 20240601000000_schema.sql

\echo '=== Applying CRUD functions ==='
\i 20240602000000_crud_functions.sql

\echo '=== Applying seed data ==='
\i ../seed.sql

\echo '=== Done ==='
