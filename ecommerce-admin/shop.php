<?php
require_once __DIR__ . '/includes/shop_bootstrap.php';

$pageTitle = 'All Products';

$search    = trim((string)($_GET['q']        ?? ''));
$catSlug   = trim((string)($_GET['category'] ?? ''));
$featured  = trim((string)($_GET['featured'] ?? ''));
$sort      = trim((string)($_GET['sort']     ?? 'newest'));
$catalogError = false;
$perPage   = 12;
$page      = 1;    // finalized by paginate() once the total is known
$offset    = 0;

$where  = ["p.status = 'active'"];
$params = [];

if ($search !== '') {
    $where[] = '(p.name LIKE :q1 OR p.short_description LIKE :q2 OR p.description LIKE :q3)';
    $params[':q1'] = like_pattern($search);
    $params[':q2'] = like_pattern($search);
    $params[':q3'] = like_pattern($search);
}
if ($catSlug !== '') {
    $where[] = 'c.slug = :cs';
    $params[':cs'] = $catSlug;
}
if ($featured === '1') {
    $where[] = 'p.featured = 1';
}

switch ($sort) {
    case 'price_asc':
        $orderSql = 'COALESCE(p.sale_price, p.price) ASC';
        break;
    case 'price_desc':
        $orderSql = 'COALESCE(p.sale_price, p.price) DESC';
        break;
    case 'name_asc':
        $orderSql = 'p.name ASC';
        break;
    case 'name_desc':
        $orderSql = 'p.name DESC';
        break;
    case 'oldest':
        $orderSql = 'p.created_at ASC';
        break;
    default:
        $orderSql = 'p.created_at DESC';
        break;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

try {
    $stmt = db()->prepare(
        "SELECT COUNT(*)
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         $whereSql"
    );
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $pg = paginate($total, $perPage, (int)($_GET['page'] ?? 1));
    $page = $pg['page']; $pages = $pg['pages']; $offset = $pg['offset'];

    $stmt = db()->prepare(
        "SELECT p.*, c.name AS category_name, c.slug AS category_slug
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         $whereSql
         ORDER BY $orderSql
         LIMIT :lim OFFSET :off"
    );
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll();
    $catalogError = false;
} catch (Throwable $e) {
    // An outage must not masquerade as an empty catalog.
    error_log('Catalog query failed: ' . $e->getMessage());
    $products = [];
    $total = 0;
    $pages = 1;
    $catalogError = true;
}

$cats = shop_active_categories();

include __DIR__ . '/includes/shop_header.php';
?>

<section class="section">
  <div class="container">

    <div class="section-header text-center">
      <h2><?= $search !== '' ? 'Results for "' . e($search) . '"' : 'All Products' ?></h2>
      <p><?= number_format($total) ?> product<?= $total === 1 ? '' : 's' ?> available</p>
    </div>

    <!-- Filters -->
    <form class="filters-bar" method="get">
      <div class="row g-2 align-items-end">
        <div class="col-md-4">
          <label class="form-label">Search</label>
          <input type="text" name="q" class="form-control"
                 value="<?= e($search) ?>" placeholder="Search products…">
        </div>
        <div class="col-md-3">
          <label class="form-label">Category</label>
          <select name="category" class="form-select">
            <option value="">All categories</option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= e($c['slug']) ?>" <?= $catSlug === $c['slug'] ? 'selected' : '' ?>>
                <?= e($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Sort by</label>
          <select name="sort" class="form-select">
            <option value="newest"      <?= $sort === 'newest'     ? 'selected' : '' ?>>Newest first</option>
            <option value="oldest"      <?= $sort === 'oldest'     ? 'selected' : '' ?>>Oldest first</option>
            <option value="price_asc"   <?= $sort === 'price_asc'  ? 'selected' : '' ?>>Price: low to high</option>
            <option value="price_desc"  <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: high to low</option>
            <option value="name_asc"    <?= $sort === 'name_asc'   ? 'selected' : '' ?>>Name: A → Z</option>
            <option value="name_desc"   <?= $sort === 'name_desc'  ? 'selected' : '' ?>>Name: Z → A</option>
          </select>
        </div>
        <div class="col-md-2 d-grid">
          <label class="form-label invisible">Apply</label>
          <button class="btn btn-primary"><i class="bi bi-funnel"></i> Apply</button>
        </div>
      </div>
      <?php if ($featured === '1'): ?>
        <input type="hidden" name="featured" value="1">
        <div class="mt-2 small text-muted">
          <i class="bi bi-star-fill text-warning"></i>
          Showing featured products only ·
          <a href="<?= e(shop_url('shop.php')) ?>">show all</a>
        </div>
      <?php endif; ?>
    </form>

    <?php if ($products): ?>
      <div class="row g-3">
        <?php foreach ($products as $p): ?>
          <div class="col-6 col-md-4 col-lg-3">
            <?php include __DIR__ . '/_product_card.php'; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php render_pagination($page, $pages, 'center'); ?>

    <?php else: ?>
      <div class="empty-state">
        <?php if ($catalogError): ?>
          <i class="bi bi-wifi-off"></i>
          <h3>Catalog temporarily unavailable</h3>
          <p>We're having trouble loading products right now. Please try again in a few moments.</p>
        <?php else: ?>
          <i class="bi bi-search"></i>
          <h3>No products found</h3>
          <p>Try adjusting your filters or
            <a href="<?= e(shop_url('shop.php')) ?>">browse all products</a>.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/includes/shop_footer.php'; ?>
