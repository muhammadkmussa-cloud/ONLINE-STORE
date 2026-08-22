<?php
/**
 * Authentication helpers: login, logout, role checks.
 */
require_once __DIR__ . '/../../includes/functions.php';

function admin_login_is_rate_limited(string $email, string $ip): bool
{
    return auth_rate_limit_exceeded('admin_login_attempts', $email, $ip);
}

function record_admin_login_attempt(string $email, string $ip, bool $successful): void
{
    auth_record_login_attempt('admin_login_attempts', $email, $ip, $successful);
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

    if (!$user) {
        // Burn the same bcrypt cost as a real check so response timing cannot
        // reveal whether an email exists in the admin panel.
        password_verify($password, '$2y$10$zg4KxXlio1BYDLP4HFvEVuG7YfsY16C5q4O2FFOWDsrfUzIb6uIxO');
        record_admin_login_attempt($email, $ip, false);
        $_SESSION['_admin_login_status'] = 'invalid';
        return false;
    }

    if (!password_verify($password, $user['password']) || $user['role'] === 'delivery_driver') {
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
    if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        db()->prepare('UPDATE users SET password = :p WHERE id = :id')
            ->execute([':p' => $newHash, ':id' => $user['id']]);
    }

    record_admin_login_attempt($email, $ip, true);
    unset($_SESSION['_admin_login_status']);
    // Mirror the driver portal: clear any cross-portal session state.
    unset($_SESSION['driver']);

    // Prevent session fixation and rotate the CSRF token for the
    // new authenticated session.
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);

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
    // Idle timeout mirrors the delivery portal: an unattended staff session
    // expires after two hours without any request.
    if (!empty($_SESSION['user_last_activity'])
        && time() - (int)$_SESSION['user_last_activity'] > 7200) {
        // Clear auth state without session_destroy() so the flash message
        // survives into the login page (same approach as logout_driver()).
        log_activity('logout', 'Session expired due to inactivity');
        unset($_SESSION['user'], $_SESSION['user_last_activity']);
        flash('warning', 'Your session expired due to inactivity. Please log in again.');
        admin_redirect('login.php');
    }
    $_SESSION['user_last_activity'] = time();
}

function require_role(string ...$roles): void
{
    require_login();
    if (!has_role(...$roles)) {
        // Redirect to the public login page, never to a guarded page:
        // bouncing non-staff roles back into the panel would loop.
        http_response_code(403);
        flash('danger', 'You do not have permission to access that page.');
        admin_redirect('login.php');
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
