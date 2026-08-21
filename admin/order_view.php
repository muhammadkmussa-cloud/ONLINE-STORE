<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/payment_methods.php';
require_once __DIR__ . '/../includes/delivery.php';
require_once __DIR__ . '/../includes/donations.php';
require_role('admin', 'editor');

$id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$order = $stmt->fetch();

if (!$order) {
    flash('warning', 'Order not found.');
    admin_redirect('orders.php');
}

$payment = null;
try {
    $payment = mpesa_find_payment_by_order_number($order['order_number']);
} catch (Throwable $e) {
    $payment = null;
}
$deliverySnapshot = null;
try {
    $deliverySnapshot = order_delivery_snapshot($id, has_role('admin'));
} catch (Throwable $e) {
    $deliverySnapshot = null;
}
$pricingSnapshot = null;
try {
    $pricingSnapshot = order_delivery_pricing_snapshot($id, has_role('admin'));
} catch (Throwable $e) {
    $pricingSnapshot = null;
}
$pickupSnapshot = null;
$donationSnapshot = null;
try {
    $pickupSnapshot = order_pickup_snapshot($id);
} catch (Throwable $e) {
    $pickupSnapshot = null;
}
try {
    $donationSnapshot = order_donation_snapshot($id);
} catch (Throwable $e) {
    $donationSnapshot = null;
}
$assignment = null;
$activeDrivers = [];
try {
    $assignmentStmt = db()->prepare(
        "SELECT da.*, da.id AS assignment_id,
                (SELECT h.status FROM delivery_status_history h
                 WHERE h.assignment_id = da.id ORDER BY h.id DESC LIMIT 1) AS delivery_status,
                u.name AS driver_name, u.email AS driver_email, u.phone AS driver_phone
         FROM delivery_assignments da
         INNER JOIN users u ON u.id = da.driver_id
         WHERE da.order_id = :order_id AND da.status = 'assigned'
         ORDER BY da.id DESC LIMIT 1"
    );
    $assignmentStmt->execute([':order_id' => $id]);
    $assignment = $assignmentStmt->fetch() ?: null;
    if (has_role('admin')) {
        $activeDrivers = db()->query(
            "SELECT id, name, email, phone FROM users
             WHERE role = 'delivery_driver' AND status = 'active'
             ORDER BY name ASC"
        )->fetchAll();
    }
} catch (Throwable $e) {
    $assignment = null;
    $activeDrivers = [];
}

$pageTitle = 'Order ' . $order['order_number'];

$allowedStatuses = ['pending', 'processing', 'shipped', 'completed', 'cancelled'];
$refunds = [];
$refundableAmount = 0.0;
$refundFormToken = '';
$refundLoadError = '';

if ($payment) {
    try {
        $refunds = mpesa_refunds_for_payment((int)$payment['id']);
        $refundableAmount = mpesa_refundable_amount($payment);
        if (!isset($_SESSION['refund_tokens'])) $_SESSION['refund_tokens'] = [];
        if (empty($_SESSION['refund_tokens'][(int)$payment['id']])) {
            $_SESSION['refund_tokens'][(int)$payment['id']] = bin2hex(random_bytes(24));
        }
        $refundFormToken = $_SESSION['refund_tokens'][(int)$payment['id']];
    } catch (Throwable $e) {
        $refundLoadError = 'Refund history is unavailable until the latest migration is applied.';
    }
}
$reversalAvailable = $payment ? mpesa_reversal_is_available() : false;

