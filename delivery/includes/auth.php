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
            "SELECT status FROM users WHERE id = :id AND role = 'delivery_driver' LIMIT 1"
        );
        $stmt->execute([':id' => (int)$_SESSION['driver']['id']]);
        if ($stmt->fetchColumn() !== 'active') {
            logout_driver();
            flash('danger', 'Your driver account is disabled.');
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
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM driver_login_attempts
             WHERE email = :email AND ip_address = :ip AND successful = 0
               AND attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)"
        );
        $stmt->execute([':email' => $email, ':ip' => $ip]);
        return (int)$stmt->fetchColumn() >= 5;
    } catch (Throwable $e) {
        // Fail closed when the rate-limit store is unavailable.
        return true;
    }
}

function record_driver_login_attempt(string $email, string $ip, bool $successful): void
{
    try {
        db()->prepare('DELETE FROM driver_login_attempts WHERE attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)')
            ->execute();
        db()->prepare(
            'INSERT INTO driver_login_attempts (email, ip_address, successful) VALUES (:email, :ip, :successful)'
        )->execute([
            ':email' => $email,
            ':ip' => $ip,
            ':successful' => $successful ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        // Login result must not expose database details.
    }
}

/** Return ok, invalid, or rate_limited without revealing account existence. */
function attempt_driver_login(string $email, string $password): string
{
    $email = strtolower(trim($email));
    $ip = driver_login_ip();
    if (driver_login_is_rate_limited($email, $ip)) return 'rate_limited';

    $stmt = db()->prepare(
        "SELECT id, name, email, password, role, status, phone
         FROM users WHERE email = :email AND role = 'delivery_driver' LIMIT 1"
    );
    $stmt->execute([':email' => $email]);
    $driver = $stmt->fetch();
    if (!$driver || $driver['status'] !== 'active'
        || !password_verify($password, $driver['password'])) {
        record_driver_login_attempt($email, $ip, false);
        return 'invalid';
    }

    record_driver_login_attempt($email, $ip, true);
    session_regenerate_id(true);
    unset($_SESSION['user']);
    $_SESSION['driver'] = [
        'id' => (int)$driver['id'],
        'name' => $driver['name'],
        'email' => $driver['email'],
        'role' => 'delivery_driver',
        'phone' => $driver['phone'],
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
