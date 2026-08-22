<?php
/**
 * Generic helper functions used throughout the panel.
 */
require_once __DIR__ . '/../config/database.php';

// ----------------------------------------------------------------
// Output / escaping
// ----------------------------------------------------------------
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Neutralize spreadsheet formula injection in CSV exports (OWASP):
 * prefix cells that begin with =, +, -, @, TAB or CR with a single quote.
 */
function csv_cell($value): string
{
    $value = (string)$value;
    if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
        return "'" . $value;
    }
    return $value;
}

/**
 * Build a literal-substring LIKE pattern: user %, _ and backslash are
 * neutralized so search input can't act as wildcards or scan whole tables.
 */
function like_pattern(string $value): string
{
    return '%' . addcslashes($value, '\\%_') . '%';
}

// ----------------------------------------------------------------
// Shared login throttling (admin + delivery portals)
// ----------------------------------------------------------------

/**
 * Clamp a requested page against the real page count and derive offset.
 */
function paginate(int $total, int $perPage, int $requestedPage): array
{
    $pages = max(1, (int)ceil($total / max(1, $perPage)));
    $page  = min(max(1, $requestedPage), $pages);
    return ['pages' => $pages, 'page' => $page, 'offset' => ($page - 1) * $perPage];
}

/**
 * Bootstrap pagination nav that preserves the current query-string filters
 * (?q=&category=&...) and only swaps ?page=. Renders nothing for 1 page.
 */
function render_pagination(int $page, int $pages, string $alignment = 'end'): void
{
    if ($pages <= 1) return;
    $qs = $_GET;
    $link = function (int $targetPage) use (&$qs): string {
        $qs['page'] = $targetPage;
        return '?' . e(http_build_query($qs));
    };
    echo '<nav class="mt-3"><ul class="pagination justify-content-' . e($alignment) . ' mb-0">';
    if ($page > 1) {
        echo '<li class="page-item"><a class="page-link" href="' . $link($page - 1) . '" aria-label="Previous">&laquo;</a></li>';
    }
    for ($pn = 1; $pn <= $pages; $pn++) {
        echo '<li class="page-item' . ($pn === $page ? ' active' : '')
            . '"><a class="page-link" href="' . $link($pn) . '">' . $pn . '</a></li>';
    }
    if ($page < $pages) {
        echo '<li class="page-item"><a class="page-link" href="' . $link($page + 1) . '" aria-label="Next">&raquo;</a></li>';
    }
    echo '</ul></nav>';
}

/**
 * Two-tier throttle: $strict failures per (email, IP) and $wide failures per
 * email across all sources, within a rolling 15-minute window. Fails closed
 * so a broken attempts table cannot open a brute-force window.
 * $table must be an internal literal ('admin_login_attempts' | 'driver_login_attempts').
 */
function auth_rate_limit_exceeded(string $table, string $email, string $ip, int $strict = 5, int $wide = 20): bool
{
    try {
        $table = str_replace('`', '', $table);
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM `$table`
             WHERE email = :email AND ip_address = :ip AND successful = 0
               AND attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)"
        );
        $stmt->execute([':email' => $email, ':ip' => $ip]);
        if ((int)$stmt->fetchColumn() >= $strict) {
            return true;
        }
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM `$table`
             WHERE email = :email AND successful = 0
               AND attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)"
        );
        $stmt->execute([':email' => $email]);
        return (int)$stmt->fetchColumn() >= $wide;
    } catch (Throwable $e) {
        return true;
    }
}

/** Record one login attempt and prune anything older than two days. */
function auth_record_login_attempt(string $table, string $email, string $ip, bool $successful): void
{
    try {
        $table = str_replace('`', '', $table);
        db()->prepare("DELETE FROM `$table` WHERE attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)")->execute();
        db()->prepare("INSERT INTO `$table` (email, ip_address, successful) VALUES (:email, :ip, :successful)")
            ->execute([':email' => $email, ':ip' => $ip, ':successful' => $successful ? 1 : 0]);
    } catch (Throwable $e) {
        // Attempt bookkeeping is best-effort; make failures visible in logs.
        error_log('Login attempt record failed: ' . $e->getMessage());
    }
}