// ----- Admin-only driver assignment -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_driver') {
    require_csrf();
    require_role('admin');
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $isPickup = ($deliverySnapshot['delivery_method'] ?? 'delivery') === 'pickup';
    if ($isPickup) {
        flash('danger', 'Store Pickup orders do not require driver assignment.');
    } else {
        $driver = null;
        if ($driverId > 0) {
            $driverStmt = db()->prepare(
                "SELECT id, name FROM users
                 WHERE id = :id AND role = 'delivery_driver' AND status = 'active' LIMIT 1"
            );
            $driverStmt->execute([':id' => $driverId]);
            $driver = $driverStmt->fetch();
        }
        if ($driverId > 0 && !$driver) {
            flash('danger', 'Choose an active delivery driver.');
        } elseif ($payment && $payment['status'] !== 'successful') {
            flash('danger', 'This M-Pesa order cannot be assigned until payment is verified.');
        } else {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                $pdo->prepare(
                    "UPDATE delivery_assignments SET status = 'unassigned', unassigned_at = UTC_TIMESTAMP()
                     WHERE order_id = :order_id AND status = 'assigned'"
                )->execute([':order_id' => $id]);
                if ($driver) {
                    $pdo->prepare(
                        "INSERT INTO delivery_assignments (order_id, driver_id, assigned_by, status)
                         VALUES (:order_id, :driver_id, :assigned_by, 'assigned')"
                    )->execute([
                        ':order_id' => $id,
                        ':driver_id' => (int)$driver['id'],
                        ':assigned_by' => (int)current_user()['id'],
                    ]);
                    $assignmentId = (int)$pdo->lastInsertId();
                    record_delivery_status(
                        $pdo,
                        $assignmentId,
                        $id,
                        (int)$driver['id'],
                        'assigned',
                        (int)current_user()['id'],
                        'Assigned by administrator.'
                    );
                }
                $pdo->commit();
                log_activity(
                    'driver.assign',
                    $driver
                        ? 'Assigned order ' . $order['order_number'] . ' to ' . $driver['name']
                        : 'Removed driver assignment from order ' . $order['order_number']
                );
                flash('success', $driver ? 'Delivery assigned.' : 'Driver assignment removed.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('danger', 'Could not update the driver assignment.');
            }
        }
    }
    admin_redirect('order_view.php?id=' . $id);
}

// ----- Status update -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    require_csrf();
    $newStatus = $_POST['status'] ?? '';
    if (!in_array($newStatus, $allowedStatuses, true)) {
        flash('danger', 'Invalid status.');
    } elseif ($payment
        && $payment['status'] !== 'successful'
        && in_array($newStatus, ['processing', 'shipped', 'completed'], true)) {
        flash('danger', 'This M-Pesa order cannot enter fulfillment until payment is verified.');
    } elseif ($newStatus === $order['status']) {
        flash('info', 'Status unchanged.');
    } else {
        db()->prepare('UPDATE orders SET status = :s WHERE id = :id')
            ->execute([':s' => $newStatus, ':id' => $id]);
        log_activity(
            'order.status',
            'Order ' . $order['order_number']
            . ' status: ' . $order['status'] . ' → ' . $newStatus
        );
        flash('success', 'Order status updated to ' . ucfirst($newStatus) . '.');
    }
    admin_redirect('order_view.php?id=' . $id);
}

// ----- Admin-only manual resolution for uncertain reversal callbacks -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resolve_refund') {
    require_csrf();
    require_role('admin');
    $refundId = (int)($_POST['refund_id'] ?? 0);
    $resolutionStatus = (string)($_POST['resolution_status'] ?? '');
    $resolutionReason = trim((string)($_POST['resolution_reason'] ?? ''));
    if (!in_array($resolutionStatus, ['refunded', 'failed'], true)) {
        flash('danger', 'Invalid refund resolution.');
    } elseif (strlen($resolutionReason) < 5) {
        flash('danger', 'A manual resolution reason of at least 5 characters is required.');
    } else {
        $stmt = db()->prepare(
            "SELECT * FROM mpesa_refunds
             WHERE id = :refund_id AND order_id = :order_id LIMIT 1"
        );
        $stmt->execute([':refund_id' => $refundId, ':order_id' => $id]);
        $refund = $stmt->fetch();
        if (!$refund || !in_array($refund['status'], ['unknown', 'requires_review'], true)) {
            flash('warning', 'That refund is not awaiting manual resolution.');
        } else {
            mpesa_mark_refund_status(
                $refundId,
                $resolutionStatus,
                'Manual admin resolution: ' . $resolutionReason
            );
            log_activity(
                'mpesa.refund.resolve',
                'Manually marked refund #' . $refundId . ' as ' . $resolutionStatus
            );
            flash('success', 'Refund marked as ' . mpesa_refund_status_label($resolutionStatus) . '.');
        }
    }
    admin_redirect('order_view.php?id=' . $id . '#refunds');
}

