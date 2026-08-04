# Research: Notion as an agent backend — findings

**Ticket:** `issues/04-research-notion-backend.md` (wayfinder map: WhatsApp SDR closer)
**Date:** 2026-07-28
**Scope:** facts only, from primary sources (cited inline per claim). No recommendation — the "Sequencing: CRM-first vs agent-first" decision ticket consumes this.

Sources consulted: Notion API reference & guides (developers.notion.com, fetched 2026-07-28), Supabase self-hosting docs, Twenty docs + GitHub repo + LICENSE, Baserow docs + LICENSE, NocoDB GitHub README, n8n docs.

---

## 1. Notion API limits and behavior

### 1.1 Rate limits

- The Notion API enforces **two** rate limits: (a) **per connection** — "an average of three requests per second, with some bursts beyond the average allowed"; (b) **per workspace** — "shared across all of the workspace's connections and scaled to the workspace's plan." No numeric values are published for the per-workspace limit or for burst size. (https://developers.notion.com/reference/request-limits)
- Exceeding either limit returns HTTP **429** with error code `"rate_limited"` and `additional_data.rate_limit_reason` distinguishing which limit was hit (`public_api_request_rate_limit` vs `public_api_space_request_rate_limit`). (https://developers.notion.com/reference/request-limits)
- 429 and **529** (`"service_overload"`, Notion temporarily overloaded) responses carry a **`Retry-After`** header (integer seconds); Notion's documented accommodation pattern is to respect it, or to queue pending requests and drain the queue as long as no 429/529 is returned. (https://developers.notion.com/reference/request-limits)
- Rate limits "may change" at any time without an API version bump; clients are told to always respect `Retry-After`. (https://developers.notion.com/reference/versioning, https://developers.notion.com/reference/request-limits)
- The docs do not state whether the per-connection limit for a **public** connection (one VAIF integration installed into many artist workspaces) is counted globally per connection or per installation/token. See Gaps.

### 1.2 Query capabilities (database/data-source query)

- **Model change (2025-09-03):** "databases" were split into databases + **data sources**; `POST /v1/databases/{id}/query` is deprecated in favor of `POST /v1/data_sources/{data_source_id}/query` at version `2025-09-03` and later. Most operations that used `database_id` now require a `data_source_id`. If a connection on an older API version encounters a database to which a user added a second data source, page creation, database read/write/query, and relation writes **fail**. (https://developers.notion.com/guides/get-started/upgrade-guide-2025-09-03, https://developers.notion.com/reference/post-database-query)
- **Filters:** property-typed conditions exist for checkbox, date, files, formula, multi_select, number, people, phone_number, relation, rich_text, select, status, timestamp, verification, and unique_id. Phone-number properties accept text-style conditions (`equals`, `contains`, etc.), so "find lead by WhatsApp number" is a single filtered query. (https://developers.notion.com/reference/post-database-query-filter; phone→textPropertyFilter in the OpenAPI schema at https://developers.notion.com/reference/query-a-data-source)
- **Compound filters** (`and`/`or`) nest **up to two levels deep**. (https://developers.notion.com/reference/post-database-query-filter)
- **Relation filters** support only `contains` / `does_not_contain` (by related page UUID) and `is_empty` / `is_not_empty` — no filtering on properties *of the related page*. (https://developers.notion.com/reference/post-database-query-filter)
- **Rollup filters** support `any`/`every`/`none` sub-filters on array rollups, plus `date` and `number` conditions for rollups computed as date/number. (https://developers.notion.com/reference/post-database-query-filter)
- **Formula/rollup correctness limits (documented):** if a formula depends on a relation with more than **25 references**, only 25 are evaluated; "rollups and formulas that depend on multiple layers of relations may not return correct results." (https://developers.notion.com/reference/query-a-data-source)
- **Pagination:** cursor-based, `page_size` max **100** per call; response may contain fewer than `page_size` results even when more exist. (https://developers.notion.com/reference/intro#pagination)
- **Hard result cap:** a single query paginates through at most **10,000 results**; pagination then stops (`has_more: false`) and every page carries `request_status.type: "incomplete"` with `incomplete_reason: "query_result_limit_reached"`. The documented workaround is windowed queries over `created_time` (stored to the minute). (https://developers.notion.com/reference/query-a-data-source, https://developers.notion.com/guides/data-apis/query-large-data-sources)
- **Sorts:** on properties or page timestamps; multiple sorts allowed, order matters; no guaranteed order without explicit sorts. (https://developers.notion.com/reference/query-a-data-source)
- **Performance guidance (official):** use `filter_properties` to shrink responses ("significant improvement to the speed of the API"); narrow filters; prune formulas/rollups/two-way relations; and "dividing large data sources (ones with more than several dozen thousand pages) into multiple." Query 503s caused by "backend datastore timeouts" (`PgPoolWaitConnectionTimeout`) are documented, with retry guidance of exponential backoff, smaller `page_size`, narrower filters. (https://developers.notion.com/reference/query-a-data-source)
- **Search endpoint is title-only and eventually consistent:** it matches page/data-source *titles*; it is explicitly "not optimized for" exhaustive enumeration, searching within a database (use the query endpoint), or "immediate and complete results" — "search indexing is not immediate," so a just-created page may not appear. (https://developers.notion.com/reference/post-search, https://developers.notion.com/reference/search-optimizations-and-limitations)

### 1.3 Content limits (transcript-append relevance)

- **Append block children:** max **100 block children per API request**; max **two levels of nesting** in a single request; existing blocks **cannot be moved** via the API once appended. (https://developers.notion.com/reference/patch-block-children)
- **Per-request payload caps:** max **1000 block elements** and **500KB** overall; any array of block types (including rich-text arrays) max **100 elements**. (https://developers.notion.com/reference/request-limits)
- **Rich text:** `text.content` max **2000 characters** per rich-text object (link URLs also 2000; equations 1000). (https://developers.notion.com/reference/request-limits)
- **Property value request limits:** relation properties accept max **100 related pages per request** (the property can hold far more across requests); multi-select max 100 options; email/phone max 200 chars; any URL max 2000 chars. (https://developers.notion.com/reference/request-limits)
- **Reading transcripts back:** block children are paginated at max 100 per call and may need recursive retrieval for nested blocks — reconstructing a long conversation thread is N sequential calls. (https://developers.notion.com/reference/get-block-children, listed with pagination conventions at https://developers.notion.com/reference/intro#pagination)
- **Select/status options:** status properties are updated by option name or group name; option arrays are capped at 100 in the schema. (https://developers.notion.com/reference/post-database-query-filter, https://developers.notion.com/reference/query-a-data-source OpenAPI schema)
- I found **no documented hard cap on total blocks per page** (relevant to ever-growing transcript pages) — see Gaps.

### 1.4 Latency, errors, versioning

- **No official latency figures or SLA exist** in the API docs. The only timing statements: HTTP **503** "can occur when the time to respond to a request takes longer than 60 seconds, the maximum request timeout"; 503 also fires for datastore query timeouts (§1.2). (https://developers.notion.com/reference/status-codes, https://developers.notion.com/reference/query-a-data-source)
- **Write collisions are acknowledged:** HTTP **409** `"conflict_error"` — "The transaction could not be completed, potentially due to a data collision. Make sure the parameters are up to date and try again." (https://developers.notion.com/reference/status-codes)
- **`Notion-Version` header is required** on all REST requests; missing → 400 `missing_version`. Latest version at fetch time: **`2026-03-11`**; `2025-09-03` introduced the data-source split (breaking). (https://developers.notion.com/reference/versioning, https://developers.notion.com/guides/get-started/upgrade-guide-2025-09-03)
- Additive changes (new endpoints, new response fields) ship to **all** pinned versions simultaneously; only backwards-incompatible changes get a new version. Notion says it has "no plans to stop supporting older API versions." (https://developers.notion.com/reference/versioning)
- **Webhooks exist** as the documented alternative to polling: events for page/database/data-source/comment changes; payloads contain only IDs + metadata (no content — you must call the API to fetch the change). Delivery "within 5 minutes… most within a minute"; high-frequency events like `page.content_updated` are **aggregated** with "a slight delivery delay (typically under one minute)"; delivery is **at-most-once** with up to 8 retries over ~24h; event ordering is not guaranteed. (https://developers.notion.com/reference/webhooks, https://developers.notion.com/reference/webhooks-events-delivery)

### 1.5 Official stance: "live application backend" vs docs tool

- I found **no official statement** explicitly blessing or forbidding use of the Notion API as a live application backend (see Gaps). The closest official signals, all from the docs above:
  - Rate limiting to a 3 rps per-connection average "to ensure a consistent developer experience for all API users." (https://developers.notion.com/reference/request-limits)
  - The query endpoint's own performance section acknowledging datastore timeouts and recommending `filter_properties`, narrow filters, schema pruning, and splitting databases beyond "several dozen thousand pages." (https://developers.notion.com/reference/query-a-data-source)
  - The search endpoint's documented unsuitability for enumeration or immediate reads. (https://developers.notion.com/reference/search-optimizations-and-limitations)
  - The recommendation to use webhooks "instead of polling the full data source on a schedule" for change detection. (https://developers.notion.com/reference/query-a-data-source)

---

## 2. Fit as a live agent state store at VAIF's volume (arithmetic against documented limits)

VAIF shape (from the wayfinder map): 6 artists today, "tens" within 6 months; each artist 6–12 inbound lead conversations/day; n8n agent reads/writes CRM state per message; each artist runs their **own Notion workspace** with VAIF's template.

Message-volume assumptions (my estimates, marked as such): 4–10 messages exchanged per lead conversation (a booking flow), so **messages/day = artists × leads × 4–10**.

| Scenario | Messages/day | Notion API calls/day (at 3–6 calls/message, see below) | Avg rps vs 3 rps per-connection limit |
|---|---|---|---|
| Now: 6 artists × 6–12 leads × 4–10 msgs | ~150–720 | ~430–4,300 | 0.005–0.05 (≤2% of 3 rps) |
| 30 artists × 6–12 leads × 4–10 msgs | ~720–2,900 | ~2,200–17,300 | 0.025–0.2 (≤7% of 3 rps) |

Call budget per inbound message (typical n8n loop, mapped to documented endpoints):
1. **Find the lead:** 1 filtered data-source query on the phone property (supported, §1.2). (https://developers.notion.com/reference/query-a-data-source)
2. **Read conversation context:** 0–N calls — if the transcript lives in page blocks, block children return max 100/call (§1.3); a 300-block transcript is 3 sequential calls. (https://developers.notion.com/reference/intro#pagination)
3. **Append transcript:** 1 call per ≤100 blocks; any single WhatsApp message fits one paragraph block ≤2000 chars (§1.3). (https://developers.notion.com/reference/patch-block-children)
4. **Update status/stage:** 1 page-update call per change. (https://developers.notion.com/reference/patch-page)

So **3 calls/message** is the floor; **~6** with transcript read-back and an outbound-message append. (Call counts are my modeling; the per-endpoint mechanics are documented as cited.)

Concrete pressure points at VAIF volume, against the documented limits:

- **Average throughput is not the problem.** Even the 30-artist high estimate averages ≤0.2 rps against the 3 rps per-connection average. The binding constraint is **bursts**: conversations cluster (evenings), and each message round-trip is a *chain* of 3–6 calls; a burst of simultaneous conversations multiplies chains. Whether all artists share one budget depends on connection architecture: separate **internal connections per artist workspace** each get their own per-connection budget; a **single public connection** installed in 30 workspaces may share the 3 rps average across all of them — the docs do not say which (Gaps). The per-workspace limit is plan-scaled and undocumented numerically. (https://developers.notion.com/reference/request-limits)
- **429 handling is mandatory and queues are the documented pattern** — meaning agent replies can be delayed by `Retry-After` waits whenever bursts trip the limit. (https://developers.notion.com/reference/request-limits)
- **Transcript-append pattern fits the size limits** (100 blocks/call, 2000 chars/rich-text object), but transcripts stored as page blocks grow unboundedly; reading them back costs ceil(blocks/100) calls, and no total-blocks-per-page cap is documented either way (Gaps). (https://developers.notion.com/reference/request-limits, https://developers.notion.com/reference/get-block-children)
- **Status-field updates** are single PATCH calls; select/status options are capped at 100 — fine for a pipeline. (https://developers.notion.com/reference/request-limits)
- **Data-source row growth:** ~12 leads/day/artist → ~4,400 rows/year/artist. The 10,000-result cap is *per query*, so filtered agent queries are unaffected; an unfiltered full export would hit it in year 2–3. Notion itself suggests splitting databases past "several dozen thousand pages" and documents query 503s from datastore timeouts — the documented scaling ceilings are an order of magnitude above VAIF's per-artist row counts. (https://developers.notion.com/reference/query-a-data-source)
- **Relational CRM modeling has documented correctness cliffs:** >25-relation formulas evaluate only 25 refs; multi-layer relation rollups "may not return correct results" — relevant if the CRM derives booking/lead aggregates through relations. (https://developers.notion.com/reference/query-a-data-source)
- **Latency is unpublished**; the only documented timing ceiling is the 60s max request time (503) and webhook delivery of "most within a minute" (at-most-once, unordered) — so a human-edits-CRM → agent-notices loop is minute-scale, not second-scale, via webhooks. (https://developers.notion.com/reference/status-codes, https://developers.notion.com/reference/webhooks-events-delivery)
- **Concurrency:** no transactions or atomic multi-object writes exist; 409 conflict errors on colliding writes are documented. An n8n flow that updates a page *and* appends blocks is two non-atomic calls; partial-write states are possible. (https://developers.notion.com/reference/status-codes)
- **Freshness after writes:** search indexing "is not immediate," so "create lead page, then search for it" can miss; the query endpoint is the documented path for in-database lookups. (https://developers.notion.com/reference/search-optimizations-and-limitations)
- **Versioning risk:** the 2025-09-03 data-source split means template databases can *break* old-version connections if a user adds a data source; new builds should target `2025-09-03`+ (current: `2026-03-11`) and discover `data_source_id`s. (https://developers.notion.com/guides/get-started/upgrade-guide-2025-09-03, https://developers.notion.com/reference/versioning)

---

## 3. Self-hostable alternatives (capabilities + hosting model only)

### 3.1 Plain Postgres accessed from n8n

- n8n's Postgres node supports: **Delete** (rows/truncate/drop), **Execute Query** (raw SQL with parameterized queries; n8n sanitizes parameters), **Insert**, **Insert or Update** (upsert), **Select** (conditions, sort, limit), **Update**. A **Query Batching** option offers "Transaction" mode: all queries execute in one transaction with rollback on failure. (https://docs.n8n.io/integrations/builtin/app-nodes/n8n-nodes-base.postgres/)
- n8n ships a **Postgres Chat Memory** node for AI agents: stores chat history in a named table (auto-created if missing), keyed by session key, with a configurable context-window length. (https://docs.n8n.io/integrations/builtin/cluster-nodes/sub-nodes/n8n-nodes-langchain.memorypostgreschat/)
- There is also a Postgres **trigger** node. (https://docs.n8n.io/integrations/builtin/app-nodes/n8n-nodes-base.postgres/, "Related resources")
- Hosting model: Postgres is a single service; connection is by host/port/db/credentials/SSL per n8n's Postgres credentials. (https://docs.n8n.io/integrations/builtin/app-nodes/n8n-nodes-base.postgres/)

### 3.2 Supabase (self-hosted)

- **What self-hosting includes (documented architecture):** Postgres, PostgREST (auto REST API from the DB), GoTrue Auth, Realtime (Postgres change broadcasting), Storage API, Studio dashboard, Kong API gateway, postgres-meta, Edge Runtime (Deno functions), Supavisor connection pooler, imgproxy; optional Logflare + Vector for logs/analytics. REST at `/rest/v1/`, Auth `/auth/v1/`, Storage `/storage/v1/`, Realtime `/realtime/v1/`. (https://supabase.com/docs/guides/self-hosting/docker#architecture)
- **What self-hosting does *not* include:** platform-only features — branching, advanced metrics beyond logs, managed backups and PITR, analytics and vector buckets, ETL, and the platform management API. Studio is single-project. Backups, HA, scaling, security hardening, and monitoring are explicitly the operator's responsibility. Community-supported. No telemetry in the Docker Compose stack. (https://supabase.com/docs/guides/self-hosting)
- **Requirements:** minimum 4 GB RAM / 2 cores / 40 GB SSD (recommended 8 GB / 4 / 80); Docker + Docker Compose; one-command Linux setup script; services can be dropped from the compose file to cut footprint; stable compose releases ~monthly. (https://supabase.com/docs/guides/self-hosting/docker)

### 3.3 Twenty (open-source CRM)

- **License:** mostly **AGPLv3**; files marked `@license Enterprise` are under Twenty's commercial license requiring an Enterprise subscription for production use. (https://raw.githubusercontent.com/twentyhq/twenty/main/LICENSE)
- **Product/stack:** self-described "open alternative to Salesforce"; TypeScript, NestJS + BullMQ, **PostgreSQL + Redis**, React frontend. CRM building blocks: objects, views, workflows, AI agents; standard objects (Person, Company, …) plus custom objects/fields/relations definable as code via `twenty-sdk` (`defineObject`, `defineField`, MANY_TO_ONE/ONE_TO_MANY relations). (https://github.com/twentyhq/twenty, https://docs.twenty.com/llms.txt — entries for developers/extend/apps/data/*)
- **API:** "REST and GraphQL APIs generated from your workspace schema" (https://docs.twenty.com/llms.txt — entry https://docs.twenty.com/developers/extend/api); **webhooks**: HTTP POST on every record create/update/delete (https://docs.twenty.com/llms.txt — entry https://docs.twenty.com/developers/extend/webhooks); OAuth with PKCE + client credentials (https://docs.twenty.com/llms.txt — entry https://docs.twenty.com/developers/extend/oauth).
- **Self-host:** Docker Compose (one-line install script or manual `.env` + compose); **min 2 GB RAM**; requires setting `ENCRYPTION_KEY` and Postgres password; `SERVER_URL` for external access; reverse-proxy TLS recommended; backups = `pg_dump` of the `twenty-postgres` container; managed-hosting partners exist. (https://docs.twenty.com/developers/self-host/capabilities/docker-compose)

### 3.4 Baserow

- **License:** open-source no-code database; core ("Baserow OSE") under **MIT Expat**; `premium/` and `enterprise/` directories under separate licenses. (https://raw.githubusercontent.com/baserow/baserow/develop/LICENSE, product description: https://baserow.io/docs/index)
- **API:** full REST backend API with OpenAPI spec/redoc; JWT auth (60-min tokens, refreshable) plus scoped **database tokens** for per-table programmatic access; auto-generated per-database API docs; a **WebSocket API** broadcasts real-time updates. (https://baserow.io/docs/apis%2Frest-api, https://baserow.io/docs/index)
- **Self-host:** official guides for Docker, Docker Compose, Kubernetes/Helm, Traefik/Nginx/Apache fronting, Cloudron, and others. (https://baserow.io/docs/index)

### 3.5 NocoDB

- **License:** "Free & Self-hostable Airtable Alternative" under a **Sustainable Use License** (source-available, not OSI). (https://github.com/nocodb/nocodb — About + License section)
- **Capabilities:** spreadsheet UI over databases; CRUD on tables/columns/rows; grid/gallery/form/kanban/calendar views; role-based access control; cell types incl. links, lookup, rollup, formula; programmatic access via **REST APIs** and an SDK; integrations (Slack/Discord, email, S3-compatible storage) via an app store. (https://github.com/nocodb/nocodb — Features)
- **Self-host:** single Docker container with built-in SQLite, or against **external Postgres** via `NC_DB`; "Auto-upstall" one-command script installs NocoDB + PostgreSQL + Redis + Traefik with auto-SSL for production. (https://github.com/nocodb/nocodb — Installation)

---

## 4. n8n specifics

### 4.1 n8n Notion node (documented operations)

- **Block:** Append After; Get Many; Get Markdown. **Data Source:** Get; Search. **Database:** Get. **Database Page:** Create; Get; Get Many; Update. **Page:** Archive; Create; Get Markdown; Search; Update Markdown. **User:** Get; Get Many. (https://docs.n8n.io/integrations/builtin/app-nodes/n8n-nodes-base.notion/)
- The node **can be attached to an AI agent as a tool** (parameters set by the agent). (https://docs.n8n.io/integrations/builtin/app-nodes/n8n-nodes-base.notion/)
- Anything outside those operations is done via the generic **HTTP Request node** reusing the Notion credential (n8n's documented escape hatch) — e.g. block update/delete, comments, file uploads, webhook-subscription management have no dedicated node operations (they are absent from the list above). (https://docs.n8n.io/integrations/builtin/app-nodes/n8n-nodes-base.notion/)
- **Notion Trigger node** exists with exactly two events: "Page added to database" and "Page updated in database." (https://docs.n8n.io/integrations/builtin/trigger-nodes/n8n-nodes-base.notiontrigger/) — whether it polls or receives Notion webhooks is not stated on the page (Gaps); Notion's own webhooks could also hit an n8n Webhook node directly (generic capability, not Notion-specific doc).

### 4.2 n8n Postgres and Supabase nodes (documented)

- **Postgres node:** full CRUD + raw SQL + upsert + transaction batching (§3.1). (https://docs.n8n.io/integrations/builtin/app-nodes/n8n-nodes-base.postgres/)
- **Supabase node:** row operations only — Create, Delete, Get, Get all, Update; defaults to the `public` schema, with an option for custom schemas; AI-tool capable. No Realtime/auth/storage operations in the node. (https://docs.n8n.io/integrations/builtin/app-nodes/n8n-nodes-base.supabase/)
- **Postgres Chat Memory:** agent chat-history persistence in Postgres (§3.1). (https://docs.n8n.io/integrations/builtin/cluster-nodes/sub-nodes/n8n-nodes-langchain.memorypostgreschat/)
- n8n has a Notion **credential** type (token-based) used by both node and HTTP-Request fallback. (https://docs.n8n.io/integrations/builtin/app-nodes/n8n-nodes-base.notion/ — Credentials hint)

---

## 5. Gaps / what I could not verify from primary sources

1. **Public-connection rate-limit scope:** Notion's docs state per-connection (3 rps avg) and per-workspace limits but do **not** say whether a public connection installed in N workspaces gets one shared 3 rps budget or one per installation/token. Load-bearing for VAIF's architecture; needs a support answer or empirical test. (https://developers.notion.com/reference/request-limits)
2. **Per-workspace limit numbers:** "scaled to the workspace's plan" — no plan-by-plan values published. (https://developers.notion.com/reference/request-limits)
3. **Burst size:** "some bursts beyond the average allowed" — no documented burst ceiling or window. (https://developers.notion.com/reference/request-limits)
4. **Latency:** no official response-time figures, percentiles, or SLA for the Notion API anywhere in the docs; only the 60s max-request-timeout statement. (https://developers.notion.com/reference/status-codes)
5. **Explicit "use us as a live backend" stance:** no official statement found either way (no FAQ entry, no ToS clause located during this pass). Only the indirect signals listed in §1.5.
6. **Total blocks per page:** no documented cap on cumulative children of a page (transcript growth); only per-request caps (100 children/append, 1000 block elements/payload). (https://developers.notion.com/reference/patch-block-children, https://developers.notion.com/reference/request-limits)
7. **HTTP 406 `row_limit_exceeded`:** present in Notion's OpenAPI error schemas but absent from the documented status-codes table — trigger conditions undocumented. (https://developers.notion.com/reference/query-a-data-source OpenAPI schema vs https://developers.notion.com/reference/status-codes)
8. **n8n Notion Trigger mechanics:** the docs page lists its two events but not whether it polls on a schedule or consumes Notion webhooks. (https://docs.n8n.io/integrations/builtin/trigger-nodes/n8n-nodes-base.notiontrigger/)
9. **Twenty API details** (pagination, filtering depth, rate limits) were taken only from docs index descriptions + repo README; the API reference page itself (https://docs.twenty.com/developers/extend/api) was not deep-fetched. Same for Twenty's webhook retry/ordering semantics.
10. **Baserow/NocoDB rate limits and self-hosted performance characteristics:** not located in their docs during this pass (Baserow's redoc spec and NocoDB docs may contain them).
11. Volume math in §2 uses **my assumptions** (4–10 messages/conversation, 3–6 API calls/message) — marked as such; only the per-endpoint mechanics and limits are sourced.
