<?php
/**
 * Sidebar navigation. Highlights the current page using $_SERVER['PHP_SELF'].
 */
$current = basename($_SERVER['PHP_SELF'] ?? '');
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <a href="<?= e(admin_url('dashboard.php')) ?>" class="d-flex align-items-center gap-2 text-decoration-none">
      <span class="brand-icon"><i class="bi bi-shield-lock-fill"></i></span>
      <span class="brand-text"><?= e(setting('site_name', APP_NAME)) ?></span>
    </a>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>

    <a href="<?= e(admin_url('dashboard.php')) ?>"
       class="nav-link <?= active_if($current === 'dashboard.php') ?>">
      <i class="bi bi-speedometer2"></i><span>Dashboard</span>
    </a>

    <a href="<?= e(url('')) ?>" target="_blank" class="nav-link">
      <i class="bi bi-shop"></i><span>View store</span>
      <i class="bi bi-box-arrow-up-right ms-auto small text-muted"></i>
    </a>

    <?php if (has_role('admin', 'editor')): ?>
      <div class="nav-section">E-commerce</div>

      <a href="<?= e(admin_url('orders.php')) ?>"
         class="nav-link <?= active_if($current === 'orders.php' || $current === 'order_view.php') ?>">
        <i class="bi bi-receipt"></i><span>Orders</span>
        <?php
          try {
            $pendingCount = (int) db()->query(
              "SELECT COUNT(*) FROM orders WHERE status = 'pending'"
            )->fetchColumn();
          } catch (Throwable $e) { $pendingCount = 0; }
        ?>
        <?php if ($pendingCount > 0): ?>
          <span class="badge text-bg-warning ms-auto"><?= $pendingCount ?></span>
        <?php endif; ?>
      </a>

      <a href="<?= e(admin_url('products.php')) ?>"
         class="nav-link <?= active_if($current === 'products.php' || $current === 'product_form.php') ?>">
        <i class="bi bi-box-seam"></i><span>Products</span>
      </a>

      <a href="<?= e(admin_url('categories.php')) ?>"
         class="nav-link <?= active_if($current === 'categories.php' || $current === 'category_form.php') ?>">
        <i class="bi bi-tags"></i><span>Categories</span>
      </a>

      <a href="<?= e(admin_url('reviews.php')) ?>"
         class="nav-link <?= active_if($current === 'reviews.php') ?>">
        <i class="bi bi-chat-square-text"></i><span>Reviews</span>
        <?php
          try {
            $pendingReviews = (int)db()->query(
              "SELECT COUNT(*) FROM product_reviews WHERE status = 'pending'"
            )->fetchColumn();
          } catch (Throwable $e) { $pendingReviews = 0; }
        ?>
        <?php if ($pendingReviews > 0): ?>
          <span class="badge text-bg-warning ms-auto"><?= $pendingReviews ?></span>
        <?php endif; ?>
      </a>

    <?php endif; ?>

    <a href="<?= e(admin_url('activity.php')) ?>"
       class="nav-link <?= active_if($current === 'activity.php') ?>">
      <i class="bi bi-clock-history"></i><span>Activity log</span>
    </a>

    <?php if (has_role('admin')): ?>
      <div class="nav-section">Operations</div>
      <a href="<?= e(admin_url('drivers.php')) ?>"
         class="nav-link <?= active_if($current === 'drivers.php') ?>">
        <i class="bi bi-truck"></i><span>Delivery drivers</span>
      </a>
    <?php endif; ?>

    <div class="nav-section">Account</div>

    <a href="<?= e(admin_url('profile.php')) ?>"
       class="nav-link <?= active_if($current === 'profile.php') ?>">
      <i class="bi bi-person-circle"></i><span>Profile</span>
    </a>

    <?php if (has_role('admin')): ?>
      <a href="<?= e(admin_url('settings.php')) ?>"
         class="nav-link <?= active_if($current === 'settings.php') ?>">
        <i class="bi bi-gear"></i><span>Settings</span>
      </a>
    <?php endif; ?>

    <form method="post" action="<?= e(admin_url('logout.php')) ?>" class="m-0">
      <?= csrf_field() ?>
      <button type="submit" class="nav-link text-danger border-0 bg-transparent w-100 text-start">
        <i class="bi bi-box-arrow-right"></i><span>Logout</span>
      </button>
    </form>
  </nav>
</aside>
