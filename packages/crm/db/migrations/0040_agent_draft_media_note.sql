-- The artist often answers with an image or a video. A staff member can
-- describe that media here so the calibration judge has something to compare
-- and the lead timeline stops showing only "[MÍDIA ENVIADA]".
alter table agent_drafts
  add column if not exists artist_media_note text;
