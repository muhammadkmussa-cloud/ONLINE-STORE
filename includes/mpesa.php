<?php
/**
 * Safaricom Daraja / Lipa na M-Pesa Online helpers.
 *
 * Credentials are read from server environment variables only. Do not store
 * consumer secrets or passkeys in the settings table or in source control.
 */
require_once __DIR__ . '/functions.php';

if (!class_exists('MpesaException')) {
    class MpesaException extends RuntimeException
    {
        /** @var bool */
        private $transient;

        public function __construct(string $message, bool $transient = false)
        {
            parent::__construct($message);
            $this->transient = $transient;
        }

        public function isTransient(): bool
        {
            return $this->transient;
        }
    }
}

/** Read an environment variable without ever falling back to a database value. */
function mpesa_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_ENV[$key] ?? ($_SERVER[$key] ?? $default);
    }
    return trim((string)$value);
}

/** Return the Daraja configuration derived from server-side environment variables. */
function mpesa_config(): array
{
    static $config = null;
    if ($config !== null) return $config;

    $environment = strtolower(mpesa_env('MPESA_ENVIRONMENT', 'sandbox'));
    if (!in_array($environment, ['sandbox', 'production'], true)) {
        $environment = 'sandbox';
    }

    $baseUrl = $environment === 'production'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';

    $shortcode = mpesa_env('MPESA_SHORTCODE');
    $config = [
        'environment'      => $environment,
        'base_url'         => rtrim(mpesa_env('MPESA_BASE_URL', $baseUrl), '/'),
        'consumer_key'     => mpesa_env('MPESA_CONSUMER_KEY'),
        'consumer_secret'  => mpesa_env('MPESA_CONSUMER_SECRET'),
        'shortcode'        => $shortcode,
        'party_b'          => mpesa_env('MPESA_PARTY_B', $shortcode),
        'transaction_type' => mpesa_env('MPESA_TRANSACTION_TYPE', 'CustomerPayBillOnline'),
        'passkey'          => mpesa_env('MPESA_PASSKEY'),
        'callback_url'     => mpesa_env('MPESA_CALLBACK_URL'),
        'callback_token'   => mpesa_env('MPESA_CALLBACK_TOKEN'),
        // Reversal credentials are separate from STK credentials. The
        // SecurityCredential must be the Safaricom-encrypted initiator
        // credential, never a plaintext password.
        'initiator_name'  => mpesa_env('MPESA_INITIATOR_NAME'),
        'security_credential' => mpesa_env('MPESA_SECURITY_CREDENTIAL'),
        'reversal_result_url' => mpesa_env('MPESA_REVERSAL_RESULT_URL'),
        'reversal_timeout_url' => mpesa_env('MPESA_REVERSAL_TIMEOUT_URL'),
        'reversal_callback_token' => mpesa_env(
            'MPESA_REVERSAL_CALLBACK_TOKEN',
            mpesa_env('MPESA_CALLBACK_TOKEN')
        ),
        'receiver_identifier_type' => mpesa_env('MPESA_REVERSAL_RECEIVER_IDENTIFIER_TYPE', '4'),
        'timeout'          => max(5, min(60, (int)mpesa_env('MPESA_TIMEOUT_SECONDS', '20'))),
    ];

    return $config;
}

/** Explain why the payment method is not safe to expose at checkout. */
function mpesa_configuration_errors(): array
{
    $config = mpesa_config();
    $errors = [];

    foreach ([
        'consumer_key'    => 'MPESA_CONSUMER_KEY',
        'consumer_secret' => 'MPESA_CONSUMER_SECRET',
        'shortcode'       => 'MPESA_SHORTCODE',
        'passkey'         => 'MPESA_PASSKEY',
        'callback_url'    => 'MPESA_CALLBACK_URL',
        'callback_token'  => 'MPESA_CALLBACK_TOKEN',
    ] as $field => $envName) {
        if ($config[$field] === '') $errors[] = $envName . ' is missing.';
    }

    if ($config['shortcode'] !== '' && !preg_match('/^\d{5,10}$/', $config['shortcode'])) {
        $errors[] = 'MPESA_SHORTCODE must contain only 5 to 10 digits.';
    }
    if ($config['party_b'] !== '' && !preg_match('/^\d{5,10}$/', $config['party_b'])) {
        $errors[] = 'MPESA_PARTY_B must contain only 5 to 10 digits.';
    }
    if (!in_array($config['transaction_type'], ['CustomerPayBillOnline', 'CustomerBuyGoodsOnline'], true)) {
        $errors[] = 'MPESA_TRANSACTION_TYPE must be CustomerPayBillOnline or CustomerBuyGoodsOnline.';
    }
    if ($config['base_url'] !== ''
        && !preg_match('#^https://#i', $config['base_url'])) {
        $errors[] = 'MPESA_BASE_URL must use HTTPS.';
    }
    if ($config['callback_url'] !== ''
        && !preg_match('#^https://#i', $config['callback_url'])) {
        $errors[] = 'MPESA_CALLBACK_URL must use HTTPS.';
    }
    if ($config['callback_token'] !== '' && strlen($config['callback_token']) < 16) {
        $errors[] = 'MPESA_CALLBACK_TOKEN must be at least 16 characters.';
    }
    if (!function_exists('curl_init')) {
        $errors[] = 'The PHP cURL extension is required for M-Pesa.';
    }

    // Daraja amounts are Kenyan shillings and are submitted as whole units.
    if (strtoupper((string)setting('currency_code', '')) !== 'KES') {
        $errors[] = 'Set the store currency code to KES before enabling M-Pesa.';
    }

    return $errors;
}

