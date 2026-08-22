<?php
/**
 * Dependency-free integration/regression runner.
 *
 * Run with:
 *   TEST_DB_NAME=php_admin_panel_test php tests/run.php
 *
 * The runner creates and drops a dedicated test database. It never targets the
 * configured production database unless the caller deliberately supplies an
 * unsafe TEST_DB_NAME, which is rejected below.
 */
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/tests/run.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '198.51.100.200';
ob_start();

$testDbName = getenv('TEST_DB_NAME') ?: 'php_admin_panel_test';
// Allowlist shape (must look like a test database) AND denylist substrings
// so tricky names like "ciproduction" can never reach the DROP below.
if (!preg_match('/(?:^test|^testing|^ci|_test$)/i', $testDbName)
    || preg_match('/prod|live/i', $testDbName)
    || in_array($testDbName, ['php_admin_panel'], true)) {
    fwrite(STDERR, "Refusing unsafe TEST_DB_NAME: {$testDbName}\n");
    exit(2);
}

$dbHost = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('TEST_DB_PORT') ?: '3306';
$dbUser = getenv('TEST_DB_ADMIN_USER') ?: (getenv('DB_USER') ?: 'root');
$dbPass = getenv('TEST_DB_ADMIN_PASS');
if ($dbPass === false) $dbPass = getenv('DB_PASS') ?: '';
$dbCharset = 'utf8mb4';

function quote_identifier(string $value): string
{
    return '`' . str_replace('`', '``', $value) . '`';
}

function execute_sql_file(PDO $pdo, string $path, string $database): void
{
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException('Could not read ' . $path);
    $sql = str_replace('`php_admin_panel`', quote_identifier($database), $sql);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql)), static function ($statement): bool {
        return $statement !== '';
    }) as $statement) {
        $pdo->exec($statement);
    }
}

$adminPdo = null;
$testPdo = null;
$testFiles = [];
$passed = 0;
$failed = 0;
$failures = [];

function regression_test(string $name, callable $callback): void
{
    global $passed, $failed, $failures;
    try {
        $callback();
        $passed++;
        echo "PASS  {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        $failures[] = $name . ': ' . $e->getMessage();
        echo "FAIL  {$name}: {$e->getMessage()}\n";
    }
}

function check_true($condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function check_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')');
    }
}

