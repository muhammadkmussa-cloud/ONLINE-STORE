<?php
require_once __DIR__ . '/includes/auth.php';
require_driver_login();
require_once __DIR__ . '/../includes/delivery.php';

$pageTitle = 'My deliveries';
$driver = current_driver();
$deliveries = [];

try {
    $stmt = db()->prepare(
        "SELECT o.id, o.order_number, o.customer_name, o.customer_phone,
                o.shipping_address, o.shipping_city, o.shipping_zip,
                o.shipping_country, o.status, o.created_at, da.id AS assignment_id,
                COALESCE((SELECT h.status FROM delivery_status_history h
                          WHERE h.assignment_id = da.id ORDER BY h.id DESC LIMIT 1),
                         CASE WHEN da.status = 'completed' THEN 'delivered' ELSE 'assigned' END) AS delivery_status,
                dl.latitude, dl.longitude, dl.accuracy_meters
         FROM delivery_assignments da
         INNER JOIN orders o ON o.id = da.order_id
         INNER JOIN order_delivery_locations dl
           ON dl.order_id = o.id AND dl.delivery_method = 'delivery'
         WHERE da.driver_id = :driver_id AND da.status = 'assigned'
           AND o.status <> 'cancelled'
         ORDER BY o.created_at ASC"
    );
    $stmt->execute([':driver_id' => (int)$driver['id']]);
    $deliveries = $stmt->fetchAll();
} catch (Throwable $e) {
    $deliveries = [];
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <div>
    <p class="text-uppercase text-primary small fw-bold mb-1">Today’s route</p>
    <h1 class="h3 mb-1">My assigned deliveries</h1>
    <p class="text-muted mb-0">Only orders assigned to your account are shown.</p>
  </div>
  <span class="badge text-bg-primary fs-6"><?= number_format(count($deliveries)) ?> assigned</span>
</div>

<?php if (!$deliveries): ?>
  <div class="card delivery-empty-card">
    <div class="card-body text-center py-5">
      <i class="bi bi-check2-circle display-4 text-success"></i>
      <h2 class="h5 mt-3">No active deliveries</h2>
      <p class="text-muted mb-0">New assignments will appear here when an administrator assigns them to you.</p>
    </div>
  </div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($deliveries as $delivery): ?>
      <div class="col-md-6 col-xl-4">
        <article class="card h-100 delivery-card">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
              <div>
                <div class="small text-muted">Order</div>
                <h2 class="h5 mb-0"><?= e($delivery['order_number']) ?></h2>
              </div>
              <span class="badge <?= e(delivery_status_class($delivery['delivery_status'])) ?>"><?= e(delivery_status_label($delivery['delivery_status'])) ?></span>
            </div>
            <div class="fw-semibold mb-1"><i class="bi bi-person"></i> <?= e($delivery['customer_name']) ?></div>
            <div class="small mb-2"><a href="tel:<?= e($delivery['customer_phone']) ?>"><i class="bi bi-telephone"></i> <?= e($delivery['customer_phone']) ?></a></div>
            <div class="small text-muted mb-3">
              <i class="bi bi-geo-alt"></i>
              <?= e(trim($delivery['shipping_address'] . ', ' . $delivery['shipping_city'] . ' ' . ($delivery['shipping_zip'] ?? ''))) ?>
            </div>
            <?php if (delivery_location_is_valid($delivery['latitude'], $delivery['longitude'])): ?>
              <a class="small text-decoration-none" target="_blank" rel="noopener"
                 href="<?= e(delivery_map_url($delivery['latitude'], $delivery['longitude'])) ?>">
                <i class="bi bi-map"></i> Open shared map location
              </a>
            <?php endif; ?>
            <a class="btn btn-primary w-100 mt-3" href="<?= e(driver_url('order.php?id=' . (int)$delivery['id'])) ?>">
              View delivery <i class="bi bi-arrow-right"></i>
            </a>
          </div>
        </article>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
