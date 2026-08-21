<?php
/**
 * Global application configuration.
 * Sensitive deployment values may be supplied through environment variables.
 */

if (!function_exists('app_config_env')) {
    function app_config_env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? ($_SERVER[$key] ?? $default);
        }
        return trim((string)$value);
    }
}

// ----- Database -----
define('DB_HOST', app_config_env('DB_HOST', '127.0.0.1'));
define('DB_PORT', app_config_env('DB_PORT', '3306'));
define('DB_NAME', app_config_env('DB_NAME', 'php_admin_panel'));
define('DB_USER', app_config_env('DB_USER', 'root'));
define('DB_PASS', app_config_env('DB_PASS', ''));
define('DB_CHARSET', app_config_env('DB_CHARSET', 'utf8mb4'));

// ----- App -----
define('APP_NAME', app_config_env('APP_NAME', 'PHP Admin Panel'));
define('APP_ENV', strtolower(app_config_env('APP_ENV', 'development')) === 'production' ? 'production' : 'development');

// Auto-detect project root URL so it works whether the request is for /
// (storefront), /admin/ (admin panel) or any other sub-folder.
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$scriptDir = rtrim($scriptDir, '/');
// If the request is inside /admin/ or /delivery/ (or any deeper sub-folder),
// climb back to the project root.
if (preg_match('#/(admin|delivery)(/.*)?$#', $scriptDir)) {
    $scriptDir = preg_replace('#/(admin|delivery)(/.*)?$#', '', $scriptDir);
}
define('BASE_URL',  ($scriptDir === '' ? '/' : $scriptDir . '/'));
define('ADMIN_URL', BASE_URL . 'admin/');

// ----- Paths -----
define('ROOT_PATH',     dirname(__DIR__));
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('UPLOADS_PATH',  ROOT_PATH . '/assets/uploads');
define('UPLOADS_URL',   BASE_URL . 'assets/uploads/');

// ----- Error handling -----
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// ----- Secure session -----
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || APP_ENV === 'production';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $isHttps ? '1' : '0');
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Baseline browser hardening. Production HTTPS additionally receives HSTS.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (APP_ENV === 'production' && $isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

date_default_timezone_set(app_config_env('APP_TIMEZONE', 'UTC'));
