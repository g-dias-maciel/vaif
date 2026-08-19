-- VAIF — Message debounce buffer
-- Stores in-flight messages per Telegram chat so the SDR can wait for the
-- user to finish typing before responding to the whole burst as one message.

CREATE TABLE IF NOT EXISTS message_buffer (
  chat_id      TEXT PRIMARY KEY,
  pending      TEXT,
  last_msg_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_typing_at TIMESTAMPTZ
);

COMMENT ON TABLE message_buffer IS 'Buffers recent Telegram messages per chat for debounce — the SDR responds only after the user pauses typing.';
COMMENT ON COLUMN message_buffer.pending IS 'Accumulated message text for the current in-progress burst';
COMMENT ON COLUMN message_buffer.last_msg_at IS 'Timestamp of the most recent message in this chat';
COMMENT ON COLUMN message_buffer.last_typing_at IS 'Timestamp of the most recent typing indicator from this chat — extended while the user is actively typing';
