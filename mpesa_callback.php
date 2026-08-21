<?php
/**
 * Safaricom Daraja STK Push callback endpoint.
 * Configure MPESA_CALLBACK_URL to the public HTTPS URL of this file.
 */
require_once __DIR__ . '/includes/mpesa.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'POST required']);
    exit;
}

$config = mpesa_config();
$providedToken = (string)($_GET['token'] ?? '');
if ($config['callback_token'] === '' || $providedToken === ''
    || !hash_equals($config['callback_token'], $providedToken)) {
    http_response_code(403);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Forbidden']);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 200000) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid payload']);
    exit;
}

$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid JSON']);
    exit;
}

try {
    mpesa_process_callback($payload);
    // Daraja expects a successful HTTP response after the callback is handled.
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
} catch (MpesaException $e) {
    // A valid but malformed/unknown callback should not be retried forever.
    error_log('M-Pesa callback rejected: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Callback received']);
} catch (Throwable $e) {
    // A database/server failure is retryable; do not acknowledge it as handled.
    error_log('M-Pesa callback processing failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Temporary processing failure']);
}
