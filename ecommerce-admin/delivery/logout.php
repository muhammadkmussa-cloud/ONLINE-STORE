<?php
require_once __DIR__ . '/includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST required.');
}
require_csrf();
logout_driver();
flash('success', 'You have been signed out.');
header('Location: ' . driver_url('login.php'));
exit;
