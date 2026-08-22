<?php
require_once __DIR__ . '/includes/auth.php';
require_driver_login();
require_once __DIR__ . '/../includes/delivery.php';
require_once __DIR__ . '/../includes/payment_methods.php';

$id = (int)($_GET['id'] ?? 0);
$driver = current_driver();

$stmt = db()->prepare(
    "SELECT o.id, o.order_number, o.customer_name, o.customer_phone,
            o.shipping_address, o.shipping_city, o.shipping_zip,
            o.shipping_country, o.notes, o.payment_method, o.status,
            da.id AS assignment_id,
            COALESCE((SELECT h.status FROM delivery_status_history h
                      WHERE h.assignment_id = da.id ORDER BY h.id DESC LIMIT 1),
                     CASE WHEN da.status = 'completed' THEN 'delivered' ELSE 'assigned' END) AS delivery_status
     FROM delivery_assignments da
     INNER JOIN orders o ON o.id = da.order_id
     INNER JOIN order_delivery_locations dl
       ON dl.order_id = o.id AND dl.delivery_method = 'delivery'
     WHERE o.id = :id AND da.driver_id = :driver_id AND da.status = 'assigned'
       AND o.status <> 'cancelled'
      LIMIT 1"
);
$stmt->execute([':id' => $id, ':driver_id' => (int)$driver['id']]);
$order = $stmt->fetch();
if (!$order) {
    http_response_code(404);
    $pageTitle = 'Delivery not found';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card"><div class="card-body text-center py-5">
      <i class="bi bi-shield-lock display-5 text-secondary"></i>
      <h1 class="h4 mt-3">Delivery not found</h1>
      <p class="text-muted">This order is not assigned to your driver account.</p>
      <a href="<?= e(driver_url()) ?>" class="btn btn-primary">Back to deliveries</a>
    </div></div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$payment = null;
try { $payment = mpesa_find_payment_by_order_number($order['order_number']); } catch (Throwable $e) { $payment = null; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    require_csrf();
    $newStatus = (string)($_POST['status'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    $currentDeliveryStatus = (string)$order['delivery_status'];
    $allowedDriverStatuses = ['picked_up', 'out_for_delivery', 'arrived', 'delivered', 'unable_to_deliver'];
    $allowedNext = delivery_status_transitions()[$currentDeliveryStatus] ?? [];
    if (!in_array($newStatus, $allowedDriverStatuses, true)) {
        flash('danger', 'Invalid delivery status.');
    } elseif (!in_array($newStatus, $allowedNext, true)) {
        flash('danger', 'Cannot move from ' . delivery_status_label($currentDeliveryStatus)
            . ' to ' . delivery_status_label($newStatus) . '. Please follow the workflow.');
    } elseif ($newStatus === 'unable_to_deliver' && $note === '') {
        flash('danger', 'Add a note explaining why the delivery could not be completed.');
    } else {
        $orderStatus = [
            'picked_up' => 'processing',
            'out_for_delivery' => 'shipped',
            'arrived' => 'shipped',
            'delivered' => 'completed',
            'unable_to_deliver' => 'processing',
        ][$newStatus];
        $pdo = db();
        try {
            $pdo->beginTransaction();
            record_delivery_status(
                $pdo,
                (int)$order['assignment_id'],
                $id,
                (int)$driver['id'],
                $newStatus,
                (int)$driver['id'],
                $note
            );
            $updateStmt = $pdo->prepare(
                "UPDATE orders SET status = :status
                 WHERE id = :id AND status <> 'cancelled'"
            );
            $updateStmt->execute([':status' => $orderStatus, ':id' => $id]);
            if ($updateStmt->rowCount() !== 1) {
                // The order changed state underneath us (e.g. cancelled by an
                // admin mid-delivery): refuse to overwrite it.
                throw new RuntimeException('Order state changed; update refused.');
            }
            if ($newStatus === 'delivered') {
                $pdo->prepare(
                    "UPDATE delivery_assignments SET status = 'completed', completed_at = UTC_TIMESTAMP()
                     WHERE id = :assignment_id AND driver_id = :driver_id AND status = 'assigned'"
                )->execute([':assignment_id' => (int)$order['assignment_id'], ':driver_id' => (int)$driver['id']]);
            }
            $pdo->commit();
            log_activity('driver.delivery.status', 'Driver updated order ' . $order['order_number'] . ' to ' . $newStatus);
            flash('success', 'Delivery status updated to ' . delivery_status_label($newStatus) . '.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('danger', 'Could not update delivery status.');
        }
    }
    header('Location: ' . driver_url($newStatus === 'delivered' ? '' : 'order.php?id=' . $id));
    exit;
}

$stmt = db()->prepare('SELECT product_name, product_sku, quantity FROM order_items WHERE order_id = :id');
$stmt->execute([':id' => $id]);
$items = $stmt->fetchAll();
$pricing = null;
try { $pricing = order_delivery_pricing_snapshot($id, false); } catch (Throwable $e) { $pricing = null; }
$location = null;
try { $location = order_delivery_snapshot($id, true); } catch (Throwable $e) { $location = null; }

$pageTitle = 'Delivery ' . $order['order_number'];
include __DIR__ . '/includes/header.php';
?>

<div class="mb-3"><a href="<?= e(driver_url()) ?>" class="text-decoration-none"><i class="bi bi-arrow-left"></i> Back to deliveries</a></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
  <div><div class="small text-muted">Assigned delivery</div><h1 class="h3 mb-1"><?= e($order['order_number']) ?></h1><span class="badge <?= e(delivery_status_class($order['delivery_status'])) ?>"><?= e(delivery_status_label($order['delivery_status'])) ?></span></div>
  <form method="post" class="d-flex gap-2 align-items-start flex-wrap justify-content-end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_status">
    <select name="status" class="form-select">
      <?php foreach (($delivery_status_transitions()[(string)$order['delivery_status']] ?? []) as $deliveryStatus): ?>
        <option value="<?= $deliveryStatus ?>"><?= e(delivery_status_label($deliveryStatus)) ?></option>
      <?php endforeach; ?>
    </select>
    <input name="note" class="form-control" placeholder="Note (required if unable)" maxlength="2000">
    <button class="btn btn-primary">Update</button>
  </form>
</div>

<div class="alert alert-info"><i class="bi bi-info-circle"></i> Workflow: take the goods to the customer location, mark <strong>Arrived</strong>, then call the customer so they can come and collect the goods.</div>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted">Customer</h2>
      <div class="h5"><?= e($order['customer_name']) ?></div>
      <a href="tel:<?= e($order['customer_phone']) ?>"><i class="bi bi-telephone"></i> <?= e($order['customer_phone']) ?></a>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted">Delivery address</h2>
      <div><?= nl2br(e($order['shipping_address'])) ?></div>
      <div><?= e(trim(($order['shipping_city'] ?? '') . ' ' . ($order['shipping_zip'] ?? ''))) ?></div>
      <div><?= e($order['shipping_country'] ?? '') ?></div>
      <?php if ($location && delivery_location_is_valid($location['latitude'] ?? null, $location['longitude'] ?? null)): ?>
        <a class="btn btn-sm btn-outline-primary mt-3" target="_blank" rel="noopener"
           href="<?= e(delivery_map_url($location['latitude'], $location['longitude'])) ?>">
          <i class="bi bi-map"></i> Open shared location
        </a>
      <?php endif; ?>
      <?php if (!empty($order['notes'])): ?>
        <div class="alert alert-warning small mt-3 mb-0"><strong>Delivery note:</strong> <?= nl2br(e($order['notes'])) ?></div>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted">Payment status</h2>
      <?php if ($payment): ?>
        <span class="badge <?= e(mpesa_payment_status_class($payment['status'])) ?>">
          <?= e(mpesa_payment_status_label($payment['status'])) ?>
        </span>
      <?php else: ?>
        <div class="fw-semibold"><?= e(payment_method_label($order['payment_method'])) ?></div>
        <div class="small text-muted">Payment status is managed by the store.</div>
      <?php endif; ?>
      <?php if ($pricing): ?>
        <div class="small mt-3"><strong>Delivery fee:</strong> <?= e(price((float)$pricing['delivery_fee'])) ?></div>
        <?php if ($pricing['actual_distance_km'] !== null): ?><div class="small text-muted"><strong>Distance:</strong> <?= e(number_format((float)$pricing['actual_distance_km'], 2)) ?> km actual<?php if ($pricing['billable_distance_km'] !== null): ?> · <?= (int)$pricing['billable_distance_km'] ?> km billable<?php endif; ?></div><?php endif; ?>
      <?php endif; ?>
      <div class="small text-muted mt-3">Do not collect or change payment details from this portal.</div>
    </div></div>
  </div>
</div>

<div class="card"><div class="card-body">
  <h2 class="h5">Package contents</h2>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Product</th><th>SKU</th><th class="text-center">Quantity</th></tr></thead>
    <tbody>
      <?php foreach ($items as $item): ?>
        <tr><td><?= e($item['product_name']) ?></td><td><code><?= e($item['product_sku'] ?: '—') ?></code></td><td class="text-center"><?= (int)$item['quantity'] ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="3" class="text-muted">No package items found.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div></div>

<?php include __DIR__ . '/includes/footer.php'; ?>
