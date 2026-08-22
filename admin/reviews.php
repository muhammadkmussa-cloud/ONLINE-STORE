<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'editor');

$pageTitle = 'Product reviews';
$allowedStatuses = ['pending', 'approved', 'rejected'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    $reviewId = (int)($_POST['id'] ?? 0);

    if ($action === 'update_status') {
        $newStatus = (string)($_POST['status'] ?? '');
        if (!in_array($newStatus, $allowedStatuses, true)) {
            flash('danger', 'Invalid review status.');
        } else {
            $stmt = db()->prepare(
                "SELECT r.id, r.status, p.name AS product_name
                 FROM product_reviews r
                 INNER JOIN products p ON p.id = r.product_id
                 WHERE r.id = :id LIMIT 1"
            );
            $stmt->execute([':id' => $reviewId]);
            if ($review = $stmt->fetch()) {
                db()->prepare('UPDATE product_reviews SET status = :status WHERE id = :id')
                    ->execute([':status' => $newStatus, ':id' => $reviewId]);
                log_activity(
                    'review.status',
                    'Review for ' . $review['product_name'] . ': '
                    . $review['status'] . ' → ' . $newStatus
                );
                flash('success', 'Review status updated.');
            } else {
                flash('warning', 'Review not found.');
            }
        }
        admin_redirect('reviews.php');
    }

    if ($action === 'delete') {
        require_role('admin');
        $stmt = db()->prepare(
            "SELECT r.id, p.name AS product_name
             FROM product_reviews r
             INNER JOIN products p ON p.id = r.product_id
             WHERE r.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $reviewId]);
        if ($review = $stmt->fetch()) {
            db()->prepare('DELETE FROM product_reviews WHERE id = :id')
                ->execute([':id' => $reviewId]);
            log_activity('review.delete', 'Deleted review for ' . $review['product_name']);
            flash('success', 'Review deleted.');
        } else {
            flash('warning', 'Review not found.');
        }
        admin_redirect('reviews.php');
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$perPage = max(5, (int)setting('items_per_page', '10'));
$page = 1;   // finalized by paginate() once the total is known
$offset = 0;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(r.customer_name LIKE :q1 OR r.customer_email LIKE :q2
                 OR r.body LIKE :q3 OR p.name LIKE :q4)';
    $params[':q1'] = like_pattern($search);
    $params[':q2'] = like_pattern($search);
    $params[':q3'] = like_pattern($search);
    $params[':q4'] = like_pattern($search);
}
if (in_array($status, $allowedStatuses, true)) {
    $where[] = 'r.status = :status';
    $params[':status'] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rows = [];
$total = 0;
$pages = 1;
try {
    $stmt = db()->prepare(
        "SELECT COUNT(*)
         FROM product_reviews r
         INNER JOIN products p ON p.id = r.product_id
         $whereSql"
    );
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $pg = paginate($total, $perPage, (int)($_GET['page'] ?? 1));
    $page = $pg['page']; $pages = $pg['pages']; $offset = $pg['offset'];

    $stmt = db()->prepare(
        "SELECT r.*, p.name AS product_name, p.slug AS product_slug
         FROM product_reviews r
         INNER JOIN products p ON p.id = r.product_id
         $whereSql
         ORDER BY r.created_at DESC
         LIMIT :lim OFFSET :off"
    );
    foreach ($params as $key => $value) $stmt->bindValue($key, $value);
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $reviewsLoadError = false;
} catch (Throwable $e) {
    // Missing table = pre-migration install; anything else is a real fault.
    error_log('Reviews list query failed: ' . $e->getMessage());
    $reviewsLoadError = true;
}
if (!isset($reviewsLoadError)) $reviewsLoadError = false;

$stats = ['total' => 0, 'pending' => 0, 'approved' => 0];
try {
    $stats = db()->query(
        "SELECT COUNT(*) AS total,
                SUM(status = 'pending') AS pending,
                SUM(status = 'approved') AS approved
         FROM product_reviews"
    )->fetch() ?: $stats;
} catch (Throwable $e) {
    error_log('Review stats query failed: ' . $e->getMessage());
}

$statusBadge = [
    'pending'  => 'text-bg-warning',
    'approved' => 'text-bg-success',
    'rejected' => 'text-bg-secondary',
];

include __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-3">
  <div class="col-sm-4">
    <div class="card stat-card">
      <div class="stat-icon bg-indigo"><i class="bi bi-chat-square-text"></i></div>
      <div><p class="stat-label">All reviews</p><p class="stat-value"><?= number_format((int)$stats['total']) ?></p></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card stat-card">
      <div class="stat-icon bg-amber"><i class="bi bi-hourglass-split"></i></div>
      <div><p class="stat-label">Awaiting moderation</p><p class="stat-value"><?= number_format((int)$stats['pending']) ?></p></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card stat-card">
      <div class="stat-icon bg-emerald"><i class="bi bi-check2-circle"></i></div>
      <div><p class="stat-label">Published</p><p class="stat-value"><?= number_format((int)$stats['approved']) ?></p></div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-6">
        <label class="form-label small text-muted">Search</label>
        <input type="text" name="q" class="form-control"
               value="<?= e($search) ?>" placeholder="Product, reviewer, email or review text">
      </div>
      <div class="col-md-3">
        <label class="form-label small text-muted">Status</label>
        <select name="status" class="form-select">
          <option value="">All statuses</option>
          <?php foreach ($allowedStatuses as $reviewStatus): ?>
            <option value="<?= $reviewStatus ?>" <?= $status === $reviewStatus ? 'selected' : '' ?>>
              <?= ucfirst($reviewStatus) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3 d-grid">
        <button class="btn btn-primary"><i class="bi bi-funnel"></i> Filter reviews</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="card-title mb-0"><?= number_format($total) ?> review<?= $total === 1 ? '' : 's' ?></h5>
      <a href="<?= e(url('shop.php')) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-box-arrow-up-right"></i> View storefront
      </a>
    </div>

    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>Product</th>
            <th>Reviewer</th>
            <th>Rating &amp; review</th>
            <th>Status</th>
            <th>Submitted</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $review): ?>
            <tr>
              <td>
                <a href="<?= e(url('product.php?slug=' . urlencode($review['product_slug']))) ?>"
                   target="_blank" class="fw-semibold">
                  <?= e($review['product_name']) ?>
                </a>
              </td>
              <td>
                <div class="fw-semibold"><?= e($review['customer_name']) ?></div>
                <?php if (!empty($review['customer_email'])): ?>
                  <small class="text-muted"><?= e($review['customer_email']) ?></small>
                <?php endif; ?>
              </td>
              <td style="min-width:260px">
                <div class="text-warning" aria-label="<?= (int)$review['rating'] ?> out of 5 stars">
                  <?php for ($star = 1; $star <= 5; $star++): ?>
                    <i class="bi bi-star<?= $star <= (int)$review['rating'] ? '-fill' : '' ?>"></i>
                  <?php endfor; ?>
                </div>
                <?php if (!empty($review['title'])): ?>
                  <div class="fw-semibold mt-1"><?= e($review['title']) ?></div>
                <?php endif; ?>
                <div class="small text-muted">
                  <?= e(strlen($review['body']) > 120 ? substr($review['body'], 0, 120) . '…' : $review['body']) ?>
                </div>
              </td>
              <td>
                <span class="badge <?= e($statusBadge[$review['status']] ?? 'text-bg-secondary') ?>">
                  <?= e(ucfirst($review['status'])) ?>
                </span>
              </td>
              <td><small><?= e(date('M j, Y g:i a', strtotime($review['created_at']))) ?></small></td>
              <td class="text-end">
                <form method="post" class="d-inline-flex gap-1 align-items-center">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="id" value="<?= (int)$review['id'] ?>">
                  <select name="status" class="form-select form-select-sm" aria-label="Review status">
                    <?php foreach ($allowedStatuses as $reviewStatus): ?>
                      <option value="<?= $reviewStatus ?>" <?= $review['status'] === $reviewStatus ? 'selected' : '' ?>>
                        <?= ucfirst($reviewStatus) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-primary" title="Save status"><i class="bi bi-check2"></i></button>
                </form>
                <?php if (has_role('admin')): ?>
                  <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$review['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" title="Delete"
                            data-confirm="Delete this review permanently?">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">
<?php if ($reviewsLoadError): ?>
                Reviews could not be loaded — check the server error log.
              <?php else: ?>
                No reviews found. Run the migration if this is a new installation.
              <?php endif; ?>
            </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): $qs = $_GET; ?>
      <?php render_pagination($page, $pages); ?>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
