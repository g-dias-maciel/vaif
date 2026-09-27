-- VAIF — Message debounce buffer
-- Stores in-flight messages per Telegram chat so the SDR can wait for the
-- user to finish typing before responding to the whole burst as one message.

CREATE TABLE IF NOT EXISTS message_buffer (
  chat_id      TEXT PRIMARY KEY,
  pending      TEXT,
  last_msg_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_typing_at TIMESTAMPTZ,
  typing_state   TEXT
);

-- Reconcile older deployments that created the table without presence columns.
ALTER TABLE message_buffer ADD COLUMN IF NOT EXISTS last_typing_at TIMESTAMPTZ;
ALTER TABLE message_buffer ADD COLUMN IF NOT EXISTS typing_state   TEXT;

COMMENT ON TABLE message_buffer IS 'Buffers recent WhatsApp messages per chat for debounce — the SDR responds only after the lead pauses typing.';
COMMENT ON COLUMN message_buffer.pending IS 'Accumulated message text for the current in-progress burst';
COMMENT ON COLUMN message_buffer.last_msg_at IS 'Timestamp of the most recent message in this chat';
COMMENT ON COLUMN message_buffer.last_typing_at IS 'Timestamp of the most recent typing indicator from this chat — extended while the lead is actively typing';
COMMENT ON COLUMN message_buffer.typing_state IS 'Last WAHA presence status for this chat (typing | paused | online | offline | recording)';