// ----- Admin-only M-Pesa refund / reversal request -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_refund') {
    require_csrf();
    require_role('admin');

    $refundErrors = [];
    $postedToken = trim((string)($_POST['refund_token'] ?? ''));
    $sessionToken = (string)($_SESSION['refund_tokens'][(int)($payment['id'] ?? 0)] ?? '');
    if (!$payment || !$refundFormToken || $postedToken === '' || $sessionToken === ''
        || !hash_equals($sessionToken, $postedToken)) {
        $refundErrors[] = 'This refund form has expired. Reload the order page and try again.';
    }
    if (!$payment || $payment['status'] !== 'successful'
        || empty($payment['provider_transaction_id'])) {
        $refundErrors[] = 'Only a verified successful M-Pesa payment can be refunded.';
    }
    if (!mpesa_reversal_is_available()) {
        $refundErrors[] = 'M-Pesa reversal credentials and callback URLs are not configured.';
    }

    $amountRaw = trim((string)($_POST['refund_amount'] ?? ''));
    $refundAmount = 0;
    if ($amountRaw === '' || !is_numeric($amountRaw)) {
        $refundErrors[] = 'Enter a valid refund amount.';
    } else {
        try {
            $refundAmount = mpesa_amount_from_order((float)$amountRaw);
        } catch (Throwable $e) {
            $refundErrors[] = $e->getMessage();
        }
    }

    $refundReason = trim((string)($_POST['refund_reason'] ?? ''));
    if ($refundReason === '' || strlen($refundReason) < 5) {
        $refundErrors[] = 'Provide a refund reason of at least 5 characters.';
    }

    if (!$refundErrors) {
        $idempotencyKey = mpesa_refund_idempotency_key((int)$payment['id'], $postedToken);
        try {
            $refund = mpesa_find_refund_by_idempotency($idempotencyKey);
            if (!$refund) {
                $refund = mpesa_create_refund_request(
                    (int)$payment['id'],
                    $id,
                    (int)current_user()['id'],
                    $refundAmount,
                    $refundReason,
                    $idempotencyKey
                );
            }

            // Claim the row before the external request. This closes the
            // concurrent double-click window around an asynchronous API.
            if ($refund['status'] === 'requested' && mpesa_claim_refund((int)$refund['id'])) {
                try {
                    $result = mpesa_initiate_reversal($payment, (int)$refund['amount'], $refund['reason']);
                    mpesa_mark_refund_processing((int)$refund['id'], $result['response']);
                    log_activity('mpesa.refund.request', 'Requested M-Pesa refund for order ' . $order['order_number']);
                    flash('success', 'Refund request submitted. Final status will arrive from M-Pesa asynchronously.');
                } catch (MpesaException $e) {
                    $failureStatus = $e->isTransient() ? 'unknown' : 'failed';
                    mpesa_mark_refund_status((int)$refund['id'], $failureStatus, $e->getMessage());
                    flash($failureStatus === 'unknown' ? 'warning' : 'danger',
                        $failureStatus === 'unknown'
                            ? 'M-Pesa did not confirm the reversal request. It is marked for review.'
                            : 'M-Pesa rejected the reversal request.');
                } catch (Throwable $e) {
                    mpesa_mark_refund_status(
                        (int)$refund['id'],
                        'unknown',
                        'The reversal request could not be confirmed; manual review is required.'
                    );
                    error_log('M-Pesa refund initiation failed: ' . $e->getMessage());
                    flash('warning', 'The reversal status is unknown and requires review.');
                }
            } else {
                flash('info', 'This refund request is already being processed or has completed.');
            }
            unset($_SESSION['refund_tokens'][(int)$payment['id']]);
        } catch (Throwable $e) {
            flash('danger', APP_ENV === 'development'
                ? 'Could not create refund request: ' . $e->getMessage()
                : 'Could not create the refund request.');
        }
    } else {
        foreach ($refundErrors as $refundError) flash('danger', $refundError);
    }

    admin_redirect('order_view.php?id=' . $id);
}

$stmt = db()->prepare('SELECT * FROM order_items WHERE order_id = :id');
$stmt->execute([':id' => $id]);
$items = $stmt->fetchAll();