try {
    $adminPdo = new PDO(
        'mysql:host=' . $dbHost . ';port=' . $dbPort . ';charset=' . $dbCharset,
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $adminPdo->exec('DROP DATABASE IF EXISTS ' . quote_identifier($testDbName));
    $adminPdo->exec(
        'CREATE DATABASE ' . quote_identifier($testDbName)
        . ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    putenv('APP_ENV=development');
    putenv('DB_HOST=' . $dbHost);
    putenv('DB_PORT=' . $dbPort);
    putenv('DB_NAME=' . $testDbName);
    putenv('DB_USER=' . $dbUser);
    putenv('DB_PASS=' . $dbPass);
    putenv('DB_CHARSET=utf8mb4');
    putenv('MPESA_ENVIRONMENT=sandbox');
    putenv('MPESA_CONSUMER_KEY=test-key');
    putenv('MPESA_CONSUMER_SECRET=test-secret');
    putenv('MPESA_SHORTCODE=174379');
    putenv('MPESA_PARTY_B=174379');
    putenv('MPESA_TRANSACTION_TYPE=CustomerPayBillOnline');
    putenv('MPESA_PASSKEY=test-passkey');
    putenv('MPESA_CALLBACK_URL=https://test.example/mpesa_callback.php');
    putenv('MPESA_CALLBACK_TOKEN=0123456789abcdef0123456789abcdef');
    putenv('MPESA_INITIATOR_NAME=test-initiator');
    putenv('MPESA_SECURITY_CREDENTIAL=encrypted-test-credential');
    putenv('MPESA_REVERSAL_RESULT_URL=https://test.example/mpesa_reversal_callback.php');
    putenv('MPESA_REVERSAL_TIMEOUT_URL=https://test.example/mpesa_reversal_callback.php');

    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../includes/shop_bootstrap.php';
    require_once __DIR__ . '/../includes/product_images.php';
    require_once __DIR__ . '/../includes/payment_methods.php';
    require_once __DIR__ . '/../includes/delivery.php';
    require_once __DIR__ . '/../includes/donations.php';
    require_once __DIR__ . '/../includes/mpesa.php';
    require_once __DIR__ . '/../includes/order_access.php';
    require_once __DIR__ . '/../delivery/includes/auth.php';

    $testPdo = db();
    execute_sql_file($testPdo, __DIR__ . '/../sql/schema.sql', $testDbName);
    execute_sql_file($testPdo, __DIR__ . '/../sql/migrations.sql', $testDbName);

    $marker = 'AUTO-REG-' . date('YmdHis');
    $testPdo->prepare("INSERT INTO categories (name, slug, status) VALUES (:name, :slug, 'active')")
        ->execute([':name' => $marker, ':slug' => strtolower($marker)]);
    $categoryId = (int)$testPdo->lastInsertId();
    $testPdo->prepare(
        "INSERT INTO products (category_id, name, slug, price, stock, status)
         VALUES (:category_id, :name, :slug, 100.00, 5, 'active')"
    )->execute([
        ':category_id' => $categoryId,
        ':name' => $marker . ' Product',
        ':slug' => strtolower($marker) . '-product',
    ]);
    $productId = (int)$testPdo->lastInsertId();

    $testPdo->prepare(
        "INSERT INTO users (name, email, password, role, status, phone)
         VALUES (:name, :email, :password, 'admin', 'active', '0700000000')"
    )->execute([
        ':name' => 'Regression Admin',
        ':email' => $marker . '@admin.test',
        ':password' => password_hash('AdminPass123!', PASSWORD_BCRYPT),
    ]);
    $adminId = (int)$testPdo->lastInsertId();
    $testPdo->prepare(
        "INSERT INTO users (name, email, password, role, status, phone)
         VALUES (:name, :email, :password, 'delivery_driver', 'active', '0711111111')"
    )->execute([
        ':name' => 'Regression Driver',
        ':email' => $marker . '@driver.test',
        ':password' => password_hash('DriverPass123!', PASSWORD_BCRYPT),
    ]);
    $driverId = (int)$testPdo->lastInsertId();

    update_setting('currency_code', 'KES');
    update_setting('currency_symbol', 'KSh ');
    update_setting('cod_enabled', '1');
    update_setting('mpesa_enabled', '0');
    update_setting('store_latitude', '-4.043477');
    update_setting('store_longitude', '39.668206');
    update_setting('delivery_price_per_km', '10.00');
    update_setting('donations_enabled', '1');
    update_setting('charity_name', 'Regression Charity');
    update_setting('charity_description', 'Regression charity description.');
    update_setting('charity_website', 'https://charity.test');
    update_setting('donation_presets', '10,25,50');
    settings_cache_clear();

    regression_test('product data and multiple images', function () use ($testPdo, $productId): void {
        $legacy = 'legacy-regression.png';
        product_image_sync_legacy($productId, $legacy);
        $rows = product_image_rows($productId);
        check_same(1, count($rows), 'legacy image was not preserved');
        $testPdo->prepare(
            "INSERT INTO product_images (product_id, filename, original_name, sort_order, is_primary)
             VALUES (:product_id, 'second-regression.png', 'second-regression.png', 1, 0)"
        )->execute([':product_id' => $productId]);
        check_true(product_image_set_primary($productId, (int)product_image_rows($productId)[1]['id']), 'primary image update failed');
        check_same('second-regression.png', product_primary_image($productId), 'primary image not synchronized');
        check_true(product_image_move($productId, (int)product_image_rows($productId)[1]['id'], -1), 'image reorder failed');
    });

    regression_test('cart total and atomic stock guard', function () use ($testPdo, $productId): void {
        $_SESSION['cart'] = [$productId => 2];
        $cart = cart_load();
        check_same(200.0, (float)$cart['subtotal'], 'cart subtotal mismatch');
        $testPdo->prepare('UPDATE products SET stock = 1 WHERE id = :id')->execute([':id' => $productId]);
        $stmt = $testPdo->prepare(
            'UPDATE products SET stock = stock - :set_quantity
             WHERE id = :id AND stock >= :minimum_quantity'
        );
        $stmt->execute([':set_quantity' => 1, ':id' => $productId, ':minimum_quantity' => 1]);
        check_same(1, $stmt->rowCount(), 'first atomic stock update failed');
        $stmt->execute([':set_quantity' => 1, ':id' => $productId, ':minimum_quantity' => 1]);
        check_same(0, $stmt->rowCount(), 'overselling atomic guard failed');
        $testPdo->prepare('UPDATE products SET stock = 5 WHERE id = :id')->execute([':id' => $productId]);
    });

    regression_test('delivery distance, fee, pickup and missing configuration', function () use ($testPdo): void {
        settings_cache_clear();
        $quote = delivery_calculate_quote('delivery', -4.05, 39.67);
        check_true($quote['distance_km'] > 0, 'distance was not calculated');
        check_same(delivery_billable_distance_km($quote['distance_km']), $quote['billable_distance_km'], 'billable distance mismatch');
        check_same(round($quote['billable_distance_km'] * 10, 2), $quote['delivery_fee'], 'delivery fee formula mismatch');
        foreach ([[0.5, 1], [1.0, 1], [1.5, 2], [2.0, 2], [2.5, 3], [5.1, 6]] as $case) {
            check_same($case[1], delivery_billable_distance_km((float)$case[0]), 'ceil billing rule failed for ' . $case[0]);
        }
        check_same(0.0, delivery_calculate_quote('pickup', -4.05, 39.67)['delivery_fee'], 'pickup is not free');
        update_setting('store_latitude', '');
        update_setting('store_longitude', '');
        settings_cache_clear();
        try { delivery_calculate_quote('delivery', -4.05, 39.67); throw new RuntimeException('missing delivery configuration did not block'); }
        catch (InvalidArgumentException $e) { check_true(strpos($e->getMessage(), 'temporarily unavailable') !== false, 'wrong missing configuration error'); }
        update_setting('store_latitude', '-4.043477');
        update_setting('store_longitude', '39.668206');
        settings_cache_clear();
    });

    regression_test('donation validation and total', function (): void {
        check_same(12.50, donation_amount_normalize('12.5'), 'donation normalization');
        try { donation_amount_normalize('-1'); throw new RuntimeException('negative donation accepted'); }
        catch (InvalidArgumentException $e) {}
        check_true(donations_are_available(), 'configured donation was not available');
        // Over-cap and non-numeric inputs must be rejected too.
        try { donation_amount_normalize('1000001'); throw new RuntimeException('over-cap donation accepted'); }
        catch (InvalidArgumentException $e) {}
        try { donation_amount_normalize('abc'); throw new RuntimeException('non-numeric donation accepted'); }
        catch (InvalidArgumentException $e) {}
        check_same(112.50, 100.00 + 0.00 + donation_amount_normalize('12.50'), 'donation total formula');
    });

    regression_test('global payment methods', function (): void {
        update_setting('cod_enabled', '1'); update_setting('mpesa_enabled', '0'); settings_cache_clear();
        $methods = checkout_payment_methods();
        check_true(isset($methods['cod']) && !isset($methods['mpesa']), 'COD-only configuration failed');
        update_setting('cod_enabled', '0'); update_setting('mpesa_enabled', '1'); settings_cache_clear();
        check_true(isset(checkout_payment_methods()['mpesa']), 'M-Pesa configuration failed');
        update_setting('cod_enabled', '1'); update_setting('mpesa_enabled', '0'); settings_cache_clear();
    });

    regression_test('M-Pesa success/failure callback and status validation', function () use ($testPdo, $marker): void {
        $testPdo->prepare(
            "INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_address, shipping_city, subtotal, total, payment_method, status)
             VALUES (:number, 'Payment Customer', 'payment@test', '0712345678', 'Address', 'Mombasa', 100, 100, 'mpesa', 'pending')"
        )->execute([':number' => $marker . '-PAY']);
        $orderId = (int)$testPdo->lastInsertId();
        $testPdo->prepare(
            "INSERT INTO payments (order_id, provider, idempotency_key, amount, currency_code, customer_phone, status, merchant_request_id, checkout_request_id)
             VALUES (:order_id, 'mpesa', :key, 100, 'KES', '254712345678', 'pending', 'M-1', 'C-1')"
        )->execute([':order_id' => $orderId, ':key' => hash('sha256', $marker . '-pay')]);
        $payload = ['Body' => ['stkCallback' => [
            'MerchantRequestID' => 'M-1', 'CheckoutRequestID' => 'C-1', 'ResultCode' => 0,
            'ResultDesc' => 'Success', 'CallbackMetadata' => ['Item' => [
                ['Name' => 'Amount', 'Value' => 100], ['Name' => 'MpesaReceiptNumber', 'Value' => 'R-1'],
                ['Name' => 'PhoneNumber', 'Value' => 254712345678],
            ]],
        ]]];
        check_same('successful', mpesa_process_callback($payload)['status'], 'success callback failed');
        check_true(!empty(mpesa_process_callback($payload)['duplicate']), 'duplicate callback not idempotent');
        $testPdo->prepare('DELETE FROM orders WHERE id = :id')->execute([':id' => $orderId]);
    });

    regression_test('refund/reversal idempotency', function () use ($testPdo, $adminId, $marker): void {
        $testPdo->prepare(
            "INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_address, shipping_city, subtotal, total, payment_method, status)
             VALUES (:number, 'Refund Customer', 'refund@test', '0712345678', 'Address', 'Mombasa', 50, 50, 'mpesa', 'processing')"
        )->execute([':number' => $marker . '-REFUND']);
        $orderId = (int)$testPdo->lastInsertId();
        $testPdo->prepare(
            "INSERT INTO payments (order_id, provider, idempotency_key, amount, currency_code, customer_phone, status, provider_transaction_id)
             VALUES (:order_id, 'mpesa', :key, 50, 'KES', '254712345678', 'successful', 'ORIGINAL-1')"
        )->execute([':order_id' => $orderId, ':key' => hash('sha256', $marker . '-refund-pay')]);
        $paymentId = (int)$testPdo->lastInsertId();
        $key = mpesa_refund_idempotency_key($paymentId, 'token');
        $refund = mpesa_create_refund_request($paymentId, $orderId, $adminId, 50, 'Regression refund', $key);
        check_true(mpesa_claim_refund((int)$refund['id']), 'refund claim failed');
        check_true(!mpesa_claim_refund((int)$refund['id']), 'duplicate refund claim succeeded');
        mpesa_mark_refund_processing((int)$refund['id'], ['OriginatorConversationID' => 'O-1', 'ConversationID' => 'C-REF-1']);
        check_same('refunded', mpesa_process_reversal_callback(['Result' => [
            'ResultCode' => 0, 'OriginatorConversationID' => 'O-1', 'ConversationID' => 'C-REF-1', 'TransactionID' => 'REV-1'
        ]])['status'], 'refund callback failed');
        $testPdo->prepare('DELETE FROM mpesa_refunds WHERE payment_id = (SELECT id FROM payments WHERE order_id = :order_id LIMIT 1)')
            ->execute([':order_id' => $orderId]);
        $testPdo->prepare('DELETE FROM orders WHERE id = :id')->execute([':id' => $orderId]);
    });

    regression_test('order access privacy', function () use ($testPdo, $marker): void {
        $testPdo->prepare(
            "INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_address, shipping_city, subtotal, total, payment_method, status)
             VALUES (:number, 'Private Customer', 'private@test', '0712345678', 'Secret Address', 'Mombasa', 10, 10, 'cod', 'pending')"
        )->execute([':number' => $marker . '-PRIVATE']);
        $orderId = (int)$testPdo->lastInsertId();
        $token = bin2hex(random_bytes(32));
        $testPdo->prepare('INSERT INTO order_access_tokens (order_id, token_hash) VALUES (:order_id, :token_hash)')
            ->execute([':order_id' => $orderId, ':token_hash' => order_access_token_hash($token)]);
        check_true(order_access_token_valid($orderId, $token), 'valid order token rejected');
        check_true(!order_access_token_valid($orderId, str_repeat('x', 64)), 'invalid order token accepted');
        $testPdo->prepare('DELETE FROM orders WHERE id = :id')->execute([':id' => $orderId]);
    });

    regression_test('driver ownership and statuses', function () use ($testPdo, $driverId, $marker): void {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.201';
        check_same('ok', attempt_driver_login($marker . '@driver.test', 'DriverPass123!'), 'driver login failed');
        $testPdo->prepare(
            "INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, shipping_address, shipping_city, subtotal, total, payment_method, status)
             VALUES (:number, 'Driver Customer', 'driver-order@test', '0712345678', 'Address', 'Mombasa', 10, 10, 'cod', 'pending')"
        )->execute([':number' => $marker . '-DRIVER']);
        $orderId = (int)$testPdo->lastInsertId();
        $testPdo->prepare("INSERT INTO order_delivery_locations(order_id, delivery_method) VALUES (:order_id, 'delivery')")
            ->execute([':order_id' => $orderId]);
        $testPdo->prepare("INSERT INTO delivery_assignments(order_id, driver_id, status) VALUES (:order_id, :driver_id, 'assigned')")
            ->execute([':order_id' => $orderId, ':driver_id' => $driverId]);
        $assignmentId = (int)$testPdo->lastInsertId();
        record_delivery_status($testPdo, $assignmentId, $orderId, $driverId, 'assigned', $driverId, 'Assigned');
        record_delivery_status($testPdo, $assignmentId, $orderId, $driverId, 'picked_up', $driverId, 'Collected');
        check_same('picked_up', delivery_current_status($assignmentId), 'driver status history failed');
        $testPdo->prepare('DELETE FROM orders WHERE id = :id')->execute([':id' => $orderId]);
        logout_driver();
    });

    regression_test('CSRF and secure filename rules', function (): void {
        check_true(!csrf_verify('not-the-session-token'), 'invalid CSRF token accepted');
        check_true(product_image_filename_is_safe('safe-image.png'), 'safe image rejected');
        check_true(!product_image_filename_is_safe('../secret.php'), 'path traversal filename accepted');
    });

    regression_test('customer and driver frontend rendering', function () use ($productId): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/product.php';
        $_GET = ['slug' => strtolower('AUTO-REG-' . date('YmdHis')) . '-product'];
        // Use the known product row rather than relying on the current wall clock slug.
        $stmt = db()->prepare('SELECT slug FROM products WHERE id = :id');
        $stmt->execute([':id' => $productId]);
        $_GET['slug'] = $stmt->fetchColumn();
        ob_start();
        include __DIR__ . '/../product.php';
        $productHtml = ob_get_clean();
        check_true(strpos($productHtml, 'productMainImage') !== false, 'product frontend did not render');
        check_true(strpos($productHtml, 'Customer reviews') !== false, 'product review frontend missing');

        $_SESSION['cart'] = [$productId => 1];
        $_SERVER['SCRIPT_NAME'] = '/checkout.php';
        ob_start();
        include __DIR__ . '/../checkout.php';
        $checkoutHtml = ob_get_clean();
        check_true(strpos($checkoutHtml, 'id="checkoutForm"') !== false, 'checkout frontend did not render');
        check_true(strpos($checkoutHtml, 'Store Pickup') !== false, 'fulfillment controls missing');

        unset($_SESSION['driver']);
        $_SERVER['SCRIPT_NAME'] = '/delivery/login.php';
        ob_start();
        include __DIR__ . '/../delivery/login.php';
        $driverLoginHtml = ob_get_clean();
        check_true(strpos($driverLoginHtml, 'Delivery portal') !== false, 'driver login frontend did not render');
    });
} finally {
    if (is_object($testPdo)) $testPdo = null;
    if ($adminPdo instanceof PDO) {
        $adminPdo->exec('DROP DATABASE IF EXISTS ' . quote_identifier($testDbName));
    }
}

echo "\nPassed: {$passed}\nFailed: {$failed}\n";
if ($failures) {
    echo implode("\n", $failures) . "\n";
    ob_end_flush();
    exit(1);
}
echo "All automated regression tests passed.\n";
ob_end_flush();
