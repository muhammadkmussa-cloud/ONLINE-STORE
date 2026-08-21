<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/payment_methods.php';
require_once __DIR__ . '/../includes/delivery.php';
require_role('admin', 'editor');

$pageTitle = 'Orders';

// ----- Status update / delete -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        require_role('admin');
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT order_number FROM orders WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($o = $stmt->fetch()) {
            $paymentCount = 0;
            try {
                $paymentStmt = db()->prepare('SELECT COUNT(*) FROM payments WHERE order_id = :id');
                $paymentStmt->execute([':id' => $id]);
                $paymentCount = (int)$paymentStmt->fetchColumn();
            } catch (Throwable $ignored) {}

            if ($paymentCount > 0) {
                flash('danger', 'This order cannot be deleted because its M-Pesa payment history must be preserved.');
            } else {
                db()->prepare('DELETE FROM orders WHERE id = :id')->execute([':id' => $id]);
                log_activity('order.delete', 'Deleted order ' . $o['order_number']);
                flash('success', 'Order ' . $o['order_number'] . ' deleted.');
            }
        } else {
            flash('warning', 'Order not found.');
        }
        admin_redirect('orders.php');
    }
}

// ----- Filters & pagination -----
$search  = trim((string)($_GET['q']      ?? ''));
$status  = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['from']  ?? ''));
$dateTo   = trim((string)($_GET['to']    ?? ''));
$fulfillment = trim((string)($_GET['fulfillment'] ?? ''));
$deliveryStatus = trim((string)($_GET['delivery_status'] ?? ''));
$perPage = max(5, (int) setting('items_per_page', '10'));
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$allowedStatuses = ['pending', 'processing', 'shipped', 'completed', 'cancelled'];

$where = []; $params = [];
if ($search !== '') {
    $where[] = '(order_number LIKE :q1 OR customer_name LIKE :q2 OR customer_email LIKE :q3)';
    $params[':q1'] = "%$search%";
    $params[':q2'] = "%$search%";
    $params[':q3'] = "%$search%";
}
if (in_array($status, $allowedStatuses, true)) {
    $where[] = 'status = :st';
    $params[':st'] = $status;
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'created_at >= :df';
    $params[':df'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'created_at <= :dt';
    $params[':dt'] = $dateTo . ' 23:59:59';
}
if (in_array($fulfillment, ['delivery', 'pickup'], true)) {
    $where[] = 'id IN (SELECT order_id FROM order_delivery_locations WHERE delivery_method = :fulfillment)';
    $params[':fulfillment'] = $fulfillment;
}
$allowedDeliveryStatuses = array_keys(delivery_status_definitions());
if (in_array($deliveryStatus, $allowedDeliveryStatuses, true)) {
    $where[] = "id IN (
        SELECT da_filter.order_id FROM delivery_assignments da_filter
        WHERE COALESCE(
            (SELECT h_filter.status FROM delivery_status_history h_filter
             WHERE h_filter.assignment_id = da_filter.id ORDER BY h_filter.id DESC LIMIT 1),
            CASE WHEN da_filter.status = 'completed' THEN 'delivered' ELSE 'assigned' END
        ) = :delivery_status
    )";
    $params[':delivery_status'] = $deliveryStatus;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Export the currently filtered order set as a spreadsheet-friendly CSV.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['export'] ?? '') === 'csv') {
    $stmt = db()->prepare(
        "SELECT o.order_number, o.customer_name, o.customer_email, o.customer_phone,
                o.subtotal, o.shipping_fee, o.total, o.payment_method, o.status, o.created_at,
                (SELECT p.status FROM payments p
                 WHERE p.order_id = o.id AND p.provider = 'mpesa'
                 ORDER BY p.id DESC LIMIT 1) AS payment_status,
                (SELECT p.provider_transaction_id FROM payments p
                 WHERE p.order_id = o.id AND p.provider = 'mpesa'
                 ORDER BY p.id DESC LIMIT 1) AS mpesa_receipt,
                (SELECT dl.delivery_method FROM order_delivery_locations dl
                 WHERE dl.order_id = o.id LIMIT 1) AS delivery_method,
                (SELECT COALESCE(
                    (SELECT h.status FROM delivery_status_history h
                     WHERE h.assignment_id = da.id ORDER BY h.id DESC LIMIT 1),
                    CASE WHEN da.status = 'completed' THEN 'delivered' ELSE 'assigned' END
                 )
                 FROM delivery_assignments da
                 WHERE da.order_id = o.id ORDER BY da.id DESC LIMIT 1) AS delivery_status
         FROM orders o
         $whereSql
         ORDER BY o.created_at DESC"
    );
    $stmt->execute($params);
    $exportRows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'Order number', 'Customer name', 'Customer email', 'Customer phone',
        'Subtotal', 'Shipping fee', 'Total', 'Payment method', 'Status', 'Placed at',
        'Payment status', 'M-Pesa receipt', 'Delivery method', 'Delivery status'
    ]);
    foreach ($exportRows as $exportRow) {
        fputcsv($output, [
            $exportRow['order_number'],
            $exportRow['customer_name'],
            $exportRow['customer_email'],
            $exportRow['customer_phone'],
            $exportRow['subtotal'],
            $exportRow['shipping_fee'],
            $exportRow['total'],
            $exportRow['payment_method'],
            $exportRow['status'],
            $exportRow['created_at'],
            $exportRow['payment_status'],
            $exportRow['mpesa_receipt'],
            delivery_method_label($exportRow['delivery_method'] ?: 'delivery'),
            $exportRow['delivery_status'] ? delivery_status_label($exportRow['delivery_status']) : 'Unassigned',
        ]);
    }
    fclose($output);
    exit;
}

