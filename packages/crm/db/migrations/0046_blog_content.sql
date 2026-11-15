-- =============================================================================
-- Blog content workflow — CRM-authored, admin-approved, LP-published
--
-- The CRM owns the topic calendar, the AI draft, the AI copilot review and the
-- human approval. Publishing writes a markdown file to packages/lp/content/blog
-- through the GitHub Contents API; the LP never writes content at runtime.
-- =============================================================================

-- Landing-page slug is the bridge between the CRM Artist and the public profile.
alter table artist_settings
  add column if not exists landing_page_slug text;

create unique index if not exists artist_settings_landing_page_slug
  on artist_settings (landing_page_slug)
  where landing_page_slug is not null;

comment on column artist_settings.landing_page_slug is
  'Slug of the public LP artist page (vaif.com.br/artists/<slug>). Null means the artist is not eligible for content.';

-- Bootstrap the first artist. The LP already publishes Adriano Santos at
-- /artists/adriano-santos; without this mapping the content cockpit would treat
-- him as ineligible until a staff user edits the artist. Pick the canonical
-- Adriano (the one with the most leads) when the CRM has duplicate records.
update artist_settings s
set landing_page_slug = 'adriano-santos'
from artists a
where a.id = s.artist_id
  and s.landing_page_slug is null
  and (a.name ilike 'Adriano%' or s.display_name ilike 'Adriano%')
  and a.id = (
    select a2.id
    from artists a2
    join artist_settings s2 on s2.artist_id = a2.id
    where a2.name ilike 'Adriano%' or s2.display_name ilike 'Adriano%'
    order by (
      select count(*) from leads l where l.artist_id = a2.id
    ) desc, a2.created_at asc
    limit 1
  );

-- -----------------------------------------------------------------------------
-- 1. Topic calendar
-- -----------------------------------------------------------------------------
create table if not exists blog_topics (
  id              uuid primary key default gen_random_uuid(),
  publish_date    date not null,
  artist_id       uuid references artists(id) on delete set null,
  artist_slug     text,
  track           text not null default 'local_guide'
                  check (track in ('local_guide', 'spotlight', 'specialty_guide', 'case_study', 'educational')),
  target_keyword  text not null,
  search_intent   text,
  angle           text,
  status          text not null default 'planned'
                  check (status in ('planned', 'generated', 'published', 'skipped')),
  notes           text,
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now()
);

create index if not exists blog_topics_due
  on blog_topics (publish_date, status);

create index if not exists blog_topics_artist
  on blog_topics (artist_id, publish_date desc);

comment on table blog_topics is
  'Weekly blog calendar. The content:generate command picks the oldest due planned topic.';

-- -----------------------------------------------------------------------------
-- 2. Drafts and approval state
-- -----------------------------------------------------------------------------
create table if not exists blog_posts (
  id                uuid primary key default gen_random_uuid(),
  blog_topic_id     uuid references blog_topics(id) on delete set null,
  artist_id         uuid references artists(id) on delete set null,
  artist_slug       text,
  track             text,
  title             text,
  slug              text unique,
  meta_description  text,
  excerpt           text,
  body_md           text,
  featured_image    text,
  featured_image_alt text,
  faq               jsonb not null default '[]'::jsonb,
  tags              jsonb not null default '[]'::jsonb,
  sources           jsonb not null default '[]'::jsonb,
  category          text,
  target_keyword    text,
  search_intent     text,
  facts             jsonb not null default '{}'::jsonb,
  ai_review         jsonb not null default '{}'::jsonb,
  ai_score          integer,
  admin_overview    text,
  status            text not null default 'generating'
                    check (status in ('generating', 'needs_review', 'approved', 'publishing', 'published', 'failed', 'rejected')),
  feedback          text,
  model             text,
  github_commit_sha text,
  published_url     text,
  approved_by       bigint references users(id) on delete set null,
  approved_at       timestamptz,
  published_at      timestamptz,
  verified_at       timestamptz,
  created_at        timestamptz not null default now(),
  updated_at        timestamptz not null default now()
);

create index if not exists blog_posts_status
  on blog_posts (status, updated_at desc);

create index if not exists blog_posts_artist
  on blog_posts (artist_id, created_at desc);

create index if not exists blog_posts_published
  on blog_posts (published_at desc);

comment on table blog_posts is
  'One weekly article. The body is the source of the markdown committed to the LP; ai_review is the admin overview + copilot findings.';

-- -----------------------------------------------------------------------------
-- 3. Revision history for regeneration comments
-- -----------------------------------------------------------------------------
create table if not exists blog_post_revisions (
  id            uuid primary key default gen_random_uuid(),
  blog_post_id  uuid not null references blog_posts(id) on delete cascade,
  version       integer not null default 1,
  body_md       text,
  ai_review     jsonb not null default '{}'::jsonb,
  feedback      text,
  created_by    bigint references users(id) on delete set null,
  created_at    timestamptz not null default now()
);

create index if not exists blog_post_revisions_post
  on blog_post_revisions (blog_post_id, version desc);

comment on table blog_post_revisions is
  'Snapshot of a draft before an admin-requested regeneration. Keeps the previous body, review and comment.';

-- -----------------------------------------------------------------------------
-- 4. updated_at triggers
-- -----------------------------------------------------------------------------
drop trigger if exists trg_blog_topics_touch on blog_topics;
create trigger trg_blog_topics_touch before update on blog_topics
  for each row execute function touch_updated_at();

drop trigger if exists trg_blog_posts_touch on blog_posts;
create trigger trg_blog_posts_touch before update on blog_posts
  for each row execute function touch_updated_at();

-- -----------------------------------------------------------------------------
-- 5. Grants
-- -----------------------------------------------------------------------------
grant select, insert, update, delete on blog_topics to crm_app;
grant select, insert, update, delete on blog_posts to crm_app;
grant select, insert, update, delete on blog_post_revisions to crm_app;

grant select on blog_topics to analytics_ro;
grant select on blog_posts to analytics_ro;
grant select on blog_post_revisions to analytics_ro;
