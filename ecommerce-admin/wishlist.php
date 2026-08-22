<?php
require_once __DIR__ . '/includes/shop_bootstrap.php';

$pageTitle = 'My Wishlist';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    $productId = (int)($_POST['product_id'] ?? 0);

    if ($action === 'toggle' && $productId > 0) {
        if (wishlist_has($productId)) {
            wishlist_remove($productId);
            flash('info', 'Product removed from your wishlist.');
        } else {
            $stmt = db()->prepare(
                "SELECT id, name FROM products WHERE id = :id AND status = 'active' LIMIT 1"
            );
            $stmt->execute([':id' => $productId]);
            if ($product = $stmt->fetch()) {
                wishlist_add($productId);
                flash('success', 'Added "' . $product['name'] . '" to your wishlist.');
            } else {
                flash('warning', 'That product is no longer available.');
            }
        }
    } elseif ($action === 'remove' && $productId > 0) {
        wishlist_remove($productId);
        flash('info', 'Product removed from your wishlist.');
    } elseif ($action === 'clear') {
        wishlist_clear();
        flash('info', 'Wishlist cleared.');
    }

    // Only allow redirects to pages within this storefront.
    $returnTo = trim((string)($_POST['return_to'] ?? 'wishlist.php'));
    $safeReturn = preg_match(
        '#^(?:index|shop|category|product|wishlist)\.php(?:\?[^#]*)?(?:#[A-Za-z0-9_-]+)?$#',
        $returnTo
    );
    redirect($safeReturn ? $returnTo : 'wishlist.php');
}

$products = wishlist_load();

include __DIR__ . '/includes/shop_header.php';
?>

<section class="section">
  <div class="container">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= e(shop_url()) ?>">Home</a></li>
        <li class="breadcrumb-item active">Wishlist</li>
      </ol>
    </nav>

    <div class="section-header d-flex justify-content-between align-items-end flex-wrap gap-3">
      <div>
        <span class="section-eyebrow">Saved for later</span>
        <h2>My wishlist</h2>
        <p><?= number_format(count($products)) ?> saved product<?= count($products) === 1 ? '' : 's' ?></p>
      </div>
      <?php if ($products): ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="clear">
          <button class="btn btn-outline-danger" onclick="return confirm('Clear your wishlist?');">
            <i class="bi bi-trash"></i> Clear wishlist
          </button>
        </form>
      <?php endif; ?>
    </div>

    <?php foreach (get_flashes() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
        <?= e($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endforeach; ?>

    <?php if ($products): ?>
      <div class="row g-3">
        <?php foreach ($products as $p): ?>
          <div class="col-6 col-md-4 col-lg-3">
            <?php $wishlistReturnTo = 'wishlist.php'; include __DIR__ . '/_product_card.php'; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state">
        <i class="bi bi-heart"></i>
        <h3>Your wishlist is empty</h3>
        <p>Save products you love and come back to them anytime.</p>
        <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-primary">
          <i class="bi bi-bag"></i> Explore the shop
        </a>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/includes/shop_footer.php'; ?>
