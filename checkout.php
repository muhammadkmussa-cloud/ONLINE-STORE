<?php
require_once __DIR__ . '/includes/shop_bootstrap.php';
require_once __DIR__ . '/includes/payment_methods.php';
require_once __DIR__ . '/includes/delivery.php';
require_once __DIR__ . '/includes/donations.php';
require_once __DIR__ . '/includes/order_access.php';

$pageTitle = 'Checkout';

// Do not let a customer start a second checkout while an earlier M-Pesa
// attempt from this browser is still unresolved.
$activeMpesaOrder = trim((string)($_SESSION['mpesa_active_order'] ?? ''));
if ($activeMpesaOrder !== '') {
    try {
        $activePayment = mpesa_find_payment_by_order_number($activeMpesaOrder);
        if ($activePayment && in_array($activePayment['status'], ['initiated', 'pending'], true)) {
            $activeToken = (string)($_SESSION['mpesa_active_order_token'] ?? '');
            redirect('mpesa_wait.php?o=' . urlencode($activeMpesaOrder) . ($activeToken !== '' ? '&t=' . urlencode($activeToken) : ''));
        }
        unset($_SESSION['mpesa_active_order']);
    } catch (Throwable $e) {
        // If the optional payment table is not available, keep the normal checkout path.
    }
}

$cart = cart_load();

if (empty($cart['items'])) {
    flash('warning', 'Your cart is empty. Add some products before checking out.');
    redirect('cart.php');
}

$deliveryPricingSettings = delivery_pricing_settings();
$deliveryPricingReady = $deliveryPricingSettings['ready'];
$deliveryQuote = null;
$deliveryFee = 0.0;
$grandTotal = $cart['subtotal'];
$availablePaymentMethods = checkout_payment_methods();
$mpesaAdminEnabled = payment_method_admin_enabled('mpesa');
$mpesaAvailable = isset($availablePaymentMethods['mpesa']);
$codAvailable = isset($availablePaymentMethods['cod']);
$mpesaAvailabilityError = '';
$pickupAddress = setting('store_pickup_address', 'Store pickup location to be configured.');
$pickupInstructions = setting('store_pickup_instructions', 'Collect your order from the store after confirmation.');
$donationSettings = donation_settings();
$donationsAvailable = donations_are_available();
$donationAmount = 0.0;
$donationError = '';
if ($mpesaAvailable) {
    try {
        mpesa_amount_from_order($grandTotal);
    } catch (Throwable $e) {
        $mpesaAvailable = false;
        unset($availablePaymentMethods['mpesa']);
        $mpesaAvailabilityError = $e->getMessage();
    }
} elseif ($mpesaAdminEnabled) {
    $mpesaAvailabilityError = 'M-Pesa is enabled but its server configuration is not ready.';
}

// One token is used for the whole checkout view. The unique database key on
// payments prevents a double-click/race from creating a second M-Pesa attempt.
if (empty($_SESSION['checkout_token'])) {
    $_SESSION['checkout_token'] = bin2hex(random_bytes(24));
}
$checkoutToken = $_SESSION['checkout_token'];
$orderAccessTokenKey = hash('sha256', 'order-access:' . session_id() . ':' . $checkoutToken);
if (!isset($_SESSION['_order_access_tokens'])) $_SESSION['_order_access_tokens'] = [];
if (empty($_SESSION['_order_access_tokens'][$orderAccessTokenKey])) {
    $_SESSION['_order_access_tokens'][$orderAccessTokenKey] = bin2hex(random_bytes(32));
}
$orderAccessToken = $_SESSION['_order_access_tokens'][$orderAccessTokenKey];
$orderAccessTokenHash = order_access_token_hash($orderAccessToken);