function url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

/**
 * URL for a local static asset with automatic cache-busting: the file's
 * mtime becomes ?v= so deploys invalidate browsers without config.
 */
function asset_url(string $path): string
{
    $full = ROOT_PATH . '/' . ltrim($path, '/');
    $version = is_file($full) ? (string)filemtime($full) : '1';
    $base = url($path);
    return $base . (strpos($base, '?') === false ? '?' : '&') . 'v=' . rawurlencode($version);
}

function admin_url(string $path = ''): string
{
    return ADMIN_URL . ltrim($path, '/');
}

/**
 * Redirect to a path relative to the project root (BASE_URL).
 * For admin pages use admin_redirect() instead.
 */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/** Redirect to a path relative to the admin panel (ADMIN_URL). */
function admin_redirect(string $path): void
{
    header('Location: ' . admin_url($path));
    exit;
}

// ----------------------------------------------------------------
// CSRF
// ----------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    return !empty($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && !csrf_verify($_POST['_csrf'] ?? null)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

// ----------------------------------------------------------------
// Flash messages
// ----------------------------------------------------------------
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

// ----------------------------------------------------------------
// Validation
// ----------------------------------------------------------------
function input(string $key, $default = ''): string
{
    return trim((string)($_POST[$key] ?? $_GET[$key] ?? $default));
}

function old(string $key, $default = ''): string
{
    return e($_SESSION['_old'][$key] ?? $default);
}

