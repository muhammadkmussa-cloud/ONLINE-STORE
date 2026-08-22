<?php
/** Authentication and rate limiting for the isolated delivery-driver portal. */
require_once __DIR__ . '/../../includes/functions.php';

function driver_url(string $path = ''): string
{
    return BASE_URL . 'delivery/' . ltrim($path, '/');
}

function current_driver(): ?array
{
    return $_SESSION['driver'] ?? null;
}

function driver_is_logged_in(): bool
{
    return !empty($_SESSION['driver'])
        && ($_SESSION['driver']['role'] ?? '') === 'delivery_driver';
}

function require_driver_login(): void
{
    if (!driver_is_logged_in()) {
        flash('warning', 'Please log in to the delivery portal.');
        header('Location: ' . driver_url('login.php'));
        exit;
    }
    if (!empty($_SESSION['driver_last_activity'])
        && time() - (int)$_SESSION['driver_last_activity'] > 7200) {
        logout_driver();
        flash('warning', 'Your delivery session expired. Please log in again.');
        header('Location: ' . driver_url('login.php'));
        exit;
    }
    try {
        $stmt = db()->prepare(
            "SELECT status, session_epoch FROM users WHERE id = :id AND role = 'delivery_driver' LIMIT 1"
        );
        $stmt->execute([':id' => (int)$_SESSION['driver']['id']]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'active') {
            logout_driver();
            flash('danger', 'Your driver account is disabled.');
            header('Location: ' . driver_url('login.php'));
            exit;
        }
        // A password reset bumps session_epoch: pre-reset sessions die here.
        if ((int)($_SESSION['driver']['session_epoch'] ?? 0) !== (int)$row['session_epoch']) {
            logout_driver();
            flash('warning', 'Your password was changed. Please log in again.');
            header('Location: ' . driver_url('login.php'));
            exit;
        }
    } catch (Throwable $e) {
        logout_driver();
        flash('danger', 'Your driver session could not be verified.');
        header('Location: ' . driver_url('login.php'));
        exit;
    }
    $_SESSION['driver_last_activity'] = time();
}

function driver_login_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function driver_login_is_rate_limited(string $email, string $ip): bool
{
    return auth_rate_limit_exceeded('driver_login_attempts', $email, $ip);
}

function record_driver_login_attempt(string $email, string $ip, bool $successful): void
{
    auth_record_login_attempt('driver_login_attempts', $email, $ip, $successful);
}

/** Return ok, invalid, or rate_limited without revealing account existence. */
function attempt_driver_login(string $email, string $password): string
{
    $email = strtolower(trim($email));
    $ip = driver_login_ip();
    if (driver_login_is_rate_limited($email, $ip)) return 'rate_limited';

    $stmt = db()->prepare(
        "SELECT id, name, email, password, role, status, phone, session_epoch
         FROM users WHERE email = :email AND role = 'delivery_driver' LIMIT 1"
    );
    $stmt->execute([':email' => $email]);
    $driver = $stmt->fetch();
    if (!$driver) {
        // Burn the same bcrypt cost as a real check so response timing cannot
        // reveal whether an email belongs to a driver account.
        password_verify($password, '$2y$10$zg4KxXlio1BYDLP4HFvEVuG7YfsY16C5q4O2FFOWDsrfUzIb6uIxO');
    }
    if (!$driver || $driver['status'] !== 'active'
        || !password_verify($password, $driver['password'])) {
        record_driver_login_attempt($email, $ip, false);
        return 'invalid';
    }

    record_driver_login_attempt($email, $ip, true);
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    unset($_SESSION['user']);
    $_SESSION['driver'] = [
        'id' => (int)$driver['id'],
        'name' => $driver['name'],
        'email' => $driver['email'],
        'role' => 'delivery_driver',
        'phone' => $driver['phone'],
        'session_epoch' => (int)($driver['session_epoch'] ?? 0),
    ];
    $_SESSION['driver_last_activity'] = time();
    log_activity('driver.login', 'Delivery driver logged in');
    return 'ok';
}

function logout_driver(): void
{
    if (driver_is_logged_in()) log_activity('driver.logout', 'Delivery driver logged out');
    unset($_SESSION['driver'], $_SESSION['driver_last_activity']);
}