function mpesa_enabled_by_admin(): bool
{
    return setting('mpesa_enabled', '0') === '1';
}

/** True only when the admin enabled M-Pesa and all safety checks pass. */
function mpesa_is_available(): bool
{
    return mpesa_enabled_by_admin() && !mpesa_configuration_errors();
}

/** Configuration required for an admin-initiated Daraja reversal. */
function mpesa_reversal_configuration_errors(): array
{
    $config = mpesa_config();
    $errors = mpesa_configuration_errors();

    foreach ([
        'initiator_name'       => 'MPESA_INITIATOR_NAME',
        'security_credential'  => 'MPESA_SECURITY_CREDENTIAL',
        'reversal_result_url'  => 'MPESA_REVERSAL_RESULT_URL',
        'reversal_timeout_url' => 'MPESA_REVERSAL_TIMEOUT_URL',
    ] as $field => $envName) {
        if ($config[$field] === '') $errors[] = $envName . ' is missing.';
    }

    foreach (['reversal_result_url', 'reversal_timeout_url'] as $field) {
        if ($config[$field] !== '' && !preg_match('#^https://#i', $config[$field])) {
            $errors[] = 'MPESA_' . strtoupper($field) . ' must use HTTPS.';
        }
    }
    if ($config['reversal_callback_token'] === '') {
        $errors[] = 'MPESA_REVERSAL_CALLBACK_TOKEN or MPESA_CALLBACK_TOKEN is missing.';
    } elseif (strlen($config['reversal_callback_token']) < 16) {
        $errors[] = 'The M-Pesa reversal callback token must be at least 16 characters.';
    }
    if (!preg_match('/^\d+$/', $config['receiver_identifier_type'])) {
        $errors[] = 'MPESA_REVERSAL_RECEIVER_IDENTIFIER_TYPE must be numeric.';
    }

    return array_values(array_unique($errors));
}

function mpesa_reversal_is_available(): bool
{
    // Checkout visibility is controlled by mpesa_enabled, but an existing
    // successful payment must remain refundable even if new M-Pesa checkouts
    // have since been disabled.
    return !mpesa_reversal_configuration_errors();
}

function mpesa_environment_label(): string
{
    return mpesa_config()['environment'] === 'production' ? 'Production' : 'Sandbox';
}

/**
 * Append a secret token to the callback URL. Daraja does not provide a
 * webhook signature for STK callbacks, so the unguessable URL token is an
 * additional application-level guard.
 */
function mpesa_callback_url(): string
{
    $config = mpesa_config();
    $separator = strpos($config['callback_url'], '?') === false ? '?' : '&';
    return $config['callback_url'] . $separator
        . 'token=' . rawurlencode($config['callback_token']);
}

/** Normalize common Kenyan mobile formats to 254XXXXXXXXX. */
function mpesa_normalize_phone(string $phone): ?string
{
    $phone = preg_replace('/[^0-9+]/', '', trim($phone));
    if ($phone === '') return null;

    if (strpos($phone, '+') === 0) $phone = substr($phone, 1);
    if (strpos($phone, '00') === 0) $phone = substr($phone, 2);
    if (strpos($phone, '0') === 0) $phone = '254' . substr($phone, 1);
    if (preg_match('/^[17]\d{8}$/', $phone)) $phone = '254' . $phone;

    return preg_match('/^254[17]\d{8}$/', $phone) ? $phone : null;
}

/** Daraja requires a whole-number KES amount. Never silently round a charge. */
function mpesa_amount_from_order($amount): int
{
    $amount = (float)$amount;
    $rounded = (int)round($amount);
    if ($rounded < 1) {
        throw new MpesaException('M-Pesa cannot charge an order with a zero total.');
    }
    if (abs($amount - $rounded) > 0.0001) {
        throw new MpesaException('M-Pesa orders must have a whole-number KES total.');
    }
    return $rounded;
}

function mpesa_timestamp(): string
{
    $now = new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi'));
    return $now->format('YmdHis');
}

function mpesa_password(string $shortcode, string $passkey, string $timestamp): string
{
    return base64_encode($shortcode . $passkey . $timestamp);
}

/** Make one strict JSON HTTP request to Daraja using cURL and TLS verification. */
function mpesa_http_json(
    string $method,
    string $url,
    array $headers = [],
    ?array $body = null,
    int $timeout = 20
): array {
    if (!function_exists('curl_init')) {
        throw new MpesaException('The PHP cURL extension is required for M-Pesa.');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new MpesaException('Could not initialize the M-Pesa connection.', true);
    }

    $requestHeaders = array_merge(['Accept: application/json'], $headers);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => $requestHeaders,
    ];

    if (strtoupper($method) === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new MpesaException(
            'M-Pesa could not be reached. Please try again shortly.',
            true
        );
    }

    $response = json_decode((string)$raw, true);
    if (!is_array($response)) {
        throw new MpesaException(
            'M-Pesa returned an invalid response' . ($curlError ? ': ' . $curlError : '.') ,
            $httpCode === 0 || $httpCode >= 500
        );
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = $response['errorMessage']
            ?? $response['ResponseDescription']
            ?? 'M-Pesa rejected the request.';
        throw new MpesaException((string)$message, $httpCode >= 500 || $httpCode === 429);
    }

    return $response;
}

