<?php
require_once __DIR__ . '/includes/shop_bootstrap.php';
require_once __DIR__ . '/includes/payment_methods.php';
require_once __DIR__ . '/includes/delivery.php';
require_once __DIR__ . '/includes/donations.php';
require_once __DIR__ . '/includes/order_access.php';

$pageTitle = 'Track your order';

// Pre-fill from query string (e.g. when linking from confirmation page).
$orderNumber = trim((string)($_GET['order'] ?? $_POST['order'] ?? ''));
$email       = trim((string)($_GET['email'] ?? $_POST['email'] ?? ''));
$accessToken = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));

$order  = null;
$items  = [];
$payment = null;
$latestRefund = null;
$deliverySnapshot = null;
$pricingSnapshot = null;
$pickupSnapshot = null;
$donationSnapshot = null;
$errors = [];
$searched = false;

if ($orderNumber !== '' || $email !== '') {
    $searched = true;

    if ($orderNumber === '') {
        $errors[] = 'Please enter your order number.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter the email you used at checkout.';
    }

    if (!$errors) {
        $stmt = db()->prepare(
            "SELECT * FROM orders
             WHERE order_number = :n AND customer_email = :e
             LIMIT 1"
        );
        $stmt->execute([':n' => $orderNumber, ':e' => $email]);
        $order = $stmt->fetch();
        if ($order) {
            try {
                if (order_has_access_token((int)$order['id'])
                    && !order_access_token_valid((int)$order['id'], $accessToken)) {
                    $order = null;
                    $errors[] = 'Use the secure tracking link from your order confirmation.';
                }
            } catch (Throwable $e) {
                $order = null;
                $errors[] = 'This tracking link is no longer available.';
            }
        }

        if (!$order) {
            $errors[] = "We couldn't find an order matching that number and email. "
                      . "Please double-check both and try again.";
        } else {
            // Legacy orders predate per-order access tokens. Mint one now so
            // this visit hands the customer a secure link and later visits
            // require it instead of a guessable number + email pair.
            $mintedToken = order_access_token_ensure((int)$order['id']);
            if ($mintedToken !== null) {
                $accessToken = $mintedToken;
            }
            $stmt = db()->prepare("SELECT * FROM order_items WHERE order_id = :id");
            $stmt->execute([':id' => $order['id']]);
            $items = $stmt->fetchAll();
            try {
                $deliverySnapshot = order_delivery_snapshot((int)$order['id'], false);
            } catch (Throwable $e) {
                $deliverySnapshot = null;
            }
            try {
                $pricingSnapshot = order_delivery_pricing_snapshot((int)$order['id'], false);
            } catch (Throwable $e) {
                $pricingSnapshot = null;
            }
            try {
                $pickupSnapshot = order_pickup_snapshot((int)$order['id']);
            } catch (Throwable $e) {
                $pickupSnapshot = null;
            }
            try {
                $donationSnapshot = order_donation_snapshot((int)$order['id']);
            } catch (Throwable $e) {
                $donationSnapshot = null;
            }
            try {
                $payment = mpesa_find_payment_by_order_number($orderNumber);
            } catch (Throwable $e) {
                $payment = null;
            }
            if ($payment) {
                try {
                    $refunds = mpesa_refunds_for_payment((int)$payment['id']);
                    $latestRefund = $refunds[0] ?? null;
                } catch (Throwable $e) {
                    $latestRefund = null;
                }
            }
        }
    }
}

/**
 * Status timeline (in order). 'cancelled' is shown separately as a red endpoint.
 */
$timeline = [
    'pending'    => ['label' => 'Order placed',    'icon' => 'bi-receipt'],
    'processing' => ['label' => 'Processing',      'icon' => 'bi-box-seam'],
    'shipped'    => ['label' => 'Shipped',         'icon' => 'bi-truck'],
    'completed'  => ['label' => 'Delivered',       'icon' => 'bi-check2-circle'],
];

$statusBadge = [
    'pending'    => 'bg-warning text-dark',
    'processing' => 'bg-info text-white',
    'shipped'    => 'bg-primary text-white',
    'completed'  => 'bg-success text-white',
    'cancelled'  => 'bg-secondary text-white',
];

include __DIR__ . '/includes/shop_header.php';
?>

