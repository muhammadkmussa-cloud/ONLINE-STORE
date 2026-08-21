<?php
/**
 * Authentication helpers: login, logout, role checks.
 */
require_once __DIR__ . '/../../includes/functions.php';

function admin_login_is_rate_limited(string $email, string $ip): bool
{
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM admin_login_attempts
             WHERE email = :email AND ip_address = :ip AND successful = 0
               AND attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)"
        );
        $stmt->execute([':email' => $email, ':ip' => $ip]);
        return (int)$stmt->fetchColumn() >= 5;
    } catch (Throwable $e) {
        return true;
    }
}

function record_admin_login_attempt(string $email, string $ip, bool $successful): void
{
    try {
        db()->prepare('DELETE FROM admin_login_attempts WHERE attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)')->execute();
        db()->prepare(
            'INSERT INTO admin_login_attempts (email, ip_address, successful) VALUES (:email, :ip, :successful)'
        )->execute([':email' => $email, ':ip' => $ip, ':successful' => $successful ? 1 : 0]);
    } catch (Throwable $e) {}
}

function attempt_login(string $email, string $password): bool
{
    $email = strtolower(trim($email));
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    if (admin_login_is_rate_limited($email, $ip)) {
        $_SESSION['_admin_login_status'] = 'rate_limited';
        return false;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password']) || $user['role'] === 'delivery_driver') {
        record_admin_login_attempt($email, $ip, false);
        $_SESSION['_admin_login_status'] = 'invalid';
        return false;
    }
    if ($user['status'] !== 'active') {
        record_admin_login_attempt($email, $ip, false);
        $_SESSION['_admin_login_status'] = 'invalid';
        return false;
    }

    // Re-hash if PHP suggests a stronger algorithm.
    if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        db()->prepare('UPDATE users SET password = :p WHERE id = :id')
            ->execute([':p' => $newHash, ':id' => $user['id']]);
    }

    record_admin_login_attempt($email, $ip, true);
    unset($_SESSION['_admin_login_status']);

    // Prevent session fixation.
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'     => (int)$user['id'],
        'name'   => $user['name'],
        'email'  => $user['email'],
        'role'   => $user['role'],
        'avatar' => $user['avatar'],
    ];

    log_activity('login', 'User logged in');
    return true;
}

function logout(): void
{
    log_activity('logout', 'User logged out');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user']);
}

function has_role(string ...$roles): bool
{
    $user = current_user();
    return $user && in_array($user['role'], $roles, true);
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('warning', 'Please log in to continue.');
        admin_redirect('login.php');
    }
}

function require_role(string ...$roles): void
{
    require_login();
    if (!has_role(...$roles)) {
        http_response_code(403);
        flash('danger', 'You do not have permission to access that page.');
        admin_redirect('dashboard.php');
    }
}

/** Refresh the cached user record from DB (after profile changes). */
function refresh_user_session(): void
{
    if (!is_logged_in()) return;
    $stmt = db()->prepare('SELECT id, name, email, role, avatar FROM users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['user']['id']]);
    if ($u = $stmt->fetch()) {
        $_SESSION['user'] = [
            'id'     => (int)$u['id'],
            'name'   => $u['name'],
            'email'  => $u['email'],
            'role'   => $u['role'],
            'avatar' => $u['avatar'],
        ];
    }
}
