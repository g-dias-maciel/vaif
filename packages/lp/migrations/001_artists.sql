-- =============================================================================
-- VAIF LP — Migration 001: artist intake table
--
-- Stores artist landing-page content submitted through the intake form
-- (/novo-artista), pending review, then published to /artists/<slug>.
--
-- The `data` column holds the full artist config array (same shape as a
-- config/*.php file) as JSON. `slug`, `display_name` and `whatsapp_number` are
-- denormalized for fast listing/lookup. `status` gates public visibility.
--
-- The app also auto-provisions this table on first use (CREATE TABLE IF NOT
-- EXISTS in lib/artists/store.php), so running this file manually is optional.
-- =============================================================================

CREATE TABLE IF NOT EXISTS artists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(120) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    whatsapp_number VARCHAR(32) NOT NULL,
    data LONGTEXT NOT NULL,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    preview_token VARCHAR(64) NOT NULL,
    submitter_name VARCHAR(255) NULL,
    submitter_contact VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_artists_slug (slug),
    KEY idx_artists_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invite tokens for the intake form (/novo-artista/<token>). Single-use: the
-- admin panel mints them and the artist form consumes one on successful submit.
CREATE TABLE IF NOT EXISTS intake_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL,
    label VARCHAR(255) NULL,
    status ENUM('active','used','revoked') NOT NULL DEFAULT 'active',
    used_by_slug VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at TIMESTAMP NULL,
    UNIQUE KEY uq_intake_tokens_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
