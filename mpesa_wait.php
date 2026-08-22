<?php
require_once __DIR__ . '/includes/shop_bootstrap.php';
require_once __DIR__ . '/includes/mpesa.php';
require_once __DIR__ . '/includes/order_access.php';

$pageTitle = 'M-Pesa payment';
$orderNumber = trim((string)($_GET['o'] ?? ''));
$accessToken = trim((string)($_GET['t'] ?? ''));
$payment = null;

if ($orderNumber !== '') {
    try {
        $payment = mpesa_find_payment_by_order_number($orderNumber);
    } catch (Throwable $e) {
        $payment = null;
    }
}
if ($payment) {
    try {
        if (!order_access_token_valid((int)$payment['order_id'], $accessToken)) $payment = null;
    } catch (Throwable $e) {
        $payment = null;
    }
}

// Manual fallback for a delayed callback. A successful STK query is not enough
// to mark an order paid because it does not contain the amount/phone metadata;
// only a validated callback can do that. Non-zero query results can safely close
// a pending attempt.

// Local expiry runs on EVERY view of a pending payment (including plain
// auto-reloads) so a lost callback always terminates the wait loop without
// any outbound Daraja call.
$expiresPassed = $payment && $payment['status'] === 'pending'
    && !empty($payment['expires_at'])
    && strtotime((string)$payment['expires_at'] . ' UTC') !== false
    && strtotime((string)$payment['expires_at'] . ' UTC') < time();
if ($expiresPassed && !empty($payment['checkout_request_id'])) {
    try {
        mpesa_mark_terminal(
            (int)$payment['id'],
            'expired',
            'Payment window elapsed while awaiting user confirmation.'
        );
        $payment = mpesa_find_payment_by_order_number($orderNumber);
    } catch (Throwable $e) {
        error_log('M-Pesa local expiry failed: ' . $e->getMessage());
    }
}

if ($payment && $payment['status'] === 'pending'
    && ($_GET['check'] ?? '') === '1'
    && !empty($payment['checkout_request_id'])) {
    // Throttle upstream Daraja queries: at most one STK status query per
    // payment every 10 seconds per browser session, so an auto-reloading or
    // scripted tab cannot hammer the API and risk credential throttling.
    $checkThrottleKey = 'mpesa_stk_check_' . (int)$payment['id'];
    $lastCheckAt = (int)($_SESSION[$checkThrottleKey] ?? 0);
    if ($lastCheckAt && (time() - $lastCheckAt) < 10) {
        // Too soon since the last upstream query; just keep showing status.
    } else {
        $_SESSION[$checkThrottleKey] = time();
        try {
            $query = mpesa_query_stk((string)$payment['checkout_request_id']);
            if (isset($query['ResultCode']) && (int)$query['ResultCode'] !== 0) {
                mpesa_mark_terminal(
                    (int)$payment['id'],
                    mpesa_result_status($query['ResultCode']),
                    (string)($query['ResultDesc'] ?? 'M-Pesa reported an unsuccessful payment.')
                );
                $payment = mpesa_find_payment_by_order_number($orderNumber);
            }
        } catch (Throwable $e) {
            error_log('M-Pesa status query failed: ' . $e->getMessage());
        }
    }
}

$status = $payment['status'] ?? '';
if ($payment && !empty($_SESSION['mpesa_active_order'])) {
    if ($_SESSION['mpesa_active_order'] === $payment['order_number']
        && !in_array($status, ['initiated', 'pending'], true)) {
        unset($_SESSION['mpesa_active_order'], $_SESSION['mpesa_active_order_token']);
    }
}
$isPending = $status === 'pending';
$isInitiated = $status === 'initiated';
$siteEmail = setting('site_email', '');
$phoneDisplay = '';
if ($payment && !empty($payment['customer_phone'])) {
    $phone = (string)$payment['customer_phone'];
    $phoneDisplay = strlen($phone) > 4
        ? str_repeat('*', strlen($phone) - 4) . substr($phone, -4)
        : $phone;
}

include __DIR__ . '/includes/shop_header.php';
?>

<?php if ($isPending): ?>
  <script>
    // This only refreshes the display. Paid state is set by the server-side
    // Daraja callback, never by this browser script. Reloads are capped so a
    // lost callback cannot loop this page forever.
    (function () {
      var key = 'mpesaWaitReloads';
      try {
        var n = Number(sessionStorage.getItem(key) || 0) + 1;
        if (n > 120) return; // ~10 minutes at 5s; stop and show guidance.
        sessionStorage.setItem(key, String(n));
      } catch (e) { /* storage unavailable: keep previous behavior */ }
      window.setTimeout(function () { window.location.reload(); }, 5000);
    })();
  </script>
<?php endif; ?>