<section class="section">
  <div class="container" style="max-width: 900px;">

    <nav aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= e(shop_url()) ?>">Home</a></li>
        <li class="breadcrumb-item active">Track order</li>
      </ol>
    </nav>

    <div class="section-header text-center">
      <h2><i class="bi bi-geo-alt"></i> Track your order</h2>
      <p>Enter your order number and email to see the latest status.</p>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <form method="get" class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Order number</label>
            <input type="text" name="order" class="form-control form-control-lg"
                   value="<?= e($orderNumber) ?>"
                   placeholder="e.g. ORD-202605-0001" required>
          </div>
          <input type="hidden" name="token" value="<?= e($accessToken) ?>">
          <div class="col-md-6">
            <label class="form-label">Email used at checkout</label>
            <input type="email" name="email" class="form-control form-control-lg"
                   value="<?= e($email) ?>"
                   placeholder="you@example.com" required>
          </div>
          <div class="col-12 d-grid">
            <button class="btn btn-primary btn-lg">
              <i class="bi bi-search"></i> Track order
            </button>
          </div>
        </form>
      </div>
    </div>

    <?php foreach ($errors as $err): ?>
      <div class="alert alert-danger"><?= e($err) ?></div>
    <?php endforeach; ?>

    <?php if ($order): ?>
      <?php
        $isCancelled = $order['status'] === 'cancelled';
        // Map current status to step index (0..3) for the visual timeline.
        $stepIndex = array_search($order['status'], array_keys($timeline), true);
        if ($stepIndex === false) $stepIndex = -1;
      ?>

      <div class="card mb-4">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
              <div class="text-muted small">Order number</div>
              <div class="h4 mb-0"><?= e($order['order_number']) ?></div>
            </div>
            <div>
              <span class="badge <?= e($statusBadge[$order['status']] ?? 'bg-secondary') ?> fs-6 px-3 py-2">
                <?= e(ucfirst($order['status'])) ?>
              </span>
            </div>
          </div>

          <hr>

          <?php if ($isCancelled): ?>
            <div class="alert alert-secondary mb-0">
              <i class="bi bi-x-circle"></i>
              This order has been <strong>cancelled</strong>. If this is unexpected,
              please contact us with your order number.
            </div>
          <?php else: ?>
            <div class="status-timeline">
              <?php $i = 0; foreach ($timeline as $key => $step):
                $isDone    = $i <= $stepIndex;
                $isCurrent = $i === $stepIndex;
              ?>
                <div class="timeline-step <?= $isDone ? 'is-done' : '' ?> <?= $isCurrent ? 'is-current' : '' ?>">
                  <div class="timeline-bullet"><i class="bi <?= e($step['icon']) ?>"></i></div>
                  <div class="timeline-label"><?= e($step['label']) ?></div>
                </div>
                <?php if ($i < count($timeline) - 1): ?>
                  <div class="timeline-line <?= $i < $stepIndex ? 'is-done' : '' ?>"></div>
                <?php endif; ?>
              <?php $i++; endforeach; ?>
            </div>

            <div class="text-muted small mt-3">
              <?php
                $msg = [
                  'pending'    => 'We have received your order and will start processing it soon.',
                  'processing' => "We're preparing your order for shipment.",
                  'shipped'    => 'Your order is on its way!',
                  'completed'  => 'Your order has been delivered. Thank you for shopping with us!',
                ][$order['status']] ?? '';
              ?>
              <i class="bi bi-info-circle"></i> <?= e($msg) ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <div class="card h-100">
            <div class="card-body">
              <h6 class="text-muted text-uppercase small">Ship to</h6>
              <div class="fw-semibold"><?= e($order['customer_name']) ?></div>
              <div><?= nl2br(e($order['shipping_address'])) ?></div>
              <div>
                <?= e(trim(($order['shipping_city'] ?? '') . ' ' . ($order['shipping_zip'] ?? ''))) ?>
              </div>
              <div><?= e($order['shipping_country']) ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card h-100">
            <div class="card-body">
              <h6 class="text-muted text-uppercase small">Order details</h6>
              <div>
                <strong>Placed:</strong>
                <?= e(date('M j, Y g:i a', strtotime($order['created_at']))) ?>
              </div>
              <div>
                <strong>Last updated:</strong>
                <?= e(date('M j, Y g:i a', strtotime($order['updated_at']))) ?>
              </div>
              <div>
                <strong>Payment:</strong>
                <?php if ($payment): ?>
                  <span class="badge <?= e(mpesa_payment_status_class($payment['status'])) ?>">
                    <?= e(mpesa_payment_status_label($payment['status'])) ?>
                  </span>
                  <?php if ($latestRefund): ?>
                    <span class="badge <?= e(mpesa_refund_status_class($latestRefund['status'])) ?>">
                      <?= e(mpesa_refund_status_label($latestRefund['status'])) ?>
                    </span>
                  <?php endif; ?>
                <?php else: ?>
                  <?= e(payment_method_label($order['payment_method'])) ?>
                <?php endif; ?>
              </div>
              <div>
                <strong>Fulfillment:</strong>
                <?= e(delivery_method_label($deliverySnapshot['delivery_method'] ?? 'delivery')) ?>
              </div>
              <div>
                <strong>Total:</strong>
                <span class="fw-bold"><?= e(price((float)$order['total'])) ?></span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <?php if ($payment && in_array($payment['status'], ['initiated', 'pending'], true)): ?>
        <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2">
          <span><i class="bi bi-phone"></i> Your M-Pesa payment still needs confirmation.</span>
          <a href="<?= e(shop_url('mpesa_wait.php?o=' . urlencode($order['order_number']) . '&t=' . urlencode($accessToken))) ?>"
             class="btn btn-sm btn-warning">View payment status</a>
        </div>
      <?php endif; ?>

      <?php if ($latestRefund): ?>
        <div class="alert alert-info">
          <i class="bi bi-arrow-counterclockwise"></i>
          Refund status: <strong><?= e(mpesa_refund_status_label($latestRefund['status'])) ?></strong>.
        </div>
      <?php endif; ?>

      <?php if ($pickupSnapshot): ?>
        <div class="alert alert-info">
          <h6><i class="bi bi-shop"></i> Store Pickup Instructions</h6>
          <div><strong>Pickup address:</strong> <?= nl2br(e($pickupSnapshot['pickup_address'])) ?></div>
          <div class="mt-1"><?= nl2br(e($pickupSnapshot['pickup_instructions'])) ?></div>
        </div>
      <?php endif; ?>
      <?php if ($donationSnapshot && (float)$donationSnapshot['donation_amount'] > 0): ?>
        <div class="alert alert-success"><i class="bi bi-heart-fill"></i> Donation recorded: <?= e(price((float)$donationSnapshot['donation_amount'])) ?> to <?= e($donationSnapshot['charity_name'] ?: 'the selected charity') ?>.</div>
      <?php endif; ?>

      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
          <table class="table mb-0">
            <thead>
              <tr>
                <th>Product</th>
                <th class="text-center">Qty</th>
                <th class="text-end">Price</th>
                <th class="text-end">Subtotal</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $i): ?>
                <tr>
                  <td>
                    <?= e($i['product_name']) ?>
                    <?php if (!empty($i['product_sku'])): ?>
                      <div class="small text-muted">SKU: <?= e($i['product_sku']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="text-center"><?= (int)$i['quantity'] ?></td>
                  <td class="text-end"><?= e(price((float)$i['unit_price'])) ?></td>
                  <td class="text-end"><?= e(price((float)$i['line_total'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3" class="text-end">Subtotal</td>
                <td class="text-end"><?= e(price((float)$order['subtotal'])) ?></td>
              </tr>
              <tr>
                <td colspan="3" class="text-end">
                  <?= e($deliverySnapshot && $deliverySnapshot['delivery_method'] === 'pickup' ? 'Pickup fee' : 'Delivery fee') ?>
                </td>
                <td class="text-end">
                  <?= ((float)$order['shipping_fee'] > 0)
                        ? e(price((float)$order['shipping_fee']))
                        : '<span class="text-success">Free</span>' ?>
                </td>
              </tr>
              <?php if ($pricingSnapshot && $pricingSnapshot['distance_km'] !== null): ?>
                <tr>
                  <td colspan="3" class="text-end text-muted small">Distance · <?= e(number_format((float)$pricingSnapshot['actual_distance_km'], 2)) ?> km actual<?php if ($pricingSnapshot['billable_distance_km'] !== null): ?> · <?= (int)$pricingSnapshot['billable_distance_km'] ?> km billable<?php endif; ?> @ <?= e(price((float)$pricingSnapshot['rate_per_km'])) ?>/km</td>
                  <td></td>
                </tr>
              <?php endif; ?>
              <?php if ($donationSnapshot && (float)$donationSnapshot['donation_amount'] > 0): ?>
                <tr>
                  <td colspan="3" class="text-end">Charity donation<?= $donationSnapshot['charity_name'] ? ' · ' . e($donationSnapshot['charity_name']) : '' ?></td>
                  <td class="text-end"><?= e(price((float)$donationSnapshot['donation_amount'])) ?></td>
                </tr>
              <?php endif; ?>
              <tr class="fw-bold fs-5">
                <td colspan="3" class="text-end">Total</td>
                <td class="text-end"><?= e(price((float)$order['total'])) ?></td>
              </tr>
            </tfoot>
          </table>
          </div>
        </div>
      </div>

    <?php elseif ($searched && !$errors): ?>
      <div class="empty-state">
        <i class="bi bi-question-circle"></i>
        <h3>Order not found</h3>
      </div>
    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/includes/shop_footer.php'; ?>
