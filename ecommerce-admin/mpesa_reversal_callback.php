<?php
/**
 * Safaricom Daraja reversal ResultURL / QueueTimeOutURL callback.
 * Configure both MPESA_REVERSAL_RESULT_URL and
 * MPESA_REVERSAL_TIMEOUT_URL to this public HTTPS file.
 */
require_once __DIR__ . '/includes/mpesa.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'POST required']);
    exit;
}

$config = mpesa_config();
// Prefer a header token (kept out of access logs) but keep ?token= support
// because Daraja's portal configures the callback URL as one static string.
$headerToken = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Bearer\s+(.+)$/i', trim($headerToken), $m)) {
    $headerToken = trim($m[1]);
}
$providedToken = $headerToken !== '' ? $headerToken : (string)($_GET['token'] ?? '');
if ($config['reversal_callback_token'] === '' || $providedToken === ''
    || !hash_equals($config['reversal_callback_token'], $providedToken)) {
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
    $kind = (string)($_GET['kind'] ?? 'result');
    if (!in_array($kind, ['result', 'timeout'], true)) {
        throw new MpesaException('Invalid reversal callback kind.');
    }
    mpesa_process_reversal_callback($payload, $kind === 'timeout');
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
} catch (MpesaException $e) {
    error_log('M-Pesa reversal callback rejected: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Callback received']);
} catch (Throwable $e) {
    error_log('M-Pesa reversal callback processing failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Temporary processing failure']);
}
