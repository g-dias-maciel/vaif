-- =============================================================================
-- Laravel database queue for the in-CRM WhatsApp agent
--
-- The CRM deployment has no Redis. Inbound WAHA messages are debounced and a
-- queued job runs the grounded turn; the queue worker lives in the app
-- container (`deploy/entrypoint.sh`).
--
-- Mirrors app/database/migrations/2026_11_08_000003_create_jobs_table.php so a
-- deploy that only runs the SQL migrator still gets the queue tables.
-- =============================================================================

create table if not exists public.jobs (
  id           bigserial primary key,
  queue        varchar(255) not null,
  payload      text not null,
  attempts     smallint not null,
  reserved_at  integer,
  available_at integer not null,
  created_at   integer not null
);

create index if not exists jobs_queue_index on public.jobs (queue);

create table if not exists public.job_batches (
  id             varchar(255) primary key,
  name           varchar(255) not null,
  total_jobs     integer not null,
  pending_jobs   integer not null,
  failed_jobs    integer not null,
  failed_job_ids text not null,
  options        text,
  cancelled_at   integer,
  created_at     integer not null,
  finished_at    integer
);

-- Laravel's failed_jobs table is created by the app migration; make sure the
-- runtime role can use it even when that migration ran as `migrator`.
create table if not exists public.failed_jobs (
  id         bigserial primary key,
  uuid       varchar(255) not null unique,
  connection text not null,
  queue      text not null,
  payload    text not null,
  exception  text not null,
  failed_at  timestamp(0) without time zone not null default now()
);

grant select, insert, update, delete on public.jobs to crm_app;
grant select, insert, update, delete on public.job_batches to crm_app;
grant select, insert, update, delete on public.failed_jobs to crm_app;
grant usage, select on sequence public.jobs_id_seq to crm_app;
grant usage, select on sequence public.failed_jobs_id_seq to crm_app;