$statusBadge = [
    'pending'    => 'text-bg-warning',
    'processing' => 'text-bg-info',
    'shipped'    => 'text-bg-primary',
    'completed'  => 'text-bg-success',
    'cancelled'  => 'text-bg-secondary',
];

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap align-items-center mb-3 gap-2">
  <div>
    <a href="<?= e(admin_url('orders.php')) ?>" class="text-decoration-none">
      <i class="bi bi-arrow-left"></i> Back to orders
    </a>
    <h3 class="mt-1 mb-0">
      Order <?= e($order['order_number']) ?>
      <span class="badge <?= e($statusBadge[$order['status']] ?? 'text-bg-secondary') ?> align-middle ms-2">
        <?= e(ucfirst($order['status'])) ?>
      </span>
    </h3>
    <div class="text-muted small">
      Placed <?= e(date('M j, Y \a\t g:i a', strtotime($order['created_at']))) ?>
    </div>
  </div>

  <form method="post" class="d-flex gap-2">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_status">
    <select name="status" class="form-select" style="width:auto">
      <?php foreach ($allowedStatuses as $s): ?>
        <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>>
          <?= ucfirst($s) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary"><i class="bi bi-check2"></i> Update status</button>
  </form>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="text-muted text-uppercase small">Customer</h6>
        <div class="fw-semibold"><?= e($order['customer_name']) ?></div>
        <div>
          <a href="mailto:<?= e($order['customer_email']) ?>"><?= e($order['customer_email']) ?></a>
        </div>
        <?php if (!empty($order['customer_phone'])): ?>
          <div>
            <a href="tel:<?= e($order['customer_phone']) ?>"><?= e($order['customer_phone']) ?></a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="text-muted text-uppercase small">Shipping</h6>
        <div><?= nl2br(e($order['shipping_address'])) ?></div>
        <div>
          <?= e(trim(($order['shipping_city'] ?? '') . ' ' . ($order['shipping_zip'] ?? ''))) ?>
        </div>
        <?php if (!empty($order['shipping_country'])): ?>
          <div><?= e($order['shipping_country']) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="text-muted text-uppercase small">Payment</h6>
        <?php if ($payment): ?>
          <div>
            <span class="badge <?= e(mpesa_payment_status_class($payment['status'])) ?>">
              <?= e(mpesa_payment_status_label($payment['status'])) ?>
            </span>
          </div>
          <?php if (!empty($payment['provider_transaction_id'])): ?>
            <div class="small mt-2">Receipt: <code><?= e($payment['provider_transaction_id']) ?></code></div>
          <?php endif; ?>
          <?php if (!empty($payment['checkout_request_id'])): ?>
            <div class="text-muted small">Checkout ID: <code><?= e($payment['checkout_request_id']) ?></code></div>
          <?php endif; ?>
          <?php if (!empty($payment['failure_reason'])): ?>
            <div class="text-danger small mt-2"><?= e($payment['failure_reason']) ?></div>
          <?php endif; ?>
        <?php else: ?>
          <div class="fw-semibold">
            <?= e(payment_method_label($order['payment_method'])) ?>
          </div>
        <?php endif; ?>
        <div class="text-muted small mt-2">
          Last updated <?= e(date('M j, Y g:i a', strtotime($order['updated_at']))) ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <h5 class="card-title mb-1"><i class="bi bi-truck"></i> Fulfillment</h5>
        <div class="fw-semibold">
          <?= e(delivery_method_label($deliverySnapshot['delivery_method'] ?? 'delivery')) ?>
        </div>
      </div>
      <?php if ($deliverySnapshot && $deliverySnapshot['delivery_method'] === 'pickup'): ?>
        <span class="badge text-bg-info"><i class="bi bi-shop"></i> Customer pickup</span>
      <?php elseif ($deliverySnapshot): ?>
        <span class="badge text-bg-primary"><i class="bi bi-truck"></i> Delivery</span>
      <?php endif; ?>
    </div>
    <?php if ($pickupSnapshot): ?>
      <hr>
      <div class="small">
        <div><strong>Pickup address:</strong> <?= nl2br(e($pickupSnapshot['pickup_address'])) ?></div>
        <div class="mt-1"><strong>Instructions:</strong> <?= nl2br(e($pickupSnapshot['pickup_instructions'])) ?></div>
        <div class="text-muted mt-1"><i class="bi bi-person-check"></i> No driver assignment required for Store Pickup.</div>
      </div>
    <?php elseif (has_role('admin')): ?>
      <hr>
      <form method="post" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_driver">
        <div class="col-md-8">
          <label class="form-label small text-muted">Assigned delivery driver</label>
          <select name="driver_id" class="form-select">
            <option value="0">Unassigned</option>
            <?php foreach ($activeDrivers as $driverOption): ?>
              <option value="<?= (int)$driverOption['id'] ?>" <?= $assignment && (int)$assignment['driver_id'] === (int)$driverOption['id'] ? 'selected' : '' ?>>
                <?= e($driverOption['name']) ?><?= $driverOption['phone'] ? ' · ' . e($driverOption['phone']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-person-check"></i> Save assignment</button></div>
        <?php if ($assignment): ?><div class="col-12 small text-muted">Currently assigned to <?= e($assignment['driver_name']) ?> · <span class="badge <?= e(delivery_status_class($assignment['delivery_status'] ?: 'assigned')) ?>"><?= e(delivery_status_label($assignment['delivery_status'] ?: 'assigned')) ?></span>. Drivers only see their own active assignments.</div><?php endif; ?>
      </form>
    <?php endif; ?>
    <?php if ($deliverySnapshot && has_role('admin')
              && delivery_location_is_valid($deliverySnapshot['latitude'] ?? null, $deliverySnapshot['longitude'] ?? null)): ?>
      <hr>
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span><i class="bi bi-pin-map-fill text-success"></i> Shared location:
          <code><?= e(number_format((float)$deliverySnapshot['latitude'], 6)) ?>,
                <?= e(number_format((float)$deliverySnapshot['longitude'], 6)) ?></code>
        </span>
        <?php if (!empty($deliverySnapshot['accuracy_meters'])): ?>
          <span class="text-muted small">Accuracy ±<?= e(number_format((float)$deliverySnapshot['accuracy_meters'], 0)) ?> m</span>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"
           href="<?= e(delivery_map_url($deliverySnapshot['latitude'], $deliverySnapshot['longitude'])) ?>">
          <i class="bi bi-map"></i> Open map
        </a>
      </div>
      <div class="small text-muted mt-2">Captured <?= e(format_date($deliverySnapshot['captured_at'])) ?>. Coordinates are visible to administrators only.</div>
    <?php elseif ($deliverySnapshot && $deliverySnapshot['delivery_method'] === 'delivery'): ?>
      <div class="small text-muted mt-2">No browser location was shared. Use the customer-entered address above.</div>
    <?php elseif (!$deliverySnapshot): ?>
      <div class="small text-muted mt-2">No delivery snapshot was recorded for this historical order.</div>
    <?php endif; ?>
    <?php if ($pricingSnapshot): ?>
      <hr>
      <div class="row g-3 small">
        <div class="col-sm-4"><span class="text-muted">Pricing mode</span><br><strong><?= e(ucfirst($pricingSnapshot['pricing_mode'])) ?></strong></div>
        <div class="col-sm-4"><span class="text-muted">Distance</span><br><strong><?= $pricingSnapshot['actual_distance_km'] !== null ? e(number_format((float)$pricingSnapshot['actual_distance_km'], 2) . ' km actual') : '—' ?><?php if ($pricingSnapshot['billable_distance_km'] !== null): ?> · <?= (int)$pricingSnapshot['billable_distance_km'] ?> km billable<?php endif; ?></strong></div>
        <div class="col-sm-4"><span class="text-muted">Rate / km</span><br><strong><?= $pricingSnapshot['rate_per_km'] !== null ? e(price((float)$pricingSnapshot['rate_per_km'])) : '—' ?></strong></div>
        <div class="col-sm-4"><span class="text-muted">Final delivery fee</span><br><strong><?= e(price((float)$pricingSnapshot['delivery_fee'])) ?></strong></div>
        <div class="col-sm-8"><span class="text-muted">Calculated</span><br><strong><?= e(format_date($pricingSnapshot['calculated_at'])) ?></strong></div>
      </div>
    <?php endif; ?>
    <?php if ($assignment): ?>
      <?php $assignmentHistory = delivery_status_history((int)$assignment['assignment_id']); ?>
      <hr>
      <h6 class="text-muted text-uppercase small">Assignment history</h6>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Status</th><th>By</th><th>Note</th><th>When</th></tr></thead>
        <tbody>
          <?php foreach ($assignmentHistory as $history): ?>
            <tr>
              <td><span class="badge <?= e(delivery_status_class($history['status'])) ?>"><?= e(delivery_status_label($history['status'])) ?></span></td>
              <td><?= e($history['changed_by_name'] ?: 'System') ?></td>
              <td><?= e($history['note'] ?: '—') ?></td>
              <td class="text-nowrap"><small><?= e(format_date($history['created_at'])) ?></small></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <h5 class="card-title">Items</h5>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th style="width:60px"></th>
            <th>Product</th>
            <th>SKU</th>
            <th class="text-end">Unit price</th>
            <th class="text-center">Qty</th>
            <th class="text-end">Subtotal</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $i): ?>
            <tr>
              <td>
                <img src="<?= e(product_image_url($i['product_image'])) ?>"
                     class="rounded" width="44" height="44" style="object-fit:cover" alt="">
              </td>
              <td>
                <?php if ($i['product_id']): ?>
                  <a href="<?= e(admin_url('product_form.php?id=' . (int)$i['product_id'])) ?>"
                     class="fw-semibold text-reset"><?= e($i['product_name']) ?></a>
                <?php else: ?>
                  <span class="fw-semibold"><?= e($i['product_name']) ?></span>
                  <span class="badge text-bg-secondary ms-1">deleted</span>
                <?php endif; ?>
              </td>
              <td><code><?= e($i['product_sku'] ?: '—') ?></code></td>
              <td class="text-end"><?= e(price((float)$i['unit_price'])) ?></td>
              <td class="text-center"><?= (int)$i['quantity'] ?></td>
              <td class="text-end fw-semibold"><?= e(price((float)$i['line_total'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="5" class="text-end">Subtotal</td>
            <td class="text-end"><?= e(price((float)$order['subtotal'])) ?></td>
          </tr>
          <tr>
            <td colspan="5" class="text-end">Shipping</td>
            <td class="text-end">
              <?= ((float)$order['shipping_fee'] > 0)
                    ? e(price((float)$order['shipping_fee']))
                    : '<span class="text-success">Free</span>' ?>
            </td>
          </tr>
          <?php if ($donationSnapshot && (float)$donationSnapshot['donation_amount'] > 0): ?>
            <tr>
              <td colspan="5" class="text-end">Charity donation<?= $donationSnapshot['charity_name'] ? ' · ' . e($donationSnapshot['charity_name']) : '' ?></td>
              <td class="text-end"><?= e(price((float)$donationSnapshot['donation_amount'])) ?></td>
            </tr>
          <?php endif; ?>
          <tr class="fs-5 fw-bold">
            <td colspan="5" class="text-end">Total</td>
            <td class="text-end"><?= e(price((float)$order['total'])) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<?php if ($payment): ?>
  <div class="card mb-3" id="refunds">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
          <h5 class="card-title mb-1"><i class="bi bi-arrow-counterclockwise"></i> Refunds &amp; reversals</h5>
          <p class="text-muted small mb-0">The original M-Pesa payment is preserved; every reversal request is recorded separately.</p>
        </div>
        <?php if ($payment['status'] === 'successful'): ?>
          <span class="badge text-bg-success">Paid <?= e(price((float)$payment['amount'])) ?></span>
        <?php endif; ?>
      </div>

      <?php if ($refundLoadError): ?>
        <div class="alert alert-warning small mb-0"><?= e($refundLoadError) ?></div>
      <?php else: ?>
        <div class="row g-3 mb-3">
          <div class="col-sm-4">
            <div class="text-muted small">Paid amount</div>
            <div class="h5 mb-0"><?= e(price((float)$payment['amount'])) ?></div>
          </div>
          <div class="col-sm-4">
            <div class="text-muted small">Remaining refundable</div>
            <div class="h5 mb-0 <?= $refundableAmount > 0 ? 'text-success' : 'text-muted' ?>">
              <?= e(price($refundableAmount)) ?>
            </div>
          </div>
          <div class="col-sm-4">
            <div class="text-muted small">Original receipt</div>
            <code><?= e($payment['provider_transaction_id'] ?: '—') ?></code>
          </div>
        </div>

        <?php if (has_role('admin') && $payment['status'] === 'successful' && $refundableAmount > 0): ?>
          <?php if ($reversalAvailable): ?>
            <div class="refund-form-wrap border rounded p-3 mb-4">
              <h6 class="mb-2">Request an M-Pesa reversal</h6>
              <p class="text-muted small">The request is asynchronous. It will remain processing until Safaricom sends a verified callback.</p>
              <form method="post" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="request_refund">
                <input type="hidden" name="refund_token" value="<?= e($refundFormToken) ?>">
                <div class="col-md-4">
                  <label class="form-label">Refund amount (KES)</label>
                  <input type="number" name="refund_amount" class="form-control"
                         min="1" max="<?= e((string)$refundableAmount) ?>" step="1"
                         value="<?= e((string)$refundableAmount) ?>" required>
                </div>
                <div class="col-md-8">
                  <label class="form-label">Reason</label>
                  <input type="text" name="refund_reason" class="form-control"
                         maxlength="2000" placeholder="e.g. Customer returned the order" required>
                </div>
                <div class="col-12">
                  <button class="btn btn-outline-danger"
                          data-confirm="Request this M-Pesa reversal? The action cannot be duplicated automatically.">
                    <i class="bi bi-arrow-counterclockwise"></i> Request refund
                  </button>
                </div>
              </form>
            </div>
          <?php else: ?>
            <div class="alert alert-warning small">
              Refunds are unavailable because the M-Pesa reversal credentials or callback URLs are not configured.
            </div>
          <?php endif; ?>
        <?php elseif ($payment['status'] !== 'successful'): ?>
          <div class="alert alert-secondary small">Refunds are available only after the original M-Pesa payment is verified.</div>
        <?php elseif ($refundableAmount <= 0): ?>
          <div class="alert alert-secondary small">This M-Pesa payment has no remaining refundable amount.</div>
        <?php endif; ?>

        <?php if ($refunds): ?>
          <h6 class="text-muted text-uppercase small">Refund history</h6>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Amount</th><th>Status</th><th>Reason</th><th>Requested by</th><th>Provider references</th><th>Updated</th><th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($refunds as $refund): ?>
                  <tr>
                    <td class="fw-semibold"><?= e(price((float)$refund['amount'])) ?></td>
                    <td><span class="badge <?= e(mpesa_refund_status_class($refund['status'])) ?>">
                      <?= e(mpesa_refund_status_label($refund['status'])) ?>
                    </span></td>
                    <td style="min-width:180px"><?= e($refund['reason']) ?></td>
                    <td><?= e($refund['admin_name'] ?: 'Deleted admin') ?></td>
                    <td class="small">
                      <?php if (!empty($refund['originator_conversation_id'])): ?>
                        <div>Originator: <code><?= e($refund['originator_conversation_id']) ?></code></div>
                      <?php endif; ?>
                      <?php if (!empty($refund['conversation_id'])): ?>
                        <div>Conversation: <code><?= e($refund['conversation_id']) ?></code></div>
                      <?php endif; ?>
                      <?php if (!empty($refund['provider_transaction_id'])): ?>
                        <div>Reversal tx: <code><?= e($refund['provider_transaction_id']) ?></code></div>
                      <?php endif; ?>
                      <?php if (!empty($refund['failure_reason'])): ?>
                        <div class="text-danger"><?= e($refund['failure_reason']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="text-nowrap"><small><?= e(date('M j, Y g:i a', strtotime($refund['updated_at']))) ?></small></td>
                    <td class="text-nowrap">
                      <?php if (has_role('admin') && in_array($refund['status'], ['unknown', 'requires_review'], true)): ?>
                        <details>
                          <summary class="btn btn-sm btn-outline-secondary">Resolve</summary>
                          <div class="border rounded p-2 mt-2 bg-body text-start" style="min-width:240px">
                            <form method="post" class="mb-2">
                              <?= csrf_field() ?>
                              <input type="hidden" name="action" value="resolve_refund">
                              <input type="hidden" name="refund_id" value="<?= (int)$refund['id'] ?>">
                              <input type="hidden" name="resolution_status" value="refunded">
                              <input type="text" name="resolution_reason" class="form-control form-control-sm mb-2"
                                     minlength="5" placeholder="Verification note" required>
                              <button class="btn btn-sm btn-success w-100"
                                      data-confirm="Only mark refunded after verifying the provider record.">
                                Mark refunded
                              </button>
                            </form>
                            <form method="post">
                              <?= csrf_field() ?>
                              <input type="hidden" name="action" value="resolve_refund">
                              <input type="hidden" name="refund_id" value="<?= (int)$refund['id'] ?>">
                              <input type="hidden" name="resolution_status" value="failed">
                              <input type="text" name="resolution_reason" class="form-control form-control-sm mb-2"
                                     minlength="5" placeholder="Verification note" required>
                              <button class="btn btn-sm btn-outline-danger w-100">Mark failed</button>
                            </form>
                          </div>
                        </details>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-muted small">No refund or reversal requests yet.</div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($order['notes'])): ?>
  <div class="card mb-3">
    <div class="card-body">
      <h6 class="text-muted text-uppercase small">Customer note</h6>
      <p class="mb-0"><?= nl2br(e($order['notes'])) ?></p>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
