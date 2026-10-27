<?php

declare(strict_types=1);

/**
 * Artist persistence layer for the intake → draft → review → publish workflow.
 *
 * Artists created through the intake form are stored in the `artists` table as
 * a JSON document (the same shape as a config/ file) plus a few denormalized
 * columns for fast listing and lookup. Legacy config/ files keep working and
 * take precedence; DB-backed artists are loaded only when no config file
 * matches the slug.
 *
 * Media uploaded via the form lands in artists/<slug>/media/, which is exactly
 * where the existing template resolves bare filenames (see lib/artists/media.php).
 */

define('ARTISTS_DIR', dirname(__DIR__, 2) . '/artists');

/**
 * Lazily build (and cache) the PDO connection used by the artist store.
 * Returns null when the environment is not configured for a database.
 */
function artist_db(): ?PDO
{
    static $pdo = null;
    static $tried = false;

    if ($pdo !== null) {
        return $pdo;
    }
    if ($tried) {
        return null;
    }
    $tried = true;

    $host = getenv('DB_HOST');
    $dbname = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASSWORD');

    if (!$host || !$dbname || !$user) {
        return null;
    }

    try {
        // Mirrors the DSN shape used by the existing lead endpoints so the
        // ephemeral-MySQL acceptance tests can inject host;port the same way.
        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass !== false ? $pass : '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Create the artists table if it does not exist yet. Safe to call on every
 * request that touches the store (CREATE TABLE IF NOT EXISTS is cheap).
 */
function artist_db_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS artists (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Decode the JSON `data` column into the artist config array.
 */
function artist_row_decode(array $row): array
{
    $data = json_decode((string) ($row['data'] ?? '[]'), true);
    $row['data'] = is_array($data) ? $data : [];
    return $row;
}

/**
 * Load a DB-backed artist row by slug (data column already decoded), or null.
 */
function artist_db_load_by_slug(PDO $pdo, string $slug): ?array
{
    artist_db_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM artists WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ? artist_row_decode($row) : null;
}

/**
 * Load a DB-backed artist row by id, or null.
 */
function artist_db_load_by_id(PDO $pdo, int $id): ?array
{
    artist_db_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM artists WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? artist_row_decode($row) : null;
}

/**
 * All DB-backed artists, newest first (for the admin list).
 */
function artist_db_list(PDO $pdo): array
{
    artist_db_ensure_schema($pdo);
    $rows = $pdo->query('SELECT * FROM artists ORDER BY updated_at DESC, id DESC')->fetchAll();
    return array_map('artist_row_decode', $rows);
}

/**
 * Insert a new draft artist. Returns the new row id.
 */
function artist_db_insert(PDO $pdo, string $slug, array $data, string $previewToken, array $meta = []): int
{
    artist_db_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO artists (slug, display_name, whatsapp_number, data, status, preview_token, submitter_name, submitter_contact)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $slug,
        (string) ($data['display_name'] ?? ''),
        (string) ($data['whatsapp_number'] ?? ''),
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'draft',
        $previewToken,
        (string) ($meta['submitter_name'] ?? ''),
        (string) ($meta['submitter_contact'] ?? ''),
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Update an existing artist's data (and optionally its status).
 */
function artist_db_update(PDO $pdo, int $id, array $data, ?string $status = null, array $meta = []): bool
{
    artist_db_ensure_schema($pdo);
    $sets = ['data = ?', 'display_name = ?', 'whatsapp_number = ?'];
    $params = [
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        (string) ($data['display_name'] ?? ''),
        (string) ($data['whatsapp_number'] ?? ''),
    ];
    if ($status !== null) {
        $sets[] = 'status = ?';
        $params[] = $status;
    }
    foreach (['submitter_name', 'submitter_contact'] as $k) {
        if (array_key_exists($k, $meta)) {
            $sets[] = "$k = ?";
            $params[] = (string) $meta[$k];
        }
    }
    $params[] = $id;
    $stmt = $pdo->prepare('UPDATE artists SET ' . implode(', ', $sets) . ' WHERE id = ?');
    return $stmt->execute($params);
}

/**
 * Change only the status column (draft <-> published).
 */
function artist_db_set_status(PDO $pdo, int $id, string $status): bool
{
    $stmt = $pdo->prepare('UPDATE artists SET status = ? WHERE id = ?');
    return $stmt->execute([$status, $id]);
}

/**
 * Permanently remove a DB-backed artist (and its media directory).
 */
function artist_db_delete(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM artists WHERE id = ?');
    return $stmt->execute([$id]);
}

/**
 * Remove the media directory for a slug, if any.
 */
function artist_delete_media_dir(string $slug): void
{
    $dir = ARTISTS_DIR . '/' . $slug;
    if (is_dir($dir)) {
        exec('rm -rf ' . escapeshellarg($dir) . ' 2>/dev/null');
    }
}

// ================================================================
// Slug + token helpers
// ================================================================

/**
 * The absolute path of a legacy config file for a slug.
 */
function artist_config_path(string $slug): string
{
    return ARTISTS_DIR . '/config/' . $slug . '.php';
}

/**
 * Whether a slug is already taken (by a config file or a DB row).
 */
function artist_slug_exists(PDO $pdo, string $slug): bool
{
    artist_db_ensure_schema($pdo);
    if (file_exists(artist_config_path($slug))) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM artists WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Turn a display name into a URL-safe slug (lowercase, ASCII, hyphenated).
 */
function artist_slugify(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($ascii) && $ascii !== '') {
            $text = $ascii;
        }
    }
    $text = strtolower($text);
    $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

/**
 * Generate a slug guaranteed to be unique against config files and the DB.
 */
function artist_unique_slug(PDO $pdo, string $base): string
{
    $base = artist_slugify($base);
    if ($base === '') {
        $base = 'artista';
    }
    $slug = $base;
    $i = 1;
    while (artist_slug_exists($pdo, $slug)) {
        $i++;
        $slug = $base . '-' . $i;
    }
    return $slug;
}

/**
 * A random preview token (used to view a draft before publishing).
 */
function artist_preview_token(): string
{
    return bin2hex(random_bytes(24));
}

/**
 * Whether the current request may view a draft artist (via ?preview=<token>).
 */
function artist_preview_authorized(array $row): bool
{
    $token = (string) ($_GET['preview'] ?? '');
    return $token !== '' && hash_equals((string) ($row['preview_token'] ?? ''), $token);
}

/**
 * The admin token from the environment (falls back to the existing create key).
 */
function artist_admin_token(): string
{
    $t = getenv('ARTISTS_ADMIN_TOKEN');
    if ($t === false || $t === '') {
        $t = getenv('API_CREATE_KEY');
    }
    return (string) $t;
}

// ================================================================
// Content sanitizing
// ================================================================

/**
 * Reverse of artist_sanitize_bio(): turn the stored safe HTML back into plain
 * text for editing in a textarea.
 */
function artist_bio_to_plain(string $html): string
{
    $text = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $html);
    $text = (string) preg_replace('#</p>\s*<p>#i', "\n\n", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    return trim($text);
}

/**
 * Convert free-form artist bio text into safe HTML: escaped, paragraphs split
 * on blank lines, single newlines become <br>. The template renders `bio`
 * unescaped, so this MUST produce only safe markup.
 */
function artist_sanitize_bio(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    $paragraphs = preg_split('/\n\s*\n/', $text) ?: [$text];
    $html = '';
    foreach ($paragraphs as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        $p = str_replace("\n", '<br>', $p);
        $html .= '<p>' . $p . '</p>' . "\n";
    }
    return $html;
}

// ================================================================
// Media uploads
// ================================================================

/**
 * Validate an uploaded image and return its canonical extension (jpg/png/webp),
 * or '' when the file is not an accepted image.
 */
function artist_validate_image(array $file): string
{
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        return '';
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($finfo, $tmp);
        finfo_close($finfo);
    }
    $byMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if ($mime !== '' && isset($byMime[$mime])) {
        return $byMime[$mime];
    }

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $byExt = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp'];
    return $byExt[$ext] ?? '';
}

/**
 * Persist a single uploaded image (a normalized $_FILES entry) into
 * artists/<slug>/media/<base>.<ext>. Replaces any prior file sharing the same
 * base name. Returns the stored filename, or '' on failure.
 */
function artist_save_upload(array $file, string $slug, string $baseName): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return '';
    }
    if (($file['size'] ?? 0) > 15 * 1024 * 1024) {
        return '';
    }
    $ext = artist_validate_image($file);
    if ($ext === '') {
        return '';
    }

    $mediaDir = ARTISTS_DIR . '/' . $slug . '/media';
    if (!is_dir($mediaDir) && !mkdir($mediaDir, 0755, true)) {
        return '';
    }

    $filename = $baseName . '.' . $ext;
    foreach (glob($mediaDir . '/' . $baseName . '.*') ?: [] as $existing) {
        @unlink($existing);
    }
    if (!move_uploaded_file((string) $file['tmp_name'], $mediaDir . '/' . $filename)) {
        return '';
    }
    return $filename;
}

// ================================================================
// Intake invitation tokens
//
// The intake form (/novo-artista/<token>) is invite-only. The admin panel mints
// single-use tokens; only a valid, unused token can load the form or submit it.
// ================================================================

function intake_tokens_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS intake_tokens (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(64) NOT NULL,
        label VARCHAR(255) NULL,
        status ENUM('active','used','revoked') NOT NULL DEFAULT 'active',
        used_by_slug VARCHAR(120) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        used_at TIMESTAMP NULL,
        UNIQUE KEY uq_intake_tokens_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** A random invitation token (48 hex chars). */
function intake_token_generate(): string
{
    return bin2hex(random_bytes(24));
}

/** Mint a new active token, optionally with a label. Returns the token string. */
function intake_token_create(PDO $pdo, string $label = ''): string
{
    intake_tokens_ensure_schema($pdo);
    $token = intake_token_generate();
    $stmt = $pdo->prepare('INSERT INTO intake_tokens (token, label) VALUES (?, ?)');
    $stmt->execute([$token, $label]);
    return $token;
}

/** Load a token row by its string, or null. */
function intake_token_load(PDO $pdo, string $token): ?array
{
    intake_tokens_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM intake_tokens WHERE token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Whether a token exists and is still usable (active). */
function intake_token_is_active(PDO $pdo, string $token): bool
{
    if ($token === '') {
        return false;
    }
    $row = intake_token_load($pdo, $token);
    return $row !== null && ($row['status'] ?? '') === 'active';
}

/** Mark a token as used by a created artist slug. */
function intake_token_mark_used(PDO $pdo, string $token, string $slug): bool
{
    $stmt = $pdo->prepare("UPDATE intake_tokens SET status = 'used', used_by_slug = ?, used_at = NOW() WHERE token = ?");
    return $stmt->execute([$slug, $token]);
}

/** Revoke a token by id (admin). */
function intake_token_revoke(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare("UPDATE intake_tokens SET status = 'revoked' WHERE id = ?");
    return $stmt->execute([$id]);
}

/** All tokens, newest first (admin). */
function intake_token_list(PDO $pdo): array
{
    intake_tokens_ensure_schema($pdo);
    return $pdo->query('SELECT * FROM intake_tokens ORDER BY id DESC')->fetchAll();
}
