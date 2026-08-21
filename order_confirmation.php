<?php
require_once __DIR__ . '/includes/shop_bootstrap.php';
require_once __DIR__ . '/includes/payment_methods.php';
require_once __DIR__ . '/includes/delivery.php';
require_once __DIR__ . '/includes/donations.php';
require_once __DIR__ . '/includes/order_access.php';

$pageTitle = 'Order confirmation';

$orderNumber = trim((string)($_GET['o'] ?? ''));
$publicToken = trim((string)($_GET['t'] ?? ''));

$order = null;
$items = [];
$payment = null;
$latestRefund = null;
$deliverySnapshot = null;
$pricingSnapshot = null;
$pickupSnapshot = null;
$donationSnapshot = null;

if ($orderNumber !== '') {
    $stmt = db()->prepare("SELECT * FROM orders WHERE order_number = :n LIMIT 1");
    $stmt->execute([':n' => $orderNumber]);
    $order = $stmt->fetch();
    if ($order) {
        try {
            if (!order_access_token_valid((int)$order['id'], $publicToken)) $order = null;
        } catch (Throwable $e) {
            $order = null;
        }
    }

    if ($order) {
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

include __DIR__ . '/includes/shop_header.php';
?>

<section class="section">
  <div class="container" style="max-width: 800px;">

    <?php if (!$order): ?>
      <div class="empty-state">
        <i class="bi bi-question-circle"></i>
        <h3>Order not found</h3>
        <p>We couldn't find an order with that reference number.</p>
        <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-primary">Back to shop</a>
      </div>
    <?php else: ?>

      <?php
        $isMpesaPending = $payment && in_array($payment['status'], ['initiated', 'pending'], true);
        $isMpesaPaid = $payment && $payment['status'] === 'successful';
      ?>
      <div class="text-center mb-4">
        <?php if ($isMpesaPending): ?>
          <div class="display-1 text-warning"><i class="bi bi-phone-vibrate"></i></div>
          <h2 class="mt-2">Order recorded, payment pending</h2>
          <p class="lead text-muted">Complete the M-Pesa prompt to finish your payment.</p>
        <?php elseif ($payment && !$isMpesaPaid): ?>
          <div class="display-1 text-secondary"><i class="bi bi-exclamation-circle-fill"></i></div>
          <h2 class="mt-2">Order recorded, payment incomplete</h2>
          <p class="lead text-muted">No successful M-Pesa payment was recorded.</p>
        <?php else: ?>
          <div class="display-1 text-success"><i class="bi bi-check-circle-fill"></i></div>
          <h2 class="mt-2">Thank you, <?= e($order['customer_name']) ?>!</h2>
          <p class="lead text-muted">Your order has been placed successfully.</p>
        <?php endif; ?>
        <div class="badge bg-primary fs-6 px-3 py-2">
          Order #<?= e($order['order_number']) ?>
        </div>
      </div>

      <div class="card mb-4">
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4">
              <h6 class="text-muted text-uppercase small">Contact</h6>
              <div><?= e($order['customer_name']) ?></div>
              <div><?= e($order['customer_email']) ?></div>
              <div><?= e($order['customer_phone']) ?></div>
            </div>
            <div class="col-md-4">
              <h6 class="text-muted text-uppercase small">Ship to</h6>
              <div><?= nl2br(e($order['shipping_address'])) ?></div>
              <div>
                <?= e(trim(($order['shipping_city'] ?? '') . ' ' . ($order['shipping_zip'] ?? ''))) ?>
              </div>
              <div><?= e($order['shipping_country']) ?></div>
            </div>
            <div class="col-md-4">
              <h6 class="text-muted text-uppercase small">Payment</h6>
              <?php if ($payment): ?>
                <span class="badge <?= e(mpesa_payment_status_class($payment['status'])) ?>">
                  <?= e(mpesa_payment_status_label($payment['status'])) ?>
                </span>
                <?php if ($latestRefund): ?>
                  <div class="small mt-2">
                    Refund: <span class="badge <?= e(mpesa_refund_status_class($latestRefund['status'])) ?>">
                      <?= e(mpesa_refund_status_label($latestRefund['status'])) ?>
                    </span>
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <div><?= e(payment_method_label($order['payment_method'])) ?></div>
                <div class="small text-muted">Payment to be completed according to the selected method.</div>
              <?php endif; ?>
              <div class="small mt-2"><strong>Fulfillment:</strong>
                <?= e(delivery_method_label($deliverySnapshot['delivery_method'] ?? 'delivery')) ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-4">
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
                  <td class="text-end"><?= e(price($i['unit_price'])) ?></td>
                  <td class="text-end"><?= e(price($i['line_total'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3" class="text-end">Subtotal</td>
                <td class="text-end"><?= e(price($order['subtotal'])) ?></td>
              </tr>
              <tr>
                <td colspan="3" class="text-end">
                  <?= e($deliverySnapshot && $deliverySnapshot['delivery_method'] === 'pickup' ? 'Pickup fee' : 'Delivery fee') ?>
                </td>
                <td class="text-end">
                  <?= ((float)$order['shipping_fee'] > 0)
                        ? e(price($order['shipping_fee']))
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
                <td class="text-end"><?= e(price($order['total'])) ?></td>
              </tr>
            </tfoot>
          </table>
          </div>
        </div>
      </div>

      <div class="alert <?= $isMpesaPending ? 'alert-warning' : ($payment && !$isMpesaPaid ? 'alert-danger' : 'alert-info') ?>">
        <i class="bi bi-info-circle"></i>
        We've recorded your order. You'll receive a confirmation at
        <strong><?= e($order['customer_email']) ?></strong> shortly.
        <?php if ($isMpesaPending): ?>
          Complete the M-Pesa prompt, then
          <a href="<?= e(shop_url('mpesa_wait.php?o=' . urlencode($order['order_number']) . '&t=' . urlencode($publicToken))) ?>">view payment status</a>.
        <?php elseif ($payment && !$isMpesaPaid): ?>
          The M-Pesa payment was not verified. No fulfillment processing will begin until payment is successful.
          <a href="<?= e(shop_url('mpesa_wait.php?o=' . urlencode($order['order_number']) . '&t=' . urlencode($publicToken))) ?>">View payment status</a>.
        <?php elseif ($order['payment_method'] === 'bank_transfer'): ?>
          Bank transfer instructions will be sent in a separate email.
        <?php endif; ?>
      </div>

      <?php if ($pickupSnapshot): ?>
        <div class="alert alert-info">
          <h6><i class="bi bi-shop"></i> Store Pickup Instructions</h6>
          <div><strong>Pickup address:</strong> <?= nl2br(e($pickupSnapshot['pickup_address'])) ?></div>
          <div class="mt-1"><?= nl2br(e($pickupSnapshot['pickup_instructions'])) ?></div>
        </div>
      <?php endif; ?>
      <?php if ($donationSnapshot && (float)$donationSnapshot['donation_amount'] > 0): ?>
        <div class="alert alert-success"><i class="bi bi-heart-fill"></i> Thank you for donating <?= e(price((float)$donationSnapshot['donation_amount'])) ?> to <?= e($donationSnapshot['charity_name'] ?: 'the selected charity') ?>.</div>
      <?php endif; ?>

      <?php if ($latestRefund): ?>
        <div class="alert alert-info">
          <i class="bi bi-arrow-counterclockwise"></i>
          Refund status: <strong><?= e(mpesa_refund_status_label($latestRefund['status'])) ?></strong>.
          The original payment record remains available for audit.
        </div>
      <?php endif; ?>

      <div class="text-center d-flex flex-wrap justify-content-center gap-2">
        <a href="<?= e(shop_url('track.php?order=' . urlencode($order['order_number'])
                                . '&email=' . urlencode($order['customer_email'])
                                . '&token=' . urlencode($publicToken))) ?>"
           class="btn btn-outline-primary">
          <i class="bi bi-geo-alt"></i> Track this order
        </a>
        <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-primary">
          <i class="bi bi-bag"></i> Continue shopping
        </a>
      </div>

    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/includes/shop_footer.php'; ?>