$errors = [];
$old = [
    'customer_name'    => '',
    'customer_email'   => '',
    'customer_phone'   => '',
    'shipping_address' => '',
    'shipping_city'    => '',
    'shipping_zip'     => '',
    'shipping_country' => '',
    'delivery_method'  => $deliveryPricingReady ? 'delivery' : 'pickup',
    'latitude'         => '',
    'longitude'        => '',
    'accuracy_meters'  => '',
    'payment_method'   => array_key_first($availablePaymentMethods) ?? '',
    'donation_amount'  => '0.00',
    'notes'            => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    foreach (array_keys($old) as $key) {
        $old[$key] = trim((string)($_POST[$key] ?? ''));
    }

    $latitude = null;
    $longitude = null;
    $accuracyMeters = null;
    $locationSource = null;
    if (!delivery_method_is_valid($old['delivery_method'])) {
        $errors[] = 'Please choose Delivery or Store Pickup.';
    } elseif ($old['delivery_method'] === 'delivery') {
        if (!$deliveryPricingReady) {
            $errors[] = 'Delivery is temporarily unavailable because distance-based delivery pricing has not been configured. Please choose Store Pickup or contact the store.';
        }
        if ($old['shipping_address'] === '') {
            $errors[] = 'Delivery address is required.';
        }
        if ($old['shipping_city'] === '') {
            $errors[] = 'Delivery city is required.';
        }

        $hasLatitude = $old['latitude'] !== '';
        $hasLongitude = $old['longitude'] !== '';
        if ($hasLatitude || $hasLongitude) {
            if (!$hasLatitude || !$hasLongitude
                || !delivery_location_is_valid($old['latitude'], $old['longitude'])) {
                $errors[] = 'The shared delivery location is invalid. Please share it again or continue with your manual address.';
            } else {
                $latitude = (float)$old['latitude'];
                $longitude = (float)$old['longitude'];
                $accuracyMeters = $old['accuracy_meters'] !== '' && is_numeric($old['accuracy_meters'])
                    ? max(0.0, min(100000.0, (float)$old['accuracy_meters']))
                    : null;
                $locationSource = 'browser_geolocation';
            }
        }
    } else {
        // Pickup does not request or store browser coordinates. Preserve any
        // manually entered notes, but provide non-null order snapshots.
        $old['latitude'] = '';
        $old['longitude'] = '';
        $old['accuracy_meters'] = '';
        if ($old['shipping_address'] === '') {
            $old['shipping_address'] = setting(
                'store_pickup_address',
                'Store pickup location to be configured.'
            );
        }
        if ($old['shipping_city'] === '') {
            $old['shipping_city'] = 'Store pickup';
        }
    }

    if (!$errors) {
        try {
            $deliveryQuote = delivery_calculate_quote(
                $old['delivery_method'],
                $latitude,
                $longitude
            );
            $deliveryFee = $deliveryQuote['delivery_fee'];
            $grandTotal = $cart['subtotal'] + $deliveryFee;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    $donationSubmitted = array_key_exists('donation_amount', $_POST);
    if ($donationsAvailable) {
        try {
            $donationAmount = donation_amount_normalize($_POST['donation_amount'] ?? '0');
            $old['donation_amount'] = number_format($donationAmount, 2, '.', '');
        } catch (Throwable $e) {
            $donationError = $e->getMessage();
            $errors[] = $donationError;
        }
    } elseif ($donationSubmitted && trim((string)$_POST['donation_amount']) !== ''
              && is_numeric($_POST['donation_amount'])
              && (float)$_POST['donation_amount'] > 0) {
        $donationError = 'Charity donations are currently disabled.';
        $errors[] = $donationError;
    }
    $grandTotal = $cart['subtotal'] + $deliveryFee + $donationAmount;

    if ($old['customer_name'] === '') {
        $errors[] = 'Full name is required.';
    }
    if (!filter_var($old['customer_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email is required.';
    }
    if ($old['customer_phone'] === '') {
        $errors[] = 'Phone number is required.';
    }
    if ($old['shipping_address'] === '') {
        $errors[] = 'Shipping address is required.';
    }
    if ($old['shipping_city'] === '') {
        $errors[] = 'City is required.';
    }

    $allowedPayments = array_keys($availablePaymentMethods);
    if (!in_array($old['payment_method'], $allowedPayments, true)) {
        $errors[] = 'Please choose a valid payment method.';
    }

    $mpesaPhone = null;
    $mpesaIdempotencyKey = null;
    if ($old['payment_method'] === 'mpesa') {
        if (!$mpesaAvailable) {
            $errors[] = 'M-Pesa is not currently available. Please choose another payment method.';
        }
        $mpesaPhone = mpesa_normalize_phone($old['customer_phone']);
        if ($mpesaPhone === null) {
            $errors[] = 'Enter a valid Kenyan M-Pesa phone number, for example 0712345678.';
        }

        $postedCheckoutToken = trim((string)($_POST['checkout_token'] ?? ''));
        $sessionCheckoutToken = (string)($_SESSION['checkout_token'] ?? '');
        if ($postedCheckoutToken === '' || $sessionCheckoutToken === ''
            || !hash_equals($sessionCheckoutToken, $postedCheckoutToken)) {
            $errors[] = 'This checkout form has expired. Please reload the page and try again.';
        } else {
            $mpesaIdempotencyKey = mpesa_checkout_idempotency_key($postedCheckoutToken);
        }

        if (!$errors) {
            try {
                mpesa_amount_from_order($grandTotal);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    // A retry of the same checkout form returns to the original payment
    // attempt instead of creating a second order or STK prompt. Check this
    // before stock validation because the first request already reserved it.
    if (!$errors && $mpesaIdempotencyKey !== null) {
        $existingPayment = mpesa_find_payment_by_idempotency($mpesaIdempotencyKey);
        if ($existingPayment) {
            $_SESSION['mpesa_active_order'] = $existingPayment['order_number'];
            cart_clear();
            redirect('mpesa_wait.php?o=' . urlencode($existingPayment['order_number']) . '&t=' . urlencode($orderAccessToken));
        }
    }

    // Re-verify stock right before order placement.
    if (!$errors) {
        foreach ($cart['items'] as $item) {
            if ((int)$item['qty'] > (int)$item['product']['stock']) {
                $errors[] = 'Not enough stock for "' . $item['product']['name']
                          . '" (only ' . (int)$item['product']['stock'] . ' left).';
            }
        }
    }

    if (!$errors) {
        $pdo = db();
        $orderNumber = '';
        $orderId = 0;
        $paymentId = 0;

        try {
            $pdo->beginTransaction();

            $orderNumber = generate_order_number();
            $stmt = $pdo->prepare(
                "INSERT INTO orders
                  (order_number, customer_name, customer_email, customer_phone,
                   shipping_address, shipping_city, shipping_zip, shipping_country,
                   subtotal, shipping_fee, total, payment_method, notes, status)
                 VALUES
                  (:order_number, :name, :email, :phone,
                   :address, :city, :zip, :country,
                   :subtotal, :shipping_fee, :total, :payment, :notes, 'pending')"
            );
            $stmt->execute([
                ':order_number' => $orderNumber,
                ':name'         => $old['customer_name'],
                ':email'        => $old['customer_email'],
                ':phone'        => $old['customer_phone'],
                ':address'      => $old['shipping_address'],
                ':city'         => $old['shipping_city'],
                ':zip'          => $old['shipping_zip'],
                ':country'      => $old['shipping_country'],
                ':subtotal'     => $cart['subtotal'],
                ':shipping_fee' => $deliveryFee,
                ':total'        => $grandTotal,
                ':payment'      => $old['payment_method'],
                ':notes'        => $old['notes'],
            ]);
            $orderId = (int)$pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO order_access_tokens (order_id, token_hash) VALUES (:order_id, :token_hash)'
            )->execute([':order_id' => $orderId, ':token_hash' => $orderAccessTokenHash]);

            $itemStmt = $pdo->prepare(
                "INSERT INTO order_items
                  (order_id, product_id, product_name, product_sku, product_image,
                   unit_price, quantity, line_total)
                 VALUES
                  (:order_id, :product_id, :name, :sku, :image,
                   :unit_price, :qty, :line_total)"
            );
            // The stock predicate makes the reservation atomic under concurrency.
            $stockStmt = $pdo->prepare(
                "UPDATE products
                 SET stock = stock - :quantity_set
                 WHERE id = :id AND status = 'active' AND stock >= :quantity_min"
            );

            foreach ($cart['items'] as $item) {
                $product = $item['product'];
                $stockStmt->execute([
                    ':quantity_set' => (int)$item['qty'],
                    ':quantity_min' => (int)$item['qty'],
                    ':id'           => (int)$product['id'],
                ]);
                if ($stockStmt->rowCount() !== 1) {
                    throw new RuntimeException('Stock changed while the order was being placed.');
                }

                $itemStmt->execute([
                    ':order_id'   => $orderId,
                    ':product_id' => (int)$product['id'],
                    ':name'       => $product['name'],
                    ':sku'        => $product['sku'] ?: null,
                    ':image'      => $product['image'] ?: null,
                    ':unit_price' => $item['unit_price'],
                    ':qty'        => $item['qty'],
                    ':line_total' => $item['line_total'],
                ]);
            }

            if ($old['payment_method'] === 'mpesa') {
                $paymentStmt = $pdo->prepare(
                    "INSERT INTO payments
                      (order_id, provider, idempotency_key, amount, currency_code, customer_phone, status)
                     VALUES
                      (:order_id, 'mpesa', :idempotency_key, :amount, :currency_code, :phone, 'initiated')"
                );
                $paymentStmt->execute([
                    ':order_id'       => $orderId,
                    ':idempotency_key' => $mpesaIdempotencyKey,
                    ':amount'         => $grandTotal,
                    ':currency_code'  => strtoupper((string)setting('currency_code', 'KES')),
                    ':phone'          => $mpesaPhone,
                ]);
                $paymentId = (int)$pdo->lastInsertId();
            }

            $deliveryStmt = $pdo->prepare(
                "INSERT INTO order_delivery_locations
                  (order_id, delivery_method, latitude, longitude, accuracy_meters,
                   location_source, captured_at)
                 VALUES
                  (:order_id, :delivery_method, :latitude, :longitude, :accuracy_meters,
                   :location_source, :captured_at)"
            );
            $deliveryStmt->execute([
                ':order_id'        => $orderId,
                ':delivery_method' => $old['delivery_method'],
                ':latitude'        => $latitude,
                ':longitude'       => $longitude,
                ':accuracy_meters' => $accuracyMeters,
                ':location_source' => $locationSource,
                ':captured_at'     => $latitude !== null ? date('Y-m-d H:i:s') : null,
            ]);

            $pricingStmt = $pdo->prepare(
                "INSERT INTO order_delivery_pricing
                  (order_id, pricing_mode, store_latitude, store_longitude,
                   customer_latitude, customer_longitude, distance_km, rate_per_km,
                   delivery_fee, currency_code, calculated_at)
                 VALUES
                  (:order_id, :pricing_mode, :store_latitude, :store_longitude,
                   :customer_latitude, :customer_longitude, :distance_km, :rate_per_km,
                   :delivery_fee, :currency_code, :calculated_at)"
            );
            $pricingStmt->execute([
                ':order_id'          => $orderId,
                ':pricing_mode'      => $deliveryQuote['pricing_mode'],
                ':store_latitude'    => $deliveryQuote['store_latitude'],
                ':store_longitude'   => $deliveryQuote['store_longitude'],
                ':customer_latitude' => $deliveryQuote['customer_latitude'],
                ':customer_longitude'=> $deliveryQuote['customer_longitude'],
                ':distance_km'       => $deliveryQuote['distance_km'],
                ':rate_per_km'       => $deliveryQuote['rate_per_km'],
                ':delivery_fee'      => $deliveryQuote['delivery_fee'],
                ':currency_code'     => $deliveryQuote['currency_code'],
                ':calculated_at'     => date('Y-m-d H:i:s'),
            ]);

            $authoritativePricingStmt = $pdo->prepare(
                "INSERT INTO order_delivery_distance_pricing
                  (order_id, pricing_mode, store_latitude, store_longitude,
                   customer_latitude, customer_longitude, actual_distance_km,
                   billable_distance_km, rate_per_km, delivery_fee, currency_code, calculated_at)
                 VALUES (:order_id, :pricing_mode, :store_latitude, :store_longitude,
                         :customer_latitude, :customer_longitude, :actual_distance_km,
                         :billable_distance_km, :rate_per_km, :delivery_fee, :currency_code, :calculated_at)"
            );
            $authoritativePricingStmt->execute([
                ':order_id' => $orderId,
                ':pricing_mode' => $deliveryQuote['pricing_mode'],
                ':store_latitude' => $deliveryQuote['store_latitude'],
                ':store_longitude' => $deliveryQuote['store_longitude'],
                ':customer_latitude' => $deliveryQuote['customer_latitude'],
                ':customer_longitude' => $deliveryQuote['customer_longitude'],
                ':actual_distance_km' => $deliveryQuote['actual_distance_km'],
                ':billable_distance_km' => $deliveryQuote['billable_distance_km'],
                ':rate_per_km' => $deliveryQuote['rate_per_km'],
                ':delivery_fee' => $deliveryQuote['delivery_fee'],
                ':currency_code' => $deliveryQuote['currency_code'],
                ':calculated_at' => date('Y-m-d H:i:s'),
            ]);

            if ($old['delivery_method'] === 'pickup') {
                $pickupStmt = $pdo->prepare(
                    "INSERT INTO order_pickup_snapshots
                      (order_id, pickup_address, pickup_instructions)
                     VALUES (:order_id, :pickup_address, :pickup_instructions)"
                );
                $pickupStmt->execute([
                    ':order_id' => $orderId,
                    ':pickup_address' => $pickupAddress,
                    ':pickup_instructions' => $pickupInstructions,
                ]);
            }

            $donationStmt = $pdo->prepare(
                "INSERT INTO order_donations
                  (order_id, donation_amount, currency_code, charity_name, charity_description, charity_website)
                 VALUES (:order_id, :amount, :currency_code, :charity_name, :charity_description, :charity_website)"
            );
            $donationStmt->execute([
                ':order_id' => $orderId,
                ':amount' => $donationAmount,
                ':currency_code' => strtoupper((string)setting('currency_code', 'USD')),
                ':charity_name' => $donationsAvailable ? ($donationSettings['charity_name'] ?: null) : null,
                ':charity_description' => $donationsAvailable ? ($donationSettings['charity_description'] ?: null) : null,
                ':charity_website' => $donationsAvailable ? ($donationSettings['charity_website'] ?: null) : null,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();

            // A concurrent duplicate form submission is safe: find and reuse
            // the committed payment attempt protected by the unique key.
            if ($mpesaIdempotencyKey !== null) {
                try {
                    $existingPayment = mpesa_find_payment_by_idempotency($mpesaIdempotencyKey);
                    if ($existingPayment) {
                        $_SESSION['mpesa_active_order'] = $existingPayment['order_number'];
                        cart_clear();
                        redirect('mpesa_wait.php?o=' . urlencode($existingPayment['order_number']) . '&t=' . urlencode($orderAccessToken));
                    }
                } catch (Throwable $ignored) {}
            }

            $errors[] = APP_ENV === 'development'
                ? 'Could not place your order: ' . $e->getMessage()
                : 'Could not place your order. Please try again.';
        }

        if (!$errors && $old['payment_method'] === 'mpesa') {
            // The order and stock reservation now exist. Clear the cart before
            // the external request so a browser retry cannot build another order.
            $_SESSION['mpesa_active_order'] = $orderNumber;
            $_SESSION['mpesa_active_order_token'] = $orderAccessToken;
            cart_clear();
            try {
                $result = mpesa_initiate_stk(
                    ['order_number' => $orderNumber, 'total' => $grandTotal],
                    (string)$mpesaPhone
                );
                mpesa_mark_pending($paymentId, $result['response']);
                unset($_SESSION['checkout_token']);
                redirect('mpesa_wait.php?o=' . urlencode($orderNumber) . '&t=' . urlencode($orderAccessToken));
            } catch (MpesaException $e) {
                try {
                    if ($e->isTransient()) {
                        mpesa_mark_initiation_pending($paymentId, $e->getMessage());
                    } else {
                        mpesa_mark_terminal($paymentId, 'failed', $e->getMessage());
                    }
                } catch (Throwable $stateError) {
                    error_log('Could not persist M-Pesa initiation state: ' . $stateError->getMessage());
                }
                unset($_SESSION['checkout_token']);
                redirect('mpesa_wait.php?o=' . urlencode($orderNumber) . '&t=' . urlencode($orderAccessToken));
            } catch (Throwable $e) {
                try {
                    mpesa_mark_initiation_pending($paymentId, 'M-Pesa initiation could not be confirmed.');
                } catch (Throwable $stateError) {
                    error_log('Could not persist M-Pesa initiation state: ' . $stateError->getMessage());
                }
                unset($_SESSION['checkout_token']);
                redirect('mpesa_wait.php?o=' . urlencode($orderNumber) . '&t=' . urlencode($orderAccessToken));
            }
        }

        if (!$errors) {
            cart_clear();
            unset($_SESSION['checkout_token']);
            redirect('order_confirmation.php?o=' . urlencode($orderNumber) . '&t=' . urlencode($orderAccessToken));
        }
    }
}

$deliveryPricingPending = $deliveryPricingReady
    && $old['delivery_method'] === 'delivery'
    && $deliveryQuote === null;
$deliveryConfigurationUnavailable = !$deliveryPricingReady;
$summaryDeliveryFee = $deliveryQuote !== null
    ? (float)$deliveryQuote['delivery_fee']
    : ($deliveryPricingPending
        || ($old['delivery_method'] === 'delivery' && $deliveryConfigurationUnavailable)
            ? null
            : $deliveryFee);
$summaryTotal = $cart['subtotal'] + ($summaryDeliveryFee ?? 0.0) + $donationAmount;
$storeLatitudeForJs = $deliveryPricingSettings['ready'] ? $deliveryPricingSettings['store_latitude'] : '';
$storeLongitudeForJs = $deliveryPricingSettings['ready'] ? $deliveryPricingSettings['store_longitude'] : '';
$rateForJs = $deliveryPricingSettings['ready'] ? $deliveryPricingSettings['rate_per_km'] : 0;

include __DIR__ . '/includes/shop_header.php';
?>

<section class="section">
  <div class="container">

    <nav aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= e(shop_url()) ?>">Home</a></li>
        <li class="breadcrumb-item"><a href="<?= e(shop_url('cart.php')) ?>">Cart</a></li>
        <li class="breadcrumb-item active">Checkout</li>
      </ol>
    </nav>

    <div class="section-header">
      <h2>Checkout</h2>
      <p>Just a few details and your order is placed securely.</p>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <strong>Please fix the following:</strong>
        <ul class="mb-0">
          <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" id="checkoutForm" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="checkout_token" value="<?= e($checkoutToken) ?>">

      <div class="row g-4">
        <div class="col-lg-7">
          <div class="card">
            <div class="card-body">
              <h5 class="card-title">Contact information</h5>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Full name *</label>
                  <input name="customer_name" class="form-control"
                         value="<?= e($old['customer_name']) ?>" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Email *</label>
                  <input name="customer_email" type="email" class="form-control"
                         value="<?= e($old['customer_email']) ?>" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Phone *</label>
                  <input name="customer_phone" class="form-control"
                         value="<?= e($old['customer_phone']) ?>"
                         placeholder="0712345678" required>
                  <?php if ($mpesaAvailable): ?>
                    <div class="form-text">Use the M-Pesa number that should receive the payment prompt.</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <div class="card mt-4">
            <div class="card-body">
              <h5 class="card-title">Fulfillment</h5>
              <div class="form-check delivery-option p-3 mb-2">
                <input class="form-check-input" type="radio" name="delivery_method"
                       id="delivery_method_delivery" value="delivery"
                       <?= $old['delivery_method'] === 'delivery' && $deliveryPricingReady ? 'checked' : '' ?>
                       <?= $deliveryPricingReady ? '' : 'disabled' ?>>
                <label class="form-check-label" for="delivery_method_delivery">
                  <strong><i class="bi bi-truck"></i> Delivery</strong>
                  <?php if ($deliveryPricingReady): ?>
                    <span class="d-block small text-muted">Share your location to calculate the delivery fee.</span>
                  <?php else: ?>
                    <span class="d-block small text-danger">Temporarily unavailable — configure store coordinates and price/km in admin settings.</span>
                  <?php endif; ?>
                </label>
              </div>
              <div class="form-check delivery-option p-3">
                <input class="form-check-input" type="radio" name="delivery_method"
                       id="delivery_method_pickup" value="pickup"
                       <?= $old['delivery_method'] === 'pickup' || !$deliveryPricingReady ? 'checked' : '' ?>>
                <label class="form-check-label" for="delivery_method_pickup">
                  <strong><i class="bi bi-shop"></i> Store Pickup</strong>
                  <span class="d-block small text-muted"><?= e($pickupAddress) ?></span>
                </label>
              </div>
            </div>
          </div>

          <div class="card mt-4">
            <div class="card-body">
              <h5 class="card-title">Shipping address</h5>
              <div id="deliveryLocationPanel">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                  <button type="button" class="btn btn-outline-primary" id="shareLocationButton">
                    <i class="bi bi-geo-alt"></i> Share my location
                  </button>
                  <span class="small text-muted" id="locationStatus" role="status">
                    Optional — your manual address remains the main delivery information.
                  </span>
                </div>
                <div class="location-preview d-none" id="locationPreview">
                  <i class="bi bi-pin-map-fill text-success"></i>
                  <span>Location confirmed: <strong id="locationCoordinates"></strong></span>
                  <a href="#" id="locationMapLink" target="_blank" rel="noopener">Open map</a>
                  <button type="button" class="btn btn-sm btn-link text-danger p-0" id="clearLocationButton">Clear</button>
                </div>
                <input type="hidden" name="latitude" id="latitude" value="<?= e($old['latitude']) ?>">
                <input type="hidden" name="longitude" id="longitude" value="<?= e($old['longitude']) ?>">
                <input type="hidden" name="accuracy_meters" id="accuracy_meters" value="<?= e($old['accuracy_meters']) ?>">
              </div>
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label" id="addressLabel">Street address *</label>
                  <textarea name="shipping_address" id="shipping_address" class="form-control" rows="2" required><?= e($old['shipping_address']) ?></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label" id="cityLabel">City *</label>
                  <input name="shipping_city" id="shipping_city" class="form-control"
                         value="<?= e($old['shipping_city']) ?>" required>
                </div>
                <div class="col-md-3">
                  <label class="form-label">ZIP / Postal</label>
                  <input name="shipping_zip" class="form-control"
                         value="<?= e($old['shipping_zip']) ?>">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Country</label>
                  <input name="shipping_country" class="form-control"
                         value="<?= e($old['shipping_country']) ?>">
                </div>
              </div>
              <div class="small text-muted mt-3" id="pickupInstructions">
                <?= e($pickupInstructions) ?>
              </div>
            </div>
          </div>
          <div class="card mt-4">
            <div class="card-body">
              <h5 class="card-title">Payment method</h5>

              <?php if ($mpesaAvailabilityError): ?>
                <div class="alert alert-warning small py-2">
                  <i class="bi bi-info-circle"></i>
                  M-Pesa is unavailable for this order: <?= e($mpesaAvailabilityError) ?>
                </div>
              <?php endif; ?>

              <?php if ($mpesaAvailable): ?>
                <div class="form-check payment-option mpesa-option">
                  <input class="form-check-input" type="radio" name="payment_method"
                         id="pm_mpesa" value="mpesa"
                         <?= $old['payment_method'] === 'mpesa' ? 'checked' : '' ?>>
                  <label class="form-check-label" for="pm_mpesa">
                    <strong><i class="bi bi-phone"></i> M-Pesa</strong>
                    <div class="small text-muted">A secure STK prompt will be sent to your phone. Payment is confirmed server-side.</div>
                  </label>
                </div>
              <?php endif; ?>

              <?php if ($codAvailable): ?>
                <div class="form-check payment-option mt-2">
                  <input class="form-check-input" type="radio" name="payment_method"
                         id="pm_cod" value="cod"
                         <?= $old['payment_method'] === 'cod' ? 'checked' : '' ?>>
                  <label class="form-check-label" for="pm_cod">
                    <strong><i class="bi bi-cash-stack"></i> Payment on Delivery</strong>
                    <div class="small text-muted">Pay with cash when the order arrives.</div>
                  </label>
                </div>
              <?php endif; ?>

              <?php if (!$availablePaymentMethods): ?>
                <div class="alert alert-danger small mb-0">
                  No payment methods are currently available. Please contact the store administrator.
                </div>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($donationsAvailable): ?>
            <div class="card mt-4 donation-card">
              <div class="card-body">
                <h5 class="card-title"><i class="bi bi-heart-fill text-danger"></i> Support <?= e($donationSettings['charity_name']) ?></h5>
                <?php if ($donationSettings['charity_description'] !== ''): ?><p class="text-muted small mb-2"><?= e($donationSettings['charity_description']) ?></p><?php endif; ?>
                <?php if ($donationSettings['charity_website'] !== ''): ?><a class="small" href="<?= e($donationSettings['charity_website']) ?>" target="_blank" rel="noopener">Learn more about this charity</a><?php endif; ?>
                <input type="hidden" name="donation_amount" id="donationAmountInput" value="<?= e(number_format($donationAmount, 2, '.', '')) ?>">
                <div class="donation-options mt-3">
                  <label class="donation-option">
                    <input type="radio" name="donation_choice" value="0" data-donation-amount="0" <?= $donationAmount <= 0 ? 'checked' : '' ?>>
                    <span>No donation</span>
                  </label>
                  <?php foreach ($donationSettings['presets'] as $preset): ?>
                    <label class="donation-option">
                      <input type="radio" name="donation_choice" value="<?= e(number_format($preset, 2, '.', '')) ?>" data-donation-amount="<?= e(number_format($preset, 2, '.', '')) ?>" <?= abs($donationAmount - $preset) < 0.001 ? 'checked' : '' ?>>
                      <span><?= e(price($preset)) ?></span>
                    </label>
                  <?php endforeach; ?>
                  <label class="donation-option donation-custom-option">
                    <input type="radio" name="donation_choice" value="custom" data-donation-custom="1" <?= $donationAmount > 0 && !in_array($donationAmount, $donationSettings['presets'], true) ? 'checked' : '' ?>>
                    <span>Custom</span>
                  </label>
                </div>
                <div class="input-group input-group-sm mt-2" id="customDonationGroup">
                  <span class="input-group-text"><?= e(setting('currency_symbol', '$')) ?></span>
                  <input type="number" id="customDonationAmount" class="form-control" min="0" max="1000000" step="0.01" value="<?= e($donationAmount > 0 ? number_format($donationAmount, 2, '.', '') : '') ?>" placeholder="Enter amount">
                </div>
                <div class="small text-muted mt-2">Donation is optional and added to your final order total.</div>
              </div>
            </div>
          <?php endif; ?>

          <div class="card mt-4">
            <div class="card-body">
              <label class="form-label fw-semibold">Order notes (optional)</label>
              <textarea name="notes" class="form-control" rows="3"
                        placeholder="Anything we should know about delivery?"><?= e($old['notes']) ?></textarea>
            </div>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="card checkout-summary">
            <div class="card-body">
              <h5 class="card-title">Your order</h5>
              <hr>
              <ul class="list-unstyled mb-3">
                <?php foreach ($cart['items'] as $item): ?>
                  <li class="d-flex justify-content-between mb-2">
                    <span class="text-truncate me-2" style="max-width:70%">
                      <?= e($item['product']['name']) ?>
                      <span class="text-muted small">× <?= (int)$item['qty'] ?></span>
                    </span>
                    <span class="fw-semibold"><?= e(price($item['line_total'])) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
              <hr>
              <div class="d-flex justify-content-between mb-2">
                <span>Subtotal</span>
                <strong><?= e(price($cart['subtotal'])) ?></strong>
              </div>
              <div id="deliveryPricingSummary"
                   data-pricing-ready="<?= $deliveryPricingReady ? '1' : '0' ?>"
                   data-store-latitude="<?= e((string)$storeLatitudeForJs) ?>"
                   data-store-longitude="<?= e((string)$storeLongitudeForJs) ?>"
                   data-rate-per-km="<?= e(number_format((float)$rateForJs, 4, '.', '')) ?>"
                   data-subtotal="<?= e(number_format($cart['subtotal'], 2, '.', '')) ?>"
                   data-currency-symbol="<?= e(setting('currency_symbol', '$')) ?>">
                <?php if ($donationsAvailable): ?>
                  <div class="d-flex justify-content-between mb-2">
                    <span>Donation</span>
                    <strong id="donationSummary"><?= $donationAmount > 0 ? e(price($donationAmount)) : '<span class="text-muted">None</span>' ?></strong>
                  </div>
                <?php endif; ?>
                <div class="d-flex justify-content-between mb-2">
                  <span>Distance</span>
                  <strong id="distanceSummary">
                    <?php if ($deliveryQuote && $deliveryQuote['distance_km'] !== null): ?>
                      <?= e(number_format((float)$deliveryQuote['actual_distance_km'], 2)) ?> km actual · <?= (int)$deliveryQuote['billable_distance_km'] ?> km billable
                    <?php elseif ($deliveryPricingPending): ?>
                      <span class="text-warning">Share location to calculate</span>
                    <?php elseif ($deliveryConfigurationUnavailable && $old['delivery_method'] === 'delivery'): ?>
                      <span class="text-danger">Unavailable</span>
                    <?php else: ?>
                      <span class="text-muted">Store pickup</span>
                    <?php endif; ?>
                  </strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                  <span><?= $old['delivery_method'] === 'pickup' ? 'Pickup fee' : 'Delivery fee' ?></span>
                  <strong id="deliveryFeeSummary">
                    <?php if ($summaryDeliveryFee === null && $deliveryConfigurationUnavailable && $old['delivery_method'] === 'delivery'): ?>
                      <span class="text-danger">Delivery unavailable</span>
                    <?php elseif ($summaryDeliveryFee === null): ?>
                      <span class="text-warning">Calculated after location</span>
                    <?php elseif ($summaryDeliveryFee > 0): ?>
                      <?= e(price($summaryDeliveryFee)) ?>
                    <?php else: ?>
                      <span class="text-success">Free</span>
                    <?php endif; ?>
                  </strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between fs-5 mb-3">
                  <span>Total</span>
                  <strong id="orderTotalSummary"><?= e(price($summaryTotal)) ?></strong>
                </div>
              </div>
              <button class="btn btn-primary w-100 btn-lg" <?= $availablePaymentMethods ? '' : 'disabled' ?>>
                <i class="bi bi-check2-circle"></i> Place order
              </button>
              <a href="<?= e(shop_url('cart.php')) ?>"
                 class="btn btn-link w-100 mt-2">Back to cart</a>
            </div>
          </div>
        </div>
      </div>
    </form>

  </div>
</section>

<script>
(function () {
    const deliveryRadio = document.getElementById('delivery_method_delivery');
    const pickupRadio = document.getElementById('delivery_method_pickup');
    const locationPanel = document.getElementById('deliveryLocationPanel');
    const shareButton = document.getElementById('shareLocationButton');
    const clearButton = document.getElementById('clearLocationButton');
    const status = document.getElementById('locationStatus');
    const preview = document.getElementById('locationPreview');
    const coordinates = document.getElementById('locationCoordinates');
    const mapLink = document.getElementById('locationMapLink');
    const latitude = document.getElementById('latitude');
    const longitude = document.getElementById('longitude');
    const accuracy = document.getElementById('accuracy_meters');
    const address = document.getElementById('shipping_address');
    const city = document.getElementById('shipping_city');
    const addressLabel = document.getElementById('addressLabel');
    const cityLabel = document.getElementById('cityLabel');
    const pickupInstructions = document.getElementById('pickupInstructions');
    const checkoutForm = document.getElementById('checkoutForm');
    const pricingSummary = document.getElementById('deliveryPricingSummary');
    const distanceSummary = document.getElementById('distanceSummary');
    const deliveryFeeSummary = document.getElementById('deliveryFeeSummary');
    const orderTotalSummary = document.getElementById('orderTotalSummary');
    const donationInput = document.getElementById('donationAmountInput');
    const donationSummary = document.getElementById('donationSummary');
    const customDonationGroup = document.getElementById('customDonationGroup');
    const customDonationAmount = document.getElementById('customDonationAmount');

    if (!deliveryRadio || !pickupRadio || !shareButton || !pricingSummary) return;

    function updateQuotePreview(lat, lng) {
        const pricingReady = pricingSummary.dataset.pricingReady === '1';
        const subtotal = Number(pricingSummary.dataset.subtotal || 0);
        const flatFee = Number(pricingSummary.dataset.flatFee || 0);
        const symbol = pricingSummary.dataset.currencySymbol || '';
        const formatMoney = value => symbol + Number(value).toFixed(2);
        const donation = donationInput ? Math.max(0, Number(donationInput.value || 0)) : 0;
        if (donationSummary) donationSummary.textContent = donation > 0 ? formatMoney(donation) : 'None';

        if (pickupRadio.checked) {
            distanceSummary.textContent = 'Store pickup';
            deliveryFeeSummary.textContent = 'Free';
            deliveryFeeSummary.className = 'text-success';
            orderTotalSummary.textContent = formatMoney(subtotal + donation);
            return;
        }
        if (!pricingReady) {
            distanceSummary.textContent = 'Unavailable';
            distanceSummary.className = 'text-danger';
            deliveryFeeSummary.textContent = 'Delivery unavailable';
            deliveryFeeSummary.className = 'text-danger';
            orderTotalSummary.textContent = formatMoney(subtotal + donation);
            return;
        }
        if (!delivery_location_is_valid_placeholder(lat, lng)) {
            distanceSummary.textContent = 'Share location to calculate';
            distanceSummary.className = 'text-warning';
            deliveryFeeSummary.textContent = 'Calculated after location';
            deliveryFeeSummary.className = 'text-warning';
            orderTotalSummary.textContent = formatMoney(subtotal + donation);
            return;
        }
        const storeLat = Number(pricingSummary.dataset.storeLatitude);
        const storeLng = Number(pricingSummary.dataset.storeLongitude);
        const rate = Number(pricingSummary.dataset.ratePerKm || 0);
        const earthRadius = 6371.0088;
        const latDelta = (Number(lat) - storeLat) * Math.PI / 180;
        const lngDelta = (Number(lng) - storeLng) * Math.PI / 180;
        const a = Math.sin(latDelta / 2) ** 2
            + Math.cos(storeLat * Math.PI / 180) * Math.cos(Number(lat) * Math.PI / 180)
            * Math.sin(lngDelta / 2) ** 2;
        const distance = 2 * earthRadius * Math.asin(Math.sqrt(Math.min(1, Math.max(0, a))));
        const billableDistance = Math.ceil(distance);
        const fee = Math.round(billableDistance * rate * 100) / 100;
        distanceSummary.textContent = distance.toFixed(2) + ' km actual · ' + billableDistance + ' km billable';
        distanceSummary.className = '';
        deliveryFeeSummary.textContent = fee > 0 ? formatMoney(fee) : 'Free';
        deliveryFeeSummary.className = fee > 0 ? '' : 'text-success';
        orderTotalSummary.textContent = formatMoney(subtotal + fee + donation);
    }

    function delivery_location_is_valid_placeholder(lat, lng) {
        return lat !== null && lng !== null && lat !== '' && lng !== ''
            && Number.isFinite(Number(lat)) && Number.isFinite(Number(lng))
            && Number(lat) >= -90 && Number(lat) <= 90
            && Number(lng) >= -180 && Number(lng) <= 180;
    }

    function clearLocation() {
        latitude.value = '';
        longitude.value = '';
        accuracy.value = '';
        preview.classList.add('d-none');
        mapLink.href = '#';
        status.textContent = 'Optional — your manual address remains the main delivery information.';
        status.className = 'small text-muted';
        updateQuotePreview(null, null);
    }

    function showLocation(lat, lng, accuracyValue) {
        const latText = Number(lat).toFixed(6);
        const lngText = Number(lng).toFixed(6);
        latitude.value = latText;
        longitude.value = lngText;
        accuracy.value = accuracyValue !== null && accuracyValue !== undefined
            ? Number(accuracyValue).toFixed(2) : '';
        coordinates.textContent = latText + ', ' + lngText;
        mapLink.href = 'https://www.openstreetmap.org/?mlat=' + encodeURIComponent(latText)
            + '&mlon=' + encodeURIComponent(lngText)
            + '#map=17/' + encodeURIComponent(latText) + '/' + encodeURIComponent(lngText);
        preview.classList.remove('d-none');
        status.textContent = 'Location captured. Confirm the pin and continue.';
        status.className = 'small text-success';
        updateQuotePreview(lat, lng);
    }

    function updateMode() {
        const deliverySelected = deliveryRadio.checked;
        locationPanel.hidden = !deliverySelected;
        shareButton.disabled = !deliverySelected;
        pickupInstructions.classList.toggle('d-none', deliverySelected);
        address.required = deliverySelected;
        city.required = deliverySelected;
        addressLabel.textContent = deliverySelected ? 'Street address *' : 'Address / pickup notes';
        cityLabel.textContent = deliverySelected ? 'City *' : 'City / pickup area';
        if (!deliverySelected) clearLocation();
        else updateQuotePreview(latitude.value || null, longitude.value || null);
    }

    function syncDonation(choice) {
        if (!donationInput) return;
        let amount = choice && choice.dataset.donationAmount !== undefined
            ? Number(choice.dataset.donationAmount)
            : Number(customDonationAmount ? customDonationAmount.value : 0);
        if (!Number.isFinite(amount) || amount < 0) amount = 0;
        donationInput.value = amount.toFixed(2);
        if (customDonationGroup) customDonationGroup.classList.toggle('d-none', !choice || choice.dataset.donationCustom !== '1');
        updateQuotePreview(latitude.value || null, longitude.value || null);
    }

    document.querySelectorAll('input[name="donation_choice"]').forEach(function (choice) {
        choice.addEventListener('change', function () { syncDonation(choice); });
    });
    if (customDonationAmount) {
        customDonationAmount.addEventListener('input', function () {
            const selected = document.querySelector('input[name="donation_choice"][data-donation-custom="1"]');
            if (selected && selected.checked) syncDonation(selected);
        });
    }
    const initialDonationChoice = document.querySelector('input[name="donation_choice"]:checked');
    if (initialDonationChoice) syncDonation(initialDonationChoice);

    shareButton.addEventListener('click', function () {
        if (!deliveryRadio.checked) return;
        if (!navigator.geolocation) {
            status.textContent = 'This browser does not support location sharing. You can continue with your manual address.';
            status.className = 'small text-warning';
            return;
        }
        shareButton.disabled = true;
        status.textContent = 'Requesting your location…';
        status.className = 'small text-muted';
        navigator.geolocation.getCurrentPosition(function (position) {
            shareButton.disabled = false;
            showLocation(position.coords.latitude, position.coords.longitude, position.coords.accuracy);
        }, function (error) {
            shareButton.disabled = false;
            const messages = {
                1: 'Location permission was denied. You can continue with your manual address.',
                2: 'Your location is currently unavailable. Check your device settings or continue manually.',
                3: 'Location request timed out. You can try again or continue with your manual address.'
            };
            status.textContent = messages[error.code] || 'Could not get your location. You can continue manually.';
            status.className = 'small text-warning';
        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 300000 });
    });

    clearButton.addEventListener('click', clearLocation);
    checkoutForm.addEventListener('submit', function (event) {
        const pricingReady = pricingSummary.dataset.pricingReady === '1';
        if (pricingReady && deliveryRadio.checked
            && !delivery_location_is_valid_placeholder(latitude.value, longitude.value)) {
            event.preventDefault();
            status.textContent = 'Share your delivery location before placing this order so the final fee can be calculated.';
            status.className = 'small text-danger';
            shareButton.focus();
        }
    });
    deliveryRadio.addEventListener('change', updateMode);
    pickupRadio.addEventListener('change', updateMode);
    updateMode();

    if (latitude.value && longitude.value) {
        showLocation(latitude.value, longitude.value, accuracy.value || null);
    }
})();
</script>

<?php include __DIR__ . '/includes/shop_footer.php'; ?>