function set_old(array $data): void
{
    $_SESSION['_old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

// ----------------------------------------------------------------
// Settings (key/value table)
// ----------------------------------------------------------------
function settings_cache_clear(): void
{
    $GLOBALS['__settings_cache'] = null;
}

function setting(string $key, ?string $default = null): ?string
{
    if (!array_key_exists('__settings_cache', $GLOBALS) || $GLOBALS['__settings_cache'] === null) {
        $GLOBALS['__settings_cache'] = [];
        try {
            $rows = db()->query('SELECT key_name, value FROM settings')->fetchAll();
            foreach ($rows as $r) {
                $GLOBALS['__settings_cache'][$r['key_name']] = $r['value'];
            }
        } catch (Throwable $e) {
            error_log('Settings load failed (table may not exist yet): ' . $e->getMessage());
            // Settings table may not exist yet during install.
        }
    }
    return $GLOBALS['__settings_cache'][$key] ?? $default;
}

function update_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (key_name, value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    $stmt->execute([':k' => $key, ':v' => $value]);
    if (isset($GLOBALS['__settings_cache']) && is_array($GLOBALS['__settings_cache'])) {
        $GLOBALS['__settings_cache'][$key] = $value;
    }
}

// ----------------------------------------------------------------
// Activity log
// ----------------------------------------------------------------
function log_activity(string $action, string $description = ''): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_log (user_id, action, description, ip_address)
             VALUES (:uid, :action, :desc, :ip)'
        );
        $stmt->execute([
            ':uid'    => $_SESSION['user']['id'] ?? null,
            ':action' => $action,
            ':desc'   => $description,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Logging must never break the request, but it must be observable.
        error_log('Activity log write failed: ' . $e->getMessage());
    }
}

// ----------------------------------------------------------------
// Misc UI helpers
// ----------------------------------------------------------------
function active_if(bool $condition, string $class = 'active'): string
{
    return $condition ? $class : '';
}

function format_date(?string $datetime, string $format = 'M j, Y H:i'): string
{
    if (empty($datetime)) return '—';
    $ts = strtotime($datetime);
    return $ts ? date($format, $ts) : e($datetime);
}

function avatar_url(?string $avatar, string $name = ''): string
{
    if (!empty($avatar) && file_exists(UPLOADS_PATH . '/' . $avatar)) {
        return UPLOADS_URL . $avatar;
    }
    // Local fallback: initials rendered as an inline SVG data URI. Names are
    // never sent to third-party avatar services.
    $initials = strtoupper(mb_substr(trim($name) !== '' ? $name : 'U', 0, 1));
    if (preg_match('/\S\.\S|\s/', trim($name))) {
        $parts = preg_split('/\s+|(?<=\.)\s*/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $initials = strtoupper(mb_substr($parts[0] ?? 'U', 0, 1)
            . mb_substr($parts[1] ?? '', 0, 1));
    }
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128">'
        . '<rect width="128" height="128" fill="#4f46e5"/>'
        . '<text x="64" y="64" dy=".35em" text-anchor="middle" '
        . 'font-family="system-ui,sans-serif" font-size="56" fill="#fff">'
        . htmlspecialchars($initials, ENT_XML1) . '</text></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

// ----------------------------------------------------------------
// E-commerce helpers
// ----------------------------------------------------------------

/** Convert a string to a URL-safe slug. */
function slugify(string $text): string
{
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT//IGNORE', $text) ?: $text;
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = strtolower(trim($text, '-'));
    $text = preg_replace('~-+~', '-', $text);
    return $text ?: 'n-a';
}

/** Make a slug unique within a table by appending -1, -2, ... if needed. */
function unique_slug(string $base, string $table, int $excludeId = 0): string
{
    // Table identifiers cannot be parameterized: restrict to known tables.
    $allowedTables = ['products', 'categories'];
    if (!in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Unsupported table for slug generation.');
    }
    $slug = $base;
    $i    = 1;
    $sql  = "SELECT id FROM `$table` WHERE slug = :s AND id <> :id LIMIT 1";
    while (true) {
        $stmt = db()->prepare($sql);
        $stmt->execute([':s' => $slug, ':id' => $excludeId]);
        if (!$stmt->fetch()) return $slug;
        $slug = $base . '-' . (++$i);
    }
}

/** Format a numeric price using the configured currency symbol. */
function price(?float $amount): string
{
    $symbol = trim((string)setting('currency_symbol', '$'));
    if ($amount === null) return '—';
    $formatted = number_format((float)$amount, 2);
    return $symbol !== '' ? $symbol . ' ' . $formatted : $formatted;
}

/**
 * Translate an uploaded-file error code into a human-readable message.
 * See https://www.php.net/manual/en/features.file-upload.errors.php
 */
function upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_OK:         return '';
        case UPLOAD_ERR_INI_SIZE:   return 'File is larger than the server allows (upload_max_filesize).';
        case UPLOAD_ERR_FORM_SIZE:  return 'File is larger than the form allows.';
        case UPLOAD_ERR_PARTIAL:    return 'File was only partially uploaded — please retry.';
        case UPLOAD_ERR_NO_FILE:    return 'No file was selected.';
        case UPLOAD_ERR_NO_TMP_DIR: return 'Server is missing a temporary upload folder.';
        case UPLOAD_ERR_CANT_WRITE: return 'Server could not write the file to disk.';
        case UPLOAD_ERR_EXTENSION:  return 'A PHP extension blocked the upload.';
    }
    return 'Unknown upload error.';
}

/** Return URL to product image (or a placeholder). */
function product_image_url(?string $image): string
{
    $safeImage = (string)($image ?? '');
    if ($safeImage !== ''
        && basename($safeImage) === $safeImage
        && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/', $safeImage) === 1
        && file_exists(UPLOADS_PATH . '/products/' . $safeImage)) {
        return UPLOADS_URL . 'products/' . $safeImage;
    }
    // SVG placeholder.
    return 'data:image/svg+xml;utf8,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' .
        '<rect width="100" height="100" fill="#e5e7eb"/>' .
        '<text x="50" y="55" text-anchor="middle" fill="#9ca3af" ' .
        'font-family="sans-serif" font-size="12">No image</text></svg>'
    );
}
