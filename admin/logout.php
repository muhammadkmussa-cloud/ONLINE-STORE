<?php
require_once __DIR__ . '/includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST required.');
}
require_csrf();
logout();
session_start();
flash('info', 'You have been logged out.');
admin_redirect('login.php');