function mpesa_access_token(): string
{
    $config = mpesa_config();
    $errors = mpesa_configuration_errors();
    if ($errors) {
        throw new MpesaException('M-Pesa is not configured: ' . implode(' ', $errors));
    }

    $credentials = base64_encode($config['consumer_key'] . ':' . $config['consumer_secret']);
    $response = mpesa_http_json(
        'GET',
        $config['base_url'] . '/oauth/v1/generate?grant_type=client_credentials',
        ['Authorization: Basic ' . $credentials],
        null,
        $config['timeout']
    );

    if (empty($response['access_token'])) {
        throw new MpesaException('M-Pesa did not return an access token.', true);
    }
    return (string)$response['access_token'];
}

/** Initiate one Lipa na M-Pesa Online / STK Push request. */
function mpesa_initiate_stk(array $order, string $phone): array
{
    $config = mpesa_config();
    $phone = mpesa_normalize_phone($phone);
    if ($phone === null) {
        throw new MpesaException('Enter a valid Kenyan M-Pesa phone number.');
    }

    $amount = mpesa_amount_from_order($order['total'] ?? 0);
    $timestamp = mpesa_timestamp();
    $token = mpesa_access_token();
    $shortcode = $config['shortcode'];
    $accountReference = preg_replace('/[^A-Za-z0-9]/', '', (string)$order['order_number']);
    $accountReference = substr($accountReference, -12);

    $payload = [
        'BusinessShortCode' => $shortcode,
        'Password'          => mpesa_password($shortcode, $config['passkey'], $timestamp),
        'Timestamp'         => $timestamp,
        'TransactionType'   => $config['transaction_type'],
        'Amount'            => $amount,
        'PartyA'            => $phone,
        'PartyB'            => $config['party_b'],
        'PhoneNumber'       => $phone,
        'CallBackURL'       => mpesa_callback_url(),
        'AccountReference'  => $accountReference,
        'TransactionDesc'   => 'Order ' . substr((string)$order['order_number'], -7),
    ];

    $response = mpesa_http_json(
        'POST',
        $config['base_url'] . '/mpesa/stkpush/v1/processrequest',
        [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        $payload,
        $config['timeout']
    );

    if ((string)($response['ResponseCode'] ?? '') !== '0'
        || empty($response['CheckoutRequestID'])) {
        $message = $response['ResponseDescription']
            ?? $response['errorMessage']
            ?? 'M-Pesa did not accept the payment request.';
        throw new MpesaException((string)$message);
    }

    return [
        'response' => $response,
        'phone'    => $phone,
        'amount'   => $amount,
    ];
}

/** Optional server-side fallback query for a pending STK request. */
function mpesa_query_stk(string $checkoutRequestId): array
{
    $checkoutRequestId = trim($checkoutRequestId);
    if ($checkoutRequestId === '') {
        throw new MpesaException('Missing M-Pesa checkout request ID.');
    }

    $config = mpesa_config();
    $timestamp = mpesa_timestamp();
    $token = mpesa_access_token();
    $payload = [
        'BusinessShortCode' => $config['shortcode'],
        'Password'          => mpesa_password($config['shortcode'], $config['passkey'], $timestamp),
        'Timestamp'         => $timestamp,
        'CheckoutRequestID' => $checkoutRequestId,
    ];

    return mpesa_http_json(
        'POST',
        $config['base_url'] . '/mpesa/stkpushquery/v1/query',
        [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        $payload,
        $config['timeout']
    );
}

function mpesa_reversal_callback_url(string $kind): string
{
    if (!in_array($kind, ['result', 'timeout'], true)) {
        throw new InvalidArgumentException('Invalid reversal callback kind.');
    }
    $config = mpesa_config();
    $url = $kind === 'timeout'
        ? $config['reversal_timeout_url']
        : $config['reversal_result_url'];
    $separator = strpos($url, '?') === false ? '?' : '&';
    return $url . $separator . http_build_query([
        'token' => $config['reversal_callback_token'],
        'kind'  => $kind,
    ]);
}

/** Initiate an asynchronous Daraja reversal for an already-successful payment. */
function mpesa_initiate_reversal(array $payment, int $amount, string $reason): array
{
    $errors = mpesa_reversal_configuration_errors();
    if ($errors) {
        throw new MpesaException('M-Pesa reversal is not configured: ' . implode(' ', $errors));
    }
    if (($payment['status'] ?? '') !== 'successful'
        || empty($payment['provider_transaction_id'])) {
        throw new MpesaException('Only a verified M-Pesa payment can be reversed.');
    }

    $amount = mpesa_amount_from_order($amount);
    $config = mpesa_config();
    $token = mpesa_access_token();
    $reason = trim(preg_replace('/[^A-Za-z0-9 .,_\-]/', '', $reason));
    if ($reason === '') $reason = 'Customer order refund';

    $payload = [
        'Initiator'              => $config['initiator_name'],
        'SecurityCredential'    => $config['security_credential'],
        'CommandID'             => 'TransactionReversal',
        'TransactionID'          => (string)$payment['provider_transaction_id'],
        'Amount'                 => $amount,
        'ReceiverParty'         => $config['shortcode'],
        'RecieverIdentifierType' => $config['receiver_identifier_type'],
        'ResultURL'              => mpesa_reversal_callback_url('result'),
        'QueueTimeOutURL'        => mpesa_reversal_callback_url('timeout'),
        'Remarks'                => substr($reason, 0, 100),
        'Occasion'               => 'Online order refund',
    ];

    $response = mpesa_http_json(
        'POST',
        $config['base_url'] . '/mpesa/reversal/v1/request',
        [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        $payload,
        $config['timeout']
    );

    $responseCode = isset($response['ResponseCode']) ? (string)$response['ResponseCode'] : null;
    if ($responseCode !== null && $responseCode !== '0') {
        $message = $response['ResponseDescription']
            ?? $response['errorMessage']
            ?? 'M-Pesa did not accept the reversal request.';
        throw new MpesaException((string)$message);
    }
    if (empty($response['OriginatorConversationID'])
        && empty($response['ConversationID'])) {
        throw new MpesaException('M-Pesa returned no reversal request reference.');
    }

    return ['response' => $response, 'amount' => $amount];
}

function mpesa_refund_status_label(string $status): string
{
    return [
        'requested'      => 'Refund requested',
        'processing'     => 'Refund processing',
        'successful'     => 'Refund successful',
        'refunded'       => 'Refunded',
        'failed'         => 'Refund failed',
        'unknown'        => 'Status unknown',
        'requires_review' => 'Requires review',
    ][$status] ?? ucfirst($status);
}

function mpesa_refund_status_class(string $status): string
{
    return [
        'requested'       => 'text-bg-info',
        'processing'      => 'text-bg-warning',
        'successful'      => 'text-bg-success',
        'refunded'        => 'text-bg-success',
        'failed'          => 'text-bg-danger',
        'unknown'         => 'text-bg-secondary',
        'requires_review' => 'text-bg-danger',
    ][$status] ?? 'text-bg-secondary';
}

function mpesa_find_refund_by_idempotency(string $key): ?array
{
    $stmt = db()->prepare(
        "SELECT r.*, u.name AS admin_name
         FROM mpesa_refunds r
         LEFT JOIN users u ON u.id = r.admin_user_id
         WHERE r.idempotency_key = :key
         LIMIT 1"
    );
    $stmt->execute([':key' => $key]);
    $refund = $stmt->fetch();
    return $refund ?: null;
}

function mpesa_refunds_for_payment(int $paymentId): array
{
    $stmt = db()->prepare(
        "SELECT r.*, u.name AS admin_name
         FROM mpesa_refunds r
         LEFT JOIN users u ON u.id = r.admin_user_id
         WHERE r.payment_id = :payment_id
         ORDER BY r.id DESC"
    );
    $stmt->execute([':payment_id' => $paymentId]);
    return $stmt->fetchAll();
}

function mpesa_refund_reserved_amount(int $paymentId): float
{
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM mpesa_refunds
         WHERE payment_id = :payment_id
           AND status IN ('requested','processing','successful','refunded','unknown','requires_review')"
    );
    $stmt->execute([':payment_id' => $paymentId]);
    return (float)$stmt->fetchColumn();
}

function mpesa_refundable_amount(array $payment): float
{
    return max(0.0, round(
        (float)$payment['amount'] - mpesa_refund_reserved_amount((int)$payment['id']),
        2
    ));
}

function mpesa_refund_idempotency_key(int $paymentId, string $token): string
{
    return hash('sha256', 'mpesa-refund:' . session_id() . ':' . $paymentId . ':' . $token);
}

/** Create a locked, auditable refund request without calling the provider. */
function mpesa_create_refund_request(
    int $paymentId,
    int $orderId,
    int $adminId,
    int $amount,
    string $reason,
    string $idempotencyKey
): array {
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT p.*, o.order_number
             FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.id = :payment_id AND p.order_id = :order_id AND p.provider = 'mpesa'
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([':payment_id' => $paymentId, ':order_id' => $orderId]);
        $payment = $stmt->fetch();
        if (!$payment) throw new MpesaException('Payment record not found.');
        if ($payment['status'] !== 'successful' || empty($payment['provider_transaction_id'])) {
            throw new MpesaException('Only a verified successful M-Pesa payment can be refunded.');
        }

        $existingStmt = $pdo->prepare(
            'SELECT * FROM mpesa_refunds WHERE idempotency_key = :idempotency_key LIMIT 1'
        );
        $existingStmt->execute([':idempotency_key' => $idempotencyKey]);
        $existing = $existingStmt->fetch();
        if ($existing) {
            $pdo->commit();
            return $existing;
        }

        $usedStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM mpesa_refunds
             WHERE payment_id = :payment_id
               AND status IN ('requested','processing','successful','refunded','unknown','requires_review')"
        );
        $usedStmt->execute([':payment_id' => $paymentId]);
        $used = (float)$usedStmt->fetchColumn();
        $remaining = (float)$payment['amount'] - $used;
        if ($amount < 1 || (float)$amount > $remaining + 0.0001) {
            throw new MpesaException(
                'Refund amount exceeds the remaining refundable amount of '
                . price(max(0.0, $remaining)) . '.'
            );
        }

        $reason = trim($reason);
        if ($reason === '') throw new MpesaException('A refund reason is required.');
        $stmt = $pdo->prepare(
            "INSERT INTO mpesa_refunds
              (payment_id, order_id, provider, idempotency_key, original_transaction_id,
               amount, currency_code, reason, status, admin_user_id, requested_at)
             VALUES
              (:payment_id, :order_id, 'mpesa', :idempotency_key, :original_transaction_id,
               :amount, :currency_code, :reason, 'requested', :admin_user_id, UTC_TIMESTAMP())"
        );
        $stmt->execute([
            ':payment_id'              => $paymentId,
            ':order_id'                => $orderId,
            ':idempotency_key'         => $idempotencyKey,
            ':original_transaction_id' => $payment['provider_transaction_id'],
            ':amount'                  => $amount,
            ':currency_code'           => $payment['currency_code'],
            ':reason'                  => substr($reason, 0, 2000),
            ':admin_user_id'           => $adminId,
        ]);
        $refundId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM mpesa_refunds WHERE id = :id');
        $stmt->execute([':id' => $refundId]);
        $refund = $stmt->fetch();
        $pdo->commit();
        return $refund;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function mpesa_claim_refund(int $refundId): bool
{
    $stmt = db()->prepare(
        "UPDATE mpesa_refunds SET status = 'processing', processing_at = UTC_TIMESTAMP()
         WHERE id = :id AND status = 'requested'"
    );
    $stmt->execute([':id' => $refundId]);
    return $stmt->rowCount() === 1;
}

function mpesa_mark_refund_processing(int $refundId, array $response): void
{
    $stmt = db()->prepare(
        "UPDATE mpesa_refunds SET
           status = 'processing',
           originator_conversation_id = :originator,
           conversation_id = :conversation,
           provider_response_code = :response_code,
           provider_result_desc = :description,
           processing_at = COALESCE(processing_at, UTC_TIMESTAMP())
         WHERE id = :id AND status IN ('requested', 'processing')"
    );
    $stmt->execute([
        ':originator'    => (string)($response['OriginatorConversationID'] ?? ''),
        ':conversation'  => (string)($response['ConversationID'] ?? ''),
        ':response_code' => (string)($response['ResponseCode'] ?? '0'),
        ':description'   => (string)($response['ResponseDescription'] ?? ''),
        ':id'            => $refundId,
    ]);
}

function mpesa_mark_refund_status(
    int $refundId,
    string $status,
    string $reason,
    ?array $callbackPayload = null,
    ?array $result = null
): void {
    $allowed = ['successful', 'refunded', 'failed', 'unknown', 'requires_review'];
    if (!in_array($status, $allowed, true)) {
        throw new InvalidArgumentException('Invalid M-Pesa refund status.');
    }
    $stmt = db()->prepare(
        "UPDATE mpesa_refunds SET
           status = :status,
           provider_result_code = :result_code,
           provider_transaction_id = COALESCE(:provider_transaction_id, provider_transaction_id),
           provider_result_desc = :result_desc,
           failure_reason = :reason,
           callback_payload = COALESCE(:payload, callback_payload),
           completed_at = UTC_TIMESTAMP()
         WHERE id = :id
           AND status NOT IN ('refunded','successful')"
    );
    $stmt->execute([
        ':status'                 => $status,
        ':result_code'            => $result ? (string)($result['ResultCode'] ?? '') : null,
        ':provider_transaction_id' => $result ? (string)($result['TransactionID'] ?? '') : null,
        ':result_desc'            => $result ? (string)($result['ResultDesc'] ?? '') : null,
        ':reason'                 => substr($reason, 0, 2000),
        ':payload'                => $callbackPayload ? json_encode($callbackPayload, JSON_UNESCAPED_SLASHES) : null,
        ':id'                     => $refundId,
    ]);
}

/** Apply a ResultURL or QueueTimeOutURL reversal callback idempotently. */
function mpesa_process_reversal_callback(array $payload, bool $isTimeout = false): array
{
    $result = $payload['Result'] ?? ($payload['result'] ?? null);
    if (!is_array($result)) throw new MpesaException('Invalid M-Pesa reversal callback payload.');

    $originator = trim((string)($result['OriginatorConversationID'] ?? ''));
    $conversation = trim((string)($result['ConversationID'] ?? ''));
    if ($originator === '' && $conversation === '') {
        throw new MpesaException('M-Pesa reversal callback is missing request references.');
    }

    $conditions = [];
    $params = [];
    if ($originator !== '') {
        $conditions[] = 'originator_conversation_id = :originator';
        $params[':originator'] = $originator;
    }
    if ($conversation !== '') {
        $conditions[] = 'conversation_id = :conversation';
        $params[':conversation'] = $conversation;
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT * FROM mpesa_refunds WHERE ' . implode(' OR ', $conditions) . ' LIMIT 1 FOR UPDATE'
        );
        $stmt->execute($params);
        $refund = $stmt->fetch();
        if (!$refund) {
            $pdo->commit();
            return ['handled' => false, 'reason' => 'unknown_refund_reference'];
        }
        if (in_array($refund['status'], ['refunded', 'successful'], true)) {
            $pdo->commit();
            return ['handled' => true, 'status' => $refund['status'], 'duplicate' => true];
        }

        $resultCode = (int)($result['ResultCode'] ?? -1);
        if ($isTimeout) {
            $status = 'requires_review';
            $reason = 'M-Pesa reversal queue timed out; verify the reversal manually.';
        } elseif ($resultCode === 0) {
            $status = 'refunded';
            $reason = 'M-Pesa reversal completed successfully.';
        } else {
            $status = 'failed';
            $reason = (string)($result['ResultDesc'] ?? 'M-Pesa reversal failed.');
        }

        $stmt = $pdo->prepare(
            "UPDATE mpesa_refunds SET
               status = :status,
               provider_result_code = :result_code,
               provider_transaction_id = :provider_transaction_id,
               provider_result_desc = :result_desc,
               failure_reason = :reason,
               callback_payload = :payload,
               completed_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND status NOT IN ('refunded','successful')"
        );
        $stmt->execute([
            ':status'                  => $status,
            ':result_code'             => (string)$resultCode,
            ':provider_transaction_id' => (string)($result['TransactionID'] ?? ''),
            ':result_desc'            => (string)($result['ResultDesc'] ?? ''),
            ':reason'                 => substr($reason, 0, 2000),
            ':payload'                 => json_encode($payload, JSON_UNESCAPED_SLASHES),
            ':id'                      => (int)$refund['id'],
        ]);
        $pdo->commit();
        return ['handled' => true, 'status' => $status];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function mpesa_payment_status_label(string $status): string
{
    return [
        'initiated'  => 'Initiation started',
        'pending'    => 'Awaiting M-Pesa confirmation',
        'successful' => 'Paid via M-Pesa',
        'failed'     => 'Payment failed',
        'cancelled'  => 'Payment cancelled',
        'expired'    => 'Payment expired',
    ][$status] ?? ucfirst($status);
}

function mpesa_payment_status_class(string $status): string
{
    return [
        'initiated'  => 'text-bg-info',
        'pending'    => 'text-bg-warning',
        'successful' => 'text-bg-success',
        'failed'     => 'text-bg-danger',
        'cancelled'  => 'text-bg-secondary',
        'expired'    => 'text-bg-secondary',
    ][$status] ?? 'text-bg-secondary';
}

function mpesa_find_payment_by_idempotency(string $key): ?array
{
    $stmt = db()->prepare(
        "SELECT p.*, o.order_number, o.customer_name, o.customer_email, o.total AS order_total
         FROM payments p
         INNER JOIN orders o ON o.id = p.order_id
         WHERE p.idempotency_key = :key
         LIMIT 1"
    );
    $stmt->execute([':key' => $key]);
    $payment = $stmt->fetch();
    return $payment ?: null;
}

function mpesa_find_payment_by_order_number(string $orderNumber): ?array
{
    $stmt = db()->prepare(
        "SELECT p.*, o.order_number, o.customer_name, o.customer_email,
                o.customer_phone, o.total AS order_total, o.status AS order_status
         FROM payments p
         INNER JOIN orders o ON o.id = p.order_id
         WHERE o.order_number = :order_number AND p.provider = 'mpesa'
         ORDER BY p.id DESC
         LIMIT 1"
    );
    $stmt->execute([':order_number' => $orderNumber]);
    $payment = $stmt->fetch();
    return $payment ?: null;
}

function mpesa_checkout_idempotency_key(string $checkoutToken): string
{
    return hash('sha256', 'mpesa-checkout:' . session_id() . ':' . $checkoutToken);
}

function mpesa_mark_pending(int $paymentId, array $response): void
{
    $stmt = db()->prepare(
        "UPDATE payments SET
           status = 'pending',
           merchant_request_id = :merchant_request_id,
           checkout_request_id = :checkout_request_id,
           provider_response_code = :response_code,
           failure_reason = NULL,
           expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)
         WHERE id = :id AND status = 'initiated'"
    );
    $stmt->execute([
        ':merchant_request_id' => (string)($response['MerchantRequestID'] ?? ''),
        ':checkout_request_id' => (string)($response['CheckoutRequestID'] ?? ''),
        ':response_code'      => (string)($response['ResponseCode'] ?? '0'),
        ':id'                 => $paymentId,
    ]);
}

function mpesa_record_initiation_error(int $paymentId, string $reason): void
{
    $stmt = db()->prepare(
        "UPDATE payments SET status = 'initiated', failure_reason = :reason WHERE id = :id"
    );
    $stmt->execute([':reason' => substr($reason, 0, 2000), ':id' => $paymentId]);
}

function mpesa_release_reserved_stock(PDO $pdo, int $orderId): void
{
    $stmt = $pdo->prepare(
        "UPDATE products p
         INNER JOIN (
           SELECT product_id, SUM(quantity) AS quantity
           FROM order_items
           WHERE order_id = :order_id AND product_id IS NOT NULL
           GROUP BY product_id
         ) oi ON oi.product_id = p.id
         SET p.stock = p.stock + oi.quantity"
    );
    $stmt->execute([':order_id' => $orderId]);
}

/** Mark a local payment attempt terminal and release its reserved stock once. */
function mpesa_mark_terminal(int $paymentId, string $status, string $reason): void
{
    if (!in_array($status, ['failed', 'cancelled', 'expired'], true)) {
        throw new InvalidArgumentException('Invalid terminal M-Pesa payment status.');
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $paymentId]);
        $payment = $stmt->fetch();

        if (!$payment || $payment['status'] === 'successful') {
            $pdo->commit();
            return;
        }

        if (!in_array($payment['status'], ['failed', 'cancelled', 'expired'], true)) {
            $stmt = $pdo->prepare(
                "UPDATE payments SET
                   status = :status,
                   failure_reason = :reason,
                   completed_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
            $stmt->execute([
                ':status' => $status,
                ':reason' => substr($reason, 0, 2000),
                ':id'     => $paymentId,
            ]);
        }

        if (empty($payment['stock_released_at'])) {
            mpesa_release_reserved_stock($pdo, (int)$payment['order_id']);
            $pdo->prepare('UPDATE payments SET stock_released_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute([':id' => $paymentId]);
            $pdo->prepare(
                "UPDATE orders SET status = 'cancelled'
                 WHERE id = :id AND status = 'pending'"
            )->execute([':id' => (int)$payment['order_id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Keep a transient network/initiation problem visible without claiming payment failure. */
function mpesa_mark_initiation_pending(int $paymentId, string $reason): void
{
    mpesa_record_initiation_error($paymentId, $reason);
}

/** Map Daraja callback ResultCode values to customer-facing local states. */
function mpesa_result_status($resultCode): string
{
    $resultCode = (int)$resultCode;
    if ($resultCode === 1032) return 'cancelled';
    if (in_array($resultCode, [1019, 1037], true)) return 'expired';
    return 'failed';
}

function mpesa_callback_metadata(array $callback): array
{
    $metadata = [];
    foreach (($callback['CallbackMetadata']['Item'] ?? []) as $item) {
        if (!empty($item['Name'])) {
            $metadata[(string)$item['Name']] = $item['Value'] ?? null;
        }
    }
    return $metadata;
}

/**
 * Validate and apply a Daraja STK callback. All payment transitions happen
 * inside a row lock, making Safaricom retries idempotent.
 */
function mpesa_process_callback(array $payload): array
{
    $callback = $payload['Body']['stkCallback'] ?? null;
    if (!is_array($callback)) {
        throw new MpesaException('Invalid M-Pesa callback payload.');
    }

    $checkoutRequestId = trim((string)($callback['CheckoutRequestID'] ?? ''));
    $merchantRequestId = trim((string)($callback['MerchantRequestID'] ?? ''));
    if ($checkoutRequestId === '' || $merchantRequestId === '') {
        throw new MpesaException('M-Pesa callback is missing request identifiers.');
    }

    $resultCode = (int)($callback['ResultCode'] ?? -1);
    $resultDesc = trim((string)($callback['ResultDesc'] ?? 'Unknown M-Pesa result.'));
    $rawPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $pdo = db();

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT p.*, o.order_number, o.total AS order_total, o.status AS order_status
             FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.provider = 'mpesa' AND p.checkout_request_id = :checkout_request_id
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([':checkout_request_id' => $checkoutRequestId]);
        $payment = $stmt->fetch();

        // Return 200 to Daraja for an old/unknown callback but do not mutate data.
        if (!$payment) {
            $pdo->commit();
            return ['handled' => false, 'reason' => 'unknown_checkout_request'];
        }

        if ($payment['status'] === 'successful') {
            $pdo->commit();
            return ['handled' => true, 'status' => 'successful', 'duplicate' => true];
        }

        if ($resultCode !== 0) {
            $status = mpesa_result_status($resultCode);
            if (!in_array($payment['status'], ['failed', 'cancelled', 'expired'], true)) {
                $pdo->prepare(
                    "UPDATE payments SET
                       status = :status,
                       merchant_request_id = :merchant_request_id,
                       provider_result_code = :result_code,
                       failure_reason = :reason,
                       callback_payload = :payload,
                       completed_at = UTC_TIMESTAMP()
                     WHERE id = :id"
                )->execute([
                    ':status'              => $status,
                    ':merchant_request_id' => $merchantRequestId,
                    ':result_code'         => (string)$resultCode,
                    ':reason'              => substr($resultDesc, 0, 2000),
                    ':payload'             => $rawPayload,
                    ':id'                  => (int)$payment['id'],
                ]);
            }

            if (empty($payment['stock_released_at'])) {
                mpesa_release_reserved_stock($pdo, (int)$payment['order_id']);
                $pdo->prepare('UPDATE payments SET stock_released_at = UTC_TIMESTAMP() WHERE id = :id')
                    ->execute([':id' => (int)$payment['id']]);
                $pdo->prepare(
                    "UPDATE orders SET status = 'cancelled'
                     WHERE id = :id AND status = 'pending'"
                )->execute([':id' => (int)$payment['order_id']]);
            }

            $pdo->commit();
            return ['handled' => true, 'status' => $status];
        }

        $metadata = mpesa_callback_metadata($callback);
        $receipt = trim((string)($metadata['MpesaReceiptNumber'] ?? ''));
        $callbackPhone = mpesa_normalize_phone((string)($metadata['PhoneNumber'] ?? ''));
        $expectedPhone = mpesa_normalize_phone((string)$payment['customer_phone']);
        $callbackAmount = $metadata['Amount'] ?? null;
        $expectedAmount = mpesa_amount_from_order($payment['order_total']);

        $verificationError = '';
        if (!empty($payment['merchant_request_id'])
            && !hash_equals((string)$payment['merchant_request_id'], $merchantRequestId)) {
            $verificationError = 'M-Pesa merchant request ID did not match the payment attempt.';
        } elseif ($receipt === '') {
            $verificationError = 'Successful callback did not contain an M-Pesa receipt number.';
        } elseif ($callbackPhone === null || $expectedPhone === null || $callbackPhone !== $expectedPhone) {
            $verificationError = 'M-Pesa callback phone number did not match the order.';
        } elseif (!is_numeric($callbackAmount)
            || abs((float)$callbackAmount - $expectedAmount) > 0.0001) {
            $verificationError = 'M-Pesa callback amount did not match the order total.';
        }

        if ($verificationError !== '') {
            $pdo->prepare(
                "UPDATE payments SET
                   merchant_request_id = :merchant_request_id,
                   provider_result_code = :result_code,
                   failure_reason = :reason,
                   callback_payload = :payload,
                   status = 'failed',
                   completed_at = UTC_TIMESTAMP()
                 WHERE id = :id AND status <> 'successful'"
            )->execute([
                ':merchant_request_id' => $merchantRequestId,
                ':result_code'         => '0',
                ':reason'              => $verificationError,
                ':payload'             => $rawPayload,
                ':id'                  => (int)$payment['id'],
            ]);
            if (empty($payment['stock_released_at'])) {
                mpesa_release_reserved_stock($pdo, (int)$payment['order_id']);
                $pdo->prepare('UPDATE payments SET stock_released_at = UTC_TIMESTAMP() WHERE id = :id')
                    ->execute([':id' => (int)$payment['id']]);
                $pdo->prepare(
                    "UPDATE orders SET status = 'cancelled'
                     WHERE id = :id AND status = 'pending'"
                )->execute([':id' => (int)$payment['order_id']]);
            }
            $pdo->commit();
            return ['handled' => true, 'status' => 'failed', 'reason' => $verificationError];
        }

        $stmt = $pdo->prepare(
            "SELECT id FROM payments
             WHERE provider = 'mpesa' AND provider_transaction_id = :receipt
               AND id <> :id AND status = 'successful'
             LIMIT 1"
        );
        $stmt->execute([':receipt' => $receipt, ':id' => (int)$payment['id']]);
        if ($stmt->fetch()) {
            $reason = 'This M-Pesa receipt is already linked to another successful payment.';
            $pdo->prepare(
                "UPDATE payments SET status = 'failed', provider_result_code = '0',
                        failure_reason = :reason, callback_payload = :payload,
                        completed_at = UTC_TIMESTAMP()
                 WHERE id = :id AND status <> 'successful'"
            )->execute([
                ':reason' => $reason,
                ':payload' => $rawPayload,
                ':id' => (int)$payment['id'],
            ]);
            if (empty($payment['stock_released_at'])) {
                mpesa_release_reserved_stock($pdo, (int)$payment['order_id']);
                $pdo->prepare('UPDATE payments SET stock_released_at = UTC_TIMESTAMP() WHERE id = :id')
                    ->execute([':id' => (int)$payment['id']]);
                $pdo->prepare(
                    "UPDATE orders SET status = 'cancelled'
                     WHERE id = :id AND status = 'pending'"
                )->execute([':id' => (int)$payment['order_id']]);
            }
            $pdo->commit();
            return ['handled' => true, 'status' => 'failed', 'reason' => $reason];
        }

        // A terminal local failure is never upgraded to paid by a late callback.
        if (in_array($payment['status'], ['failed', 'cancelled', 'expired'], true)) {
            $pdo->prepare(
                "UPDATE payments SET callback_payload = :payload,
                        provider_result_code = '0', failure_reason = :reason
                 WHERE id = :id"
            )->execute([
                ':payload' => $rawPayload,
                ':reason' => 'Late successful callback received after local payment closure.',
                ':id' => (int)$payment['id'],
            ]);
            $pdo->commit();
            return ['handled' => true, 'status' => $payment['status'], 'late_success' => true];
        }

        $pdo->prepare(
            "UPDATE payments SET
               status = 'successful',
               merchant_request_id = :merchant_request_id,
               provider_result_code = '0',
               provider_transaction_id = :receipt,
               failure_reason = NULL,
               callback_payload = :payload,
               completed_at = UTC_TIMESTAMP()
             WHERE id = :id AND status <> 'successful'"
        )->execute([
            ':merchant_request_id' => $merchantRequestId,
            ':receipt'            => $receipt,
            ':payload'            => $rawPayload,
            ':id'                 => (int)$payment['id'],
        ]);
        $pdo->prepare(
            "UPDATE orders SET status = 'processing'
             WHERE id = :id AND status = 'pending'"
        )->execute([':id' => (int)$payment['order_id']]);

        $pdo->commit();
        return ['handled' => true, 'status' => 'successful', 'receipt' => $receipt];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
