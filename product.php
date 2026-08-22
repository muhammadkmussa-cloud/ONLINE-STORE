<?php
require_once __DIR__ . '/includes/shop_bootstrap.php';
require_once __DIR__ . '/includes/product_images.php';

$slug = trim((string)($_GET['slug'] ?? ''));

if ($slug === '') {
    redirect('shop.php');
}

try {
    $stmt = db()->prepare(
        "SELECT p.*, c.name AS category_name, c.slug AS category_slug
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.slug = :slug AND p.status = 'active'
         LIMIT 1"
    );
    $stmt->execute([':slug' => $slug]);
    $product = $stmt->fetch();
} catch (Throwable $e) {
    $product = false;
}

if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product not found';
    include __DIR__ . '/includes/shop_header.php';
    ?>
    <section class="section">
      <div class="container">
        <div class="empty-state">
          <i class="bi bi-question-circle"></i>
          <h3>Product not found</h3>
          <p>The product you're looking for doesn't exist or is no longer available.</p>
          <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-primary">
            Browse all products
          </a>
        </div>
      </div>
    </section>
    <?php
    include __DIR__ . '/includes/shop_footer.php';
    exit;
}

$pageTitle = $product['name'];
$gallery = product_image_rows((int)$product['id']);
if (!$gallery && !empty($product['image'])) {
    $gallery = [[
        'id' => 0,
        'filename' => $product['image'],
        'original_name' => $product['image'],
        'alt_text' => $product['name'],
        'sort_order' => 0,
        'is_primary' => 1,
    ]];
}
$primaryImage = $gallery[0]['filename'] ?? ($product['image'] ?? null);

$reviewErrors = [];
$reviewOld = [
    'customer_name'  => '',
    'customer_email' => '',
    'rating'         => '5',
    'title'          => '',
    'body'           => '',
];

// ----- Public review submission -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_review') {
    require_csrf();
    foreach (array_keys($reviewOld) as $key) {
        $reviewOld[$key] = trim((string)($_POST[$key] ?? ''));
    }
    $rating = (int)$reviewOld['rating'];

    if ($reviewOld['customer_name'] === '') {
        $reviewErrors[] = 'Your name is required.';
    }
    if ($reviewOld['customer_email'] === '') {
        $reviewErrors[] = 'Your email is required so we can manage your review.';
    } elseif (!filter_var($reviewOld['customer_email'], FILTER_VALIDATE_EMAIL)) {
        $reviewErrors[] = 'Please enter a valid email address.';
    }
    if ($rating < 1 || $rating > 5) {
        $reviewErrors[] = 'Please choose a rating from 1 to 5 stars.';
    }
    if ($reviewOld['body'] === '' || strlen($reviewOld['body']) < 10) {
        $reviewErrors[] = 'Your review must be at least 10 characters.';
    }
    if (mb_strlen($reviewOld['customer_name']) > 100) {
        $reviewErrors[] = 'Your name must be 100 characters or fewer.';
    }
    if (mb_strlen($reviewOld['title']) > 200) {
        $reviewErrors[] = 'The title must be 200 characters or fewer.';
    }

    // Flood control: one submission per minute per session, and at most one
    // review per customer per product per day regardless of moderation state.
    $lastReviewAt = (int)($_SESSION['last_review_at'] ?? 0);
    if (!$reviewErrors && $lastReviewAt && time() - $lastReviewAt < 60) {
        $reviewErrors[] = 'Please wait a minute before submitting another review.';
    }
    if (!$reviewErrors && $reviewOld['customer_email'] !== '') {
        try {
            $dupStmt = db()->prepare(
                "SELECT COUNT(*) FROM product_reviews
                 WHERE product_id = :product_id AND customer_email = :email
                   AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
            );
            $dupStmt->execute([
                ':product_id' => (int)$product['id'],
                ':email'      => $reviewOld['customer_email'],
            ]);
            if ((int)$dupStmt->fetchColumn() > 0) {
                $reviewErrors[] = 'We already received a review from this email for this product recently.';
            }
        } catch (Throwable $e) {
            // Reviews table missing (pre-migration): skip the duplicate probe.
        }
    }

    if (!$reviewErrors) {
        try {
            $stmt = db()->prepare(
                "INSERT INTO product_reviews
                  (product_id, customer_name, customer_email, rating, title, body, status)
                 VALUES
                  (:product_id, :name, :email, :rating, :title, :body, 'pending')"
            );
            $stmt->execute([
                ':product_id' => (int)$product['id'],
                ':name'      => $reviewOld['customer_name'],
                ':email'     => $reviewOld['customer_email'] ?: null,
                ':rating'    => $rating,
                ':title'     => $reviewOld['title'] ?: null,
                ':body'      => $reviewOld['body'],
            ]);
            $_SESSION['last_review_at'] = time();
            flash('success', 'Thanks for your review! It will appear after moderation.');
            redirect('product.php?slug=' . urlencode($product['slug']) . '#reviews');
        } catch (Throwable $e) {
            $reviewErrors[] = 'Reviews are temporarily unavailable. Please try again later.';
        }
    }
}

