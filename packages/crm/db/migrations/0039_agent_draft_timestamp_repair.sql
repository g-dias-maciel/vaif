-- Before the Postgres session used the app timezone, Eloquent wrote
-- `created_at` (and other model timestamps) as local wall time into a
-- timestamptz column, so every draft landed three hours behind. `lead_msg_at`
-- is parsed from an offset-aware WhatsApp timestamp and is correct, so it is
-- the anchor for the repair.
--
-- 1. A reply that appears to predate the lead message was shifted by the same
--    amount as `created_at`. Move it forward.
update agent_drafts
   set artist_replied_at = artist_replied_at + (lead_msg_at - created_at)
 where artist_replied_at is not null
   and lead_msg_at is not null
   and created_at < lead_msg_at - interval '10 minutes'
   and artist_replied_at < lead_msg_at;

-- 2. Anchor the draft to the real lead message time. New drafts (after the
--    timezone fix) are created a few seconds after the lead message and are
--    left untouched.
update agent_drafts
   set created_at = lead_msg_at,
       updated_at = lead_msg_at
 where lead_msg_at is not null
   and created_at < lead_msg_at - interval '10 minutes';

-- 3. Response time is now a plain difference between two correct instants.
update agent_drafts
   set response_seconds = greatest(0, round(extract(epoch from (artist_replied_at - lead_msg_at)))::integer)
 where artist_replied_at is not null
   and lead_msg_at is not null;