$stmt = db()->prepare("SELECT COUNT(*) FROM orders $whereSql");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = db()->prepare(
    "SELECT o.*,
             (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count,
             (SELECT p.status FROM payments p
              WHERE p.order_id = o.id AND p.provider = 'mpesa'
              ORDER BY p.id DESC LIMIT 1) AS payment_status,
             (SELECT dl.delivery_method FROM order_delivery_locations dl
              WHERE dl.order_id = o.id LIMIT 1) AS delivery_method,
             (SELECT COALESCE(
                 (SELECT h.status FROM delivery_status_history h
                  WHERE h.assignment_id = da.id ORDER BY h.id DESC LIMIT 1),
                 CASE WHEN da.status = 'completed' THEN 'delivered' ELSE 'assigned' END
              )
              FROM delivery_assignments da
              WHERE da.order_id = o.id ORDER BY da.id DESC LIMIT 1) AS delivery_status
     FROM orders o
     $whereSql
     ORDER BY o.created_at DESC
     LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

// Headline metrics for the toolbar.
$stats = db()->query(
    "SELECT
       COUNT(*) AS total_orders,
       SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) AS pending_orders,
       SUM(CASE
             WHEN o.status IN ('cancelled') THEN 0
             WHEN o.payment_method = 'mpesa'
                  AND NOT EXISTS (
                      SELECT 1 FROM payments p
                      WHERE p.order_id = o.id
                        AND p.provider = 'mpesa'
                        AND p.status = 'successful'
                  ) THEN 0
             ELSE o.total
           END) AS revenue
     FROM orders o"
)->fetch();

$statusBadge = [
    'pending'    => 'text-bg-warning',
    'processing' => 'text-bg-info',
    'shipped'    => 'text-bg-primary',
    'completed'  => 'text-bg-success',
    'cancelled'  => 'text-bg-secondary',
];

include __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-3">
  <div class="col-sm-6 col-md-4">
    <div class="card stat-card">
      <div class="stat-icon bg-indigo"><i class="bi bi-receipt"></i></div>
      <div>
        <p class="stat-label">Total orders</p>
        <p class="stat-value"><?= number_format((int)$stats['total_orders']) ?></p>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-4">
    <div class="card stat-card">
      <div class="stat-icon bg-amber"><i class="bi bi-hourglass-split"></i></div>
      <div>
        <p class="stat-label">Pending</p>
        <p class="stat-value"><?= number_format((int)$stats['pending_orders']) ?></p>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-4">
    <div class="card stat-card">
      <div class="stat-icon bg-emerald"><i class="bi bi-cash-stack"></i></div>
      <div>
        <p class="stat-label">Revenue (excl. cancelled)</p>
        <p class="stat-value"><?= e(price((float)$stats['revenue'])) ?></p>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-3">
        <label class="form-label small text-muted">Search</label>
        <input type="text" name="q" class="form-control"
               value="<?= e($search) ?>" placeholder="Order #, name or email">
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted">Status</label>
        <select name="status" class="form-select">
          <option value="">All</option>
          <?php foreach ($allowedStatuses as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted">Fulfillment</label>
        <select name="fulfillment" class="form-select">
          <option value="">All</option>
          <option value="delivery" <?= $fulfillment === 'delivery' ? 'selected' : '' ?>>Delivery</option>
          <option value="pickup" <?= $fulfillment === 'pickup' ? 'selected' : '' ?>>Store Pickup</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted">Delivery status</label>
        <select name="delivery_status" class="form-select">
          <option value="">All</option>
          <?php foreach ($allowedDeliveryStatuses as $statusOption): ?>
            <option value="<?= e($statusOption) ?>" <?= $deliveryStatus === $statusOption ? 'selected' : '' ?>><?= e(delivery_status_label($statusOption)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted">From</label>
        <input type="date" name="from" class="form-control" value="<?= e($dateFrom) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted">To</label>
        <input type="date" name="to" class="form-control" value="<?= e($dateTo) ?>">
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-primary flex-grow-1"><i class="bi bi-funnel"></i> Filter</button>
        <a class="btn btn-outline-secondary" href="<?= e(admin_url('orders.php')) ?>">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <?php $exportParams = $_GET; $exportParams['export'] = 'csv'; unset($exportParams['page']); ?>
    <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
      <h5 class="card-title mb-0"><?= number_format($total) ?> order<?= $total === 1 ? '' : 's' ?></h5>
      <a class="btn btn-outline-success btn-sm"
         href="<?= e(admin_url('orders.php?' . http_build_query($exportParams))) ?>">
        <i class="bi bi-download"></i> Export CSV
      </a>
    </div>

    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>Order #</th>
            <th>Customer</th>
            <th>Items</th>
            <th>Total</th>
            <th>Payment</th>
            <th>Fulfillment</th>
            <th>Status</th>
            <th>Placed</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $o): ?>
            <tr>
              <td>
                <a href="<?= e(admin_url('order_view.php?id=' . (int)$o['id'])) ?>"
                   class="fw-semibold"><?= e($o['order_number']) ?></a>
              </td>
              <td>
                <div><?= e($o['customer_name']) ?></div>
                <small class="text-muted"><?= e($o['customer_email']) ?></small>
              </td>
              <td><?= (int)$o['item_count'] ?></td>
              <td class="fw-semibold"><?= e(price((float)$o['total'])) ?></td>
              <td>
                <span class="badge text-bg-light">
                  <?= e(payment_method_label($o['payment_method'])) ?>
                </span>
                <?php if ($o['payment_method'] === 'mpesa' && !empty($o['payment_status'])): ?>
                  <div class="mt-1">
                    <span class="badge <?= e(mpesa_payment_status_class($o['payment_status'])) ?>">
                      <?= e(mpesa_payment_status_label($o['payment_status'])) ?>
                    </span>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge text-bg-light">
                  <i class="bi <?= ($o['delivery_method'] ?? 'delivery') === 'pickup' ? 'bi-shop' : 'bi-truck' ?>"></i>
                  <?= e(delivery_method_label($o['delivery_method'] ?: 'delivery')) ?>
                </span>
                <?php if (!empty($o['delivery_status'])): ?>
                  <div class="mt-1"><span class="badge <?= e(delivery_status_class($o['delivery_status'])) ?>"><?= e(delivery_status_label($o['delivery_status'])) ?></span></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= e($statusBadge[$o['status']] ?? 'text-bg-secondary') ?>">
                  <?= e(ucfirst($o['status'])) ?>
                </span>
              </td>
              <td>
                <small><?= e(date('M j, Y g:i a', strtotime($o['created_at']))) ?></small>
              </td>
              <td class="text-end">
                <a class="btn btn-sm btn-light"
                   href="<?= e(admin_url('order_view.php?id=' . (int)$o['id'])) ?>"
                   title="View">
                  <i class="bi bi-eye"></i>
                </a>
                <?php if (has_role('admin')): ?>
                  <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"
                            data-confirm="Delete this order? This cannot be undone."
                            title="Delete">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No orders found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): $qs = $_GET; ?>
      <nav class="mt-3"><ul class="pagination justify-content-end mb-0">
        <?php for ($pn = 1; $pn <= $pages; $pn++): $qs['page'] = $pn; ?>
          <li class="page-item <?= $pn === $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= e(http_build_query($qs)) ?>"><?= $pn ?></a>
          </li>
        <?php endfor; ?>
      </ul></nav>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