remember_recent_product((int)$product['id']);

$onSale  = product_is_on_sale($product);
$discount = $onSale ? product_discount_percent($product) : 0;
$inStock = (int)$product['stock'] > 0;

// ----- Approved review summary -----
$reviews = [];
$reviewSummary = [
    'count' => 0,
    'average' => 0.0,
    'breakdown' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
];
try {
    $stmt = db()->prepare(
        "SELECT * FROM product_reviews
         WHERE product_id = :id AND status = 'approved'
         ORDER BY created_at DESC"
    );
    $stmt->execute([':id' => (int)$product['id']]);
    $reviews = $stmt->fetchAll();
    $totalRating = 0;
    foreach ($reviews as $review) {
        $reviewRating = (int)$review['rating'];
        if (isset($reviewSummary['breakdown'][$reviewRating])) {
            $reviewSummary['breakdown'][$reviewRating]++;
        }
        $totalRating += $reviewRating;
    }
    $reviewSummary['count'] = count($reviews);
    $reviewSummary['average'] = $reviews ? $totalRating / count($reviews) : 0.0;
} catch (Throwable $e) {
    // The review migration may not have been run yet.
}

// Related products from same category.
$related = [];
if (!empty($product['category_id'])) {
    try {
        $stmt = db()->prepare(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.category_id = :cid
               AND p.id <> :pid
               AND p.status = 'active'
             ORDER BY p.created_at DESC
             LIMIT 4"
        );
        $stmt->execute([':cid' => $product['category_id'], ':pid' => $product['id']]);
        $related = $stmt->fetchAll();
    } catch (Throwable $e) {}
}

$recentlyViewed = array_values(array_filter(
    recently_viewed_products(6),
    static function (array $item) use ($product): bool {
        return (int)$item['id'] !== (int)$product['id'];
    }
));

include __DIR__ . '/includes/shop_header.php';
?>