<section class="section">
  <div class="container" style="max-width: 760px;">
    <?php if (!$payment): ?>
      <div class="empty-state">
        <i class="bi bi-question-circle"></i>
        <h3>Payment attempt not found</h3>
        <p>We couldn't find a M-Pesa payment for that order reference.</p>
        <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-primary">Back to shop</a>
      </div>
    <?php else: ?>
      <div class="text-center mb-4">
        <?php if ($status === 'successful'): ?>
          <div class="display-1 text-success"><i class="bi bi-check-circle-fill"></i></div>
          <h2 class="mt-2">Payment received</h2>
          <p class="lead text-muted">Your M-Pesa payment was verified successfully.</p>
        <?php elseif ($isPending): ?>
          <div class="display-1 text-warning"><i class="bi bi-phone-vibrate"></i></div>
          <h2 class="mt-2">Check your phone</h2>
          <p class="lead text-muted">We've sent an M-Pesa payment prompt to <?= e($phoneDisplay) ?>.</p>
        <?php elseif ($isInitiated): ?>
          <div class="display-1 text-info"><i class="bi bi-hourglass-split"></i></div>
          <h2 class="mt-2">Payment request is being prepared</h2>
          <p class="lead text-muted">We could not confirm the M-Pesa prompt yet. Please do not submit this order again.</p>
        <?php else: ?>
          <div class="display-1 text-secondary"><i class="bi bi-x-circle-fill"></i></div>
          <h2 class="mt-2"><?= e(mpesa_payment_status_label($status)) ?></h2>
          <p class="lead text-muted">The order remains unpaid and was not released for processing.</p>
        <?php endif; ?>
        <div class="badge bg-primary fs-6 px-3 py-2">Order #<?= e($payment['order_number']) ?></div>
      </div>

      <div class="card mb-4">
        <div class="card-body">
          <div class="row g-3">
            <div class="col-sm-6">
              <div class="text-muted small text-uppercase">Payment status</div>
              <span class="badge <?= e(mpesa_payment_status_class($status)) ?> mt-1">
                <?= e(mpesa_payment_status_label($status)) ?>
              </span>
            </div>
            <div class="col-sm-6 text-sm-end">
              <div class="text-muted small text-uppercase">Amount</div>
              <div class="h5 mb-0"><?= e(price((float)$payment['amount'])) ?></div>
            </div>
          </div>

          <?php if ($isPending): ?>
            <hr>
            <ol class="mb-0 text-muted">
              <li>Open the M-Pesa prompt on your phone.</li>
              <li>Confirm the amount and enter your M-Pesa PIN.</li>
              <li>Keep this page open while we verify the callback.</li>
            </ol>
          <?php elseif ($isInitiated): ?>
            <hr>
            <p class="mb-0 text-muted">
              The order is safely held while the server checks the Daraja request.
              If no prompt arrives, contact <?= e($siteEmail ?: 'the store team') ?> with your order number.
            </p>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($status === 'successful'): ?>
        <div class="alert alert-success">
          <i class="bi bi-shield-check"></i>
          The payment was verified on the server. Receipt reference:
          <strong><?= e($payment['provider_transaction_id'] ?: 'Recorded') ?></strong>.
        </div>
        <div class="text-center d-flex flex-wrap justify-content-center gap-2">
          <a href="<?= e(shop_url('order_confirmation.php?o=' . urlencode($payment['order_number']) . '&t=' . urlencode($accessToken))) ?>"
             class="btn btn-primary"><i class="bi bi-receipt"></i> View order</a>
          <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-outline-primary">Continue shopping</a>
        </div>
      <?php elseif ($isPending): ?>
        <div class="alert alert-info">
          <i class="bi bi-info-circle"></i>
          This page checks the server-side payment status every few seconds.
          Do not submit the payment again while the prompt is pending.
        </div>
        <div class="text-center d-flex flex-wrap justify-content-center gap-2">
          <a href="<?= e(shop_url('mpesa_wait.php?o=' . urlencode($payment['order_number']) . '&t=' . urlencode($accessToken))) ?>"
             class="btn btn-outline-primary"><i class="bi bi-arrow-clockwise"></i> Refresh status</a>
          <a href="<?= e(shop_url('mpesa_wait.php?o=' . urlencode($payment['order_number']) . '&t=' . urlencode($accessToken) . '&check=1')) ?>"
             class="btn btn-outline-secondary"><i class="bi bi-cloud-check"></i> Check with M-Pesa</a>
        </div>
      <?php elseif ($isInitiated): ?>
        <div class="alert alert-info">
          <i class="bi bi-info-circle"></i>
          Your order was created safely, but the payment prompt has not been confirmed.
          Please contact the store before trying to place another order.
        </div>
        <div class="text-center">
          <a href="<?= e(shop_url('mpesa_wait.php?o=' . urlencode($payment['order_number']) . '&t=' . urlencode($accessToken))) ?>"
             class="btn btn-outline-primary"><i class="bi bi-arrow-clockwise"></i> Check again</a>
        </div>
      <?php else: ?>
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle"></i>
          No successful payment was recorded for this attempt. If your M-Pesa account
          shows a debit, contact <?= e($siteEmail ?: 'the store team') ?> with order
          <strong><?= e($payment['order_number']) ?></strong>.
        </div>
        <div class="text-center">
          <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-primary">Return to shop</a>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/includes/shop_footer.php'; ?>