<section class="product-detail">
  <div class="container">

    <?php foreach (get_flashes() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
        <?= e($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endforeach; ?>

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= e(shop_url()) ?>">Home</a></li>
        <li class="breadcrumb-item"><a href="<?= e(shop_url('shop.php')) ?>">Shop</a></li>
        <?php if (!empty($product['category_slug'])): ?>
          <li class="breadcrumb-item">
            <a href="<?= e(shop_url('category.php?slug=' . urlencode($product['category_slug']))) ?>">
              <?= e($product['category_name']) ?>
            </a>
          </li>
        <?php endif; ?>
        <li class="breadcrumb-item active"><?= e($product['name']) ?></li>
      </ol>
    </nav>

    <div class="row g-4">
      <div class="col-md-6">
        <div class="product-gallery" data-gallery>
          <div class="product-image">
            <img src="<?= e(product_image_url($primaryImage)) ?>"
                 id="productMainImage" alt="<?= e($product['name']) ?>">
          </div>
          <?php if (count($gallery) > 1): ?>
            <div class="product-thumbnails" role="list" aria-label="Product images">
              <?php foreach ($gallery as $galleryIndex => $galleryImage): ?>
                <button type="button" class="product-thumbnail <?= $galleryIndex === 0 ? 'is-active' : '' ?>"
                        data-image-src="<?= e(product_image_url($galleryImage['filename'])) ?>"
                        data-image-alt="<?= e($galleryImage['alt_text'] ?: $product['name']) ?>"
                        aria-label="View image <?= $galleryIndex + 1 ?>">
                  <img src="<?= e(product_image_url($galleryImage['filename'])) ?>"
                       alt="" loading="lazy">
                </button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-md-6">
        <?php if (!empty($product['category_name'])): ?>
          <div class="product-cat text-uppercase small text-muted mb-2">
            <a href="<?= e(shop_url('category.php?slug=' . urlencode($product['category_slug']))) ?>"
               class="text-muted">
              <?= e($product['category_name']) ?>
            </a>
          </div>
        <?php endif; ?>

        <h1><?= e($product['name']) ?></h1>

        <div class="product-rating mb-3" aria-label="<?= number_format($reviewSummary['average'], 1) ?> out of 5 stars">
          <span class="stars text-warning">
            <?php for ($star = 1; $star <= 5; $star++): ?>
              <i class="bi bi-star<?= $star <= round($reviewSummary['average']) ? '-fill' : '' ?>"></i>
            <?php endfor; ?>
          </span>
          <a href="#reviews" class="ms-2">
            <?= $reviewSummary['count'] ?> review<?= $reviewSummary['count'] === 1 ? '' : 's' ?>
          </a>
        </div>

        <?php if (!empty($product['short_description'])): ?>
          <p class="lead text-muted"><?= e($product['short_description']) ?></p>
        <?php endif; ?>

        <div class="price-block my-3">
          <?php if ($onSale): ?>
            <span><?= e(price((float)$product['sale_price'])) ?></span>
            <span class="old"><?= e(price((float)$product['price'])) ?></span>
            <span class="badge bg-danger fs-6 align-self-center">-<?= $discount ?>%</span>
          <?php else: ?>
            <span><?= e(price((float)$product['price'])) ?></span>
          <?php endif; ?>
        </div>

        <div class="stock-row">
          <?php if ($inStock): ?>
            <span class="in-stock"><i class="bi bi-check-circle-fill"></i> In stock</span>
            — <?= (int)$product['stock'] ?> available
          <?php else: ?>
            <span class="out-of-stock"><i class="bi bi-x-circle-fill"></i> Out of stock</span>
          <?php endif; ?>
          <?php if (!empty($product['sku'])): ?>
            · <span class="text-muted">SKU: <code><?= e($product['sku']) ?></code></span>
          <?php endif; ?>
        </div>

        <form method="post" action="<?= e(shop_url('cart.php')) ?>" class="my-4">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">

          <div class="row g-2 align-items-stretch">
            <div class="col-12 col-md-3">
              <input type="number" name="qty" value="1" min="1"
                     max="<?= (int)$product['stock'] ?>"
                     class="form-control form-control-lg"
                     <?= $inStock ? '' : 'disabled' ?>>
            </div>
            <div class="col-12 col-md">
              <button class="btn btn-outline-primary btn-lg w-100"
                      name="return_to" value="cart.php"
                      <?= $inStock ? '' : 'disabled' ?>>
                <i class="bi bi-bag-plus"></i>
                <?= $inStock ? 'Add to cart' : 'Sold out' ?>
              </button>
            </div>
            <?php if ($inStock): ?>
              <div class="col-12 col-md">
                <button class="btn btn-primary btn-lg w-100"
                        name="return_to" value="checkout.php">
                  <i class="bi bi-lightning-charge-fill"></i> Buy now
                </button>
              </div>
            <?php endif; ?>
          </div>
        </form>

        <form method="post" action="<?= e(shop_url('wishlist.php')) ?>" class="mb-4">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
          <input type="hidden" name="return_to" value="product.php?slug=<?= e(urlencode($product['slug'])) ?>">
          <button class="btn <?= wishlist_has((int)$product['id']) ? 'btn-danger' : 'btn-outline-danger' ?>">
            <i class="bi bi-heart<?= wishlist_has((int)$product['id']) ? '-fill' : '' ?>"></i>
            <?= wishlist_has((int)$product['id']) ? 'Saved to wishlist' : 'Save to wishlist' ?>
          </button>
        </form>

        <?php if (!empty($product['description'])): ?>
          <hr>
          <h5>Description</h5>
          <div class="text-muted" style="white-space: pre-line"><?= e($product['description']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Reviews -->
    <hr class="my-5" id="reviews">
    <div class="section-header">
      <span class="section-eyebrow">Community feedback</span>
      <h2>Customer reviews</h2>
      <p>What other shoppers think about <?= e($product['name']) ?>.</p>
    </div>

    <div class="row g-4 mb-4">
      <div class="col-lg-4">
        <div class="review-summary card h-100">
          <div class="card-body text-center">
            <div class="review-average"><?= number_format($reviewSummary['average'], 1) ?></div>
            <div class="stars text-warning fs-5">
              <?php for ($star = 1; $star <= 5; $star++): ?>
                <i class="bi bi-star<?= $star <= round($reviewSummary['average']) ? '-fill' : '' ?>"></i>
              <?php endfor; ?>
            </div>
            <div class="text-muted small mt-1">
              Based on <?= $reviewSummary['count'] ?> review<?= $reviewSummary['count'] === 1 ? '' : 's' ?>
            </div>
            <div class="review-breakdown mt-4 text-start">
              <?php for ($star = 5; $star >= 1; $star--):
                  $bar = $reviewSummary['count'] > 0
                      ? ($reviewSummary['breakdown'][$star] / $reviewSummary['count']) * 100
                      : 0;
              ?>
                <div class="d-flex align-items-center gap-2 small mb-2">
                  <span><?= $star ?> <i class="bi bi-star-fill text-warning"></i></span>
                  <div class="progress flex-grow-1" style="height:7px">
                    <div class="progress-bar bg-warning" style="width:<?= number_format($bar, 2) ?>%"></div>
                  </div>
                  <span class="text-muted" style="width:20px"><?= (int)$reviewSummary['breakdown'][$star] ?></span>
                </div>
              <?php endfor; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="card review-form-card">
          <div class="card-body">
            <h5 class="card-title">Write a review</h5>
            <p class="text-muted small">Your review will be visible after a quick moderation check.</p>

            <?php if ($reviewErrors): ?>
              <div class="alert alert-danger">
                <ul class="mb-0">
                  <?php foreach ($reviewErrors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <form method="post" action="<?= e(shop_url('product.php?slug=' . urlencode($product['slug']) . '#reviews')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="submit_review">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Your name *</label>
                  <input type="text" name="customer_name" class="form-control"
                         value="<?= e($reviewOld['customer_name']) ?>" maxlength="100" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Email <span class="text-muted">(optional)</span></label>
                  <input type="email" name="customer_email" class="form-control"
                         value="<?= e($reviewOld['customer_email']) ?>" maxlength="150">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Rating *</label>
                  <select name="rating" class="form-select" required>
                    <?php for ($rating = 5; $rating >= 1; $rating--): ?>
                      <option value="<?= $rating ?>" <?= (int)$reviewOld['rating'] === $rating ? 'selected' : '' ?>>
                        <?= $rating ?> star<?= $rating === 1 ? '' : 's' ?>
                      </option>
                    <?php endfor; ?>
                  </select>
                </div>
                <div class="col-md-8">
                  <label class="form-label">Review title <span class="text-muted">(optional)</span></label>
                  <input type="text" name="title" class="form-control"
                         value="<?= e($reviewOld['title']) ?>" maxlength="200">
                </div>
                <div class="col-12">
                  <label class="form-label">Your review *</label>
                  <textarea name="body" class="form-control" rows="4" minlength="10" maxlength="5000"
                            required><?= e($reviewOld['body']) ?></textarea>
                </div>
                <div class="col-12">
                  <button class="btn btn-primary"><i class="bi bi-send"></i> Submit review</button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <?php if ($reviews): ?>
      <div class="row g-3">
        <?php foreach ($reviews as $review): ?>
          <div class="col-md-6">
            <article class="card review-card h-100">
              <div class="card-body">
                <div class="d-flex justify-content-between gap-3 mb-2">
                  <div>
                    <div class="stars text-warning">
                      <?php for ($star = 1; $star <= 5; $star++): ?>
                        <i class="bi bi-star<?= $star <= (int)$review['rating'] ? '-fill' : '' ?>"></i>
                      <?php endfor; ?>
                    </div>
                    <h5 class="mb-0 mt-2"><?= e($review['title'] ?: 'Customer review') ?></h5>
                  </div>
                  <small class="text-muted text-nowrap"><?= e(date('M j, Y', strtotime($review['created_at']))) ?></small>
                </div>
                <p class="mb-3" style="white-space:pre-line"><?= e($review['body']) ?></p>
                <div class="small text-muted"><i class="bi bi-person-circle"></i> <?= e($review['customer_name']) ?></div>
              </div>
            </article>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state py-4">
        <i class="bi bi-chat-heart"></i>
        <h3>Be the first to review this product</h3>
        <p>Your feedback helps other shoppers make confident choices.</p>
      </div>
    <?php endif; ?>

    <?php if ($related): ?>
      <hr class="my-5">
      <div class="section-header">
        <h2>You might also like</h2>
        <p>Other products in <?= e($product['category_name']) ?></p>
      </div>

      <div class="row g-3">
        <?php $wishlistReturnTo = 'product.php?slug=' . urlencode($product['slug']); ?>
        <?php foreach ($related as $p): ?>
          <div class="col-6 col-md-3">
            <?php include __DIR__ . '/_product_card.php'; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($recentlyViewed): ?>
      <hr class="my-5">
      <div class="section-header">
        <h2>Recently viewed</h2>
        <p>Pick up where you left off.</p>
      </div>
      <div class="row g-3">
        <?php $wishlistReturnTo = 'product.php?slug=' . urlencode($product['slug']); ?>
        <?php foreach ($recentlyViewed as $p): ?>
          <div class="col-6 col-md-3">
            <?php include __DIR__ . '/_product_card.php'; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</section>

<?php if (count($gallery) > 1): ?>
<script>
document.querySelectorAll('[data-gallery] .product-thumbnail').forEach(function (button) {
    button.addEventListener('click', function () {
        const main = document.getElementById('productMainImage');
        if (!main) return;
        main.src = button.dataset.imageSrc;
        main.alt = button.dataset.imageAlt || main.alt;
        document.querySelectorAll('[data-gallery] .product-thumbnail').forEach(function (item) {
            item.classList.toggle('is-active', item === button);
        });
    });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/shop_footer.php'; ?>
