<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/product_images.php';
require_role('admin', 'editor');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$pageTitle = $id ? 'Edit product' : 'Add product';
$errors = [];

$product = [
    'id' => 0, 'category_id' => null, 'name' => '', 'slug' => '', 'sku' => '',
    'short_description' => '', 'description' => '', 'price' => '0.00', 'sale_price' => '',
    'stock' => '0', 'image' => null, 'status' => 'active', 'featured' => 0,
];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM products WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if (!($product = $stmt->fetch())) {
        flash('warning', 'Product not found.');
        admin_redirect('products.php');
    }
    // One-time normalization of legacy rows on the edit view; failures are
    // logged rather than swallowed, and never block the page.
    try { product_image_sync_legacy($id, $product['image'] ?? null); }
    catch (Throwable $e) { error_log('Legacy image sync failed for product ' . $id . ': ' . $e->getMessage()); }
}

$categories = db()->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? 'save_product');

    if (in_array($action, ['image_primary', 'image_delete', 'image_move'], true)) {
        $imageId = (int)($_POST['image_id'] ?? 0);
        try {
            if ($action === 'image_primary') {
                if (!product_image_set_primary($id, $imageId)) throw new RuntimeException('Image not found.');
                log_activity('product.image.primary', 'Changed primary image for product #' . $id);
                flash('success', 'Primary image updated.');
            } elseif ($action === 'image_delete') {
                if (!product_image_delete($id, $imageId)) throw new RuntimeException('Image not found.');
                log_activity('product.image.delete', 'Deleted an image from product #' . $id);
                flash('success', 'Image deleted.');
            } else {
                $direction = (int)($_POST['direction'] ?? 0);
                if (!product_image_move($id, $imageId, $direction)) throw new RuntimeException('Image could not be reordered.');
                log_activity('product.image.reorder', 'Reordered images for product #' . $id);
                flash('success', 'Image order updated.');
            }
        } catch (Throwable $e) {
            flash('danger', APP_ENV === 'development' ? $e->getMessage() : 'Could not update product images.');
        }
        admin_redirect('product_form.php?id=' . $id . '#gallery');
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $sku = trim((string)($_POST['sku'] ?? ''));
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $priceValue = (string)($_POST['price'] ?? '0');
    $salePrice = trim((string)($_POST['sale_price'] ?? ''));
    $stock = (int)($_POST['stock'] ?? 0);
    $shortDescription = trim((string)($_POST['short_description'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $status = (string)($_POST['status'] ?? 'active');
    $featured = isset($_POST['featured']) ? 1 : 0;

    if ($name === '') $errors[] = 'Name is required.';
    if (!is_numeric($priceValue) || (float)$priceValue < 0) $errors[] = 'Price must be a non-negative number.';
    if ($salePrice !== '' && (!is_numeric($salePrice) || (float)$salePrice < 0)) $errors[] = 'Sale price must be a non-negative number (or blank).';
    if ($salePrice !== '' && is_numeric($salePrice) && is_numeric($priceValue) && (float)$salePrice >= (float)$priceValue) $errors[] = 'Sale price must be less than the regular price.';
    if ($categoryId > 0) {
        // A stale/removed category should say so, not surface as a cryptic
        // foreign-key failure on save.
        $catStmt = db()->prepare('SELECT id FROM categories WHERE id = :id LIMIT 1');
        $catStmt->execute([':id' => $categoryId]);
        if (!$catStmt->fetch()) {
            $errors[] = 'The selected category no longer exists. Please pick another.';
            $categoryId = 0;
        }
    }
    if (!in_array($status, ['active', 'inactive', 'draft'], true)) $status = 'active';
    if ($sku !== '') {
        $stmt = db()->prepare('SELECT id FROM products WHERE sku = :sku AND id <> :id LIMIT 1');
        $stmt->execute([':sku' => $sku, ':id' => $id]);
        if ($stmt->fetch()) $errors[] = 'That SKU is already used by another product.';
    }

    $uploadInput = $_FILES['images'] ?? ($_FILES['image'] ?? []);
    $uploadResult = product_image_upload_files(is_array($uploadInput) ? $uploadInput : []);
    $uploadedFiles = $uploadResult['files'];
    $errors = array_merge($errors, $uploadResult['errors']);
    if ($errors) {
        foreach ($uploadedFiles as $file) @unlink(UPLOADS_PATH . '/products/' . $file['filename']);
    }

    if (!$errors) {
        $finalSlug = unique_slug($slug !== '' ? slugify($slug) : slugify($name), 'products', $id);
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $data = [
                ':cid' => $categoryId > 0 ? $categoryId : null,
                ':name' => $name,
                ':slug' => $finalSlug,
                ':sku' => $sku !== '' ? $sku : null,
                ':sd' => $shortDescription !== '' ? $shortDescription : null,
                ':d' => $description !== '' ? $description : null,
                ':price' => number_format((float)$priceValue, 2, '.', ''),
                ':sp' => $salePrice !== '' ? number_format((float)$salePrice, 2, '.', '') : null,
                ':stock' => max(0, $stock),
                ':st' => $status,
                ':feat' => $featured,
            ];
            if ($id) {
                $data[':id'] = $id;
                $pdo->prepare(
                    'UPDATE products SET category_id=:cid, name=:name, slug=:slug, sku=:sku,
                     short_description=:sd, description=:d, price=:price, sale_price=:sp,
                     stock=:stock, status=:st, featured=:feat WHERE id=:id'
                )->execute($data);
    // One-time normalization of legacy rows; failures are visible in logs
    // instead of silently vanishing, and never block the page.
    try { product_image_sync_legacy($id, $product['image'] ?? null); }
    catch (Throwable $e) { error_log('Legacy image sync failed for product ' . $id . ': ' . $e->getMessage()); }
            } else {
                $pdo->prepare(
                    'INSERT INTO products
                     (category_id,name,slug,sku,short_description,description,price,sale_price,stock,image,status,featured)
                     VALUES (:cid,:name,:slug,:sku,:sd,:d,:price,:sp,:stock,NULL,:st,:feat)'
                )->execute($data);
                $id = (int)$pdo->lastInsertId();
            }

            $hasGallery = product_image_rows($id);
            $sortOrder = product_image_next_sort_order($id);
            $hasPrimary = false;
            foreach ($hasGallery as $galleryImage) if ((int)$galleryImage['is_primary'] === 1) $hasPrimary = true;
            foreach ($uploadedFiles as $index => $file) {
                $isPrimary = !$hasPrimary && $index === 0 ? 1 : 0;
                $pdo->prepare(
                    'INSERT INTO product_images (product_id,filename,original_name,sort_order,is_primary)
                     VALUES (:product_id,:filename,:original_name,:sort_order,:is_primary)'
                )->execute([
                    ':product_id' => $id,
                    ':filename' => $file['filename'],
                    ':original_name' => $file['original_name'],
                    ':sort_order' => $sortOrder++,
                    ':is_primary' => $isPrimary,
                ]);
                if ($isPrimary) {
                    $pdo->prepare('UPDATE products SET image = :image WHERE id = :id')
                        ->execute([':image' => $file['filename'], ':id' => $id]);
                    $hasPrimary = true;
                }
            }
            $pdo->commit();
            // Rows are durable: exempt these files from the shutdown sweep.
            product_images_mark_committed($uploadedFiles);
            log_activity($product['id'] ? 'product.update' : 'product.create', ($product['id'] ? 'Updated product ' : 'Created product ') . $name);
            if ($uploadedFiles) log_activity('product.image.upload', 'Uploaded ' . count($uploadedFiles) . ' image(s) for ' . $name);
            flash('success', $product['id'] ? 'Product updated.' : 'Product created.');
            admin_redirect('products.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($uploadedFiles as $file) @unlink(UPLOADS_PATH . '/products/' . $file['filename']);
            $errors[] = APP_ENV === 'development' ? 'Could not save product: ' . $e->getMessage() : 'Could not save product.';
        }
    }

    $product = array_merge($product, [
        'name' => $name, 'slug' => $slug, 'sku' => $sku, 'category_id' => $categoryId ?: null,
        'short_description' => $shortDescription, 'description' => $description,
        'price' => $priceValue, 'sale_price' => $salePrice, 'stock' => $stock,
        'status' => $status, 'featured' => $featured,
    ]);
}

$gallery = $id ? product_image_rows($id) : [];
$primaryImage = product_primary_image($id, $product['image'] ?? null);

include __DIR__ . '/includes/header.php';
?>

<form method="post" enctype="multipart/form-data" autocomplete="off" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <?php foreach ($errors as $error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endforeach; ?>
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card mb-3"><div class="card-body">
        <h5 class="card-title mb-3">Product details</h5>
        <div class="row g-3">
          <div class="col-md-8"><label class="form-label">Name <span class="text-danger">*</span></label><input name="name" class="form-control" value="<?= e($product['name']) ?>" required></div>
          <div class="col-md-4"><label class="form-label">SKU</label><input name="sku" class="form-control" value="<?= e($product['sku'] ?? '') ?>"></div>
          <div class="col-md-8"><label class="form-label">Slug <small class="text-muted">(auto if blank)</small></label><input name="slug" class="form-control" value="<?= e($product['slug']) ?>"></div>
          <div class="col-md-4"><label class="form-label">Category</label><select name="category_id" class="form-select"><option value="0">— None —</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['id'] ?>" <?= (int)($product['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-12"><label class="form-label">Short description</label><textarea name="short_description" class="form-control" rows="2" maxlength="500"><?= e($product['short_description'] ?? '') ?></textarea></div>
          <div class="col-12"><label class="form-label">Full description</label><textarea name="description" class="form-control" rows="6"><?= e($product['description'] ?? '') ?></textarea></div>
        </div>
      </div></div>
      <div class="card"><div class="card-body">
        <h5 class="card-title mb-3">Pricing &amp; stock</h5>
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">Price (<?= e(setting('currency_symbol', '$')) ?>) *</label><input type="number" step="0.01" min="0" name="price" class="form-control" value="<?= e($product['price']) ?>" required></div>
          <div class="col-md-4"><label class="form-label">Sale price</label><input type="number" step="0.01" min="0" name="sale_price" class="form-control" value="<?= e($product['sale_price'] ?? '') ?>"></div>
          <div class="col-md-4"><label class="form-label">Stock quantity</label><input type="number" step="1" min="0" name="stock" class="form-control" value="<?= e((string)($product['stock'] ?? 0)) ?>"></div>
        </div>
      </div></div>
    </div>
    <div class="col-lg-4">
      <div class="card mb-3"><div class="card-body">
        <h6 class="card-title">Status</h6>
        <select name="status" class="form-select mb-3"><?php foreach (['active','draft','inactive'] as $statusOption): ?><option value="<?= $statusOption ?>" <?= ($product['status'] ?? 'active') === $statusOption ? 'selected' : '' ?>><?= ucfirst($statusOption) ?></option><?php endforeach; ?></select>
        <div class="form-check"><input type="checkbox" name="featured" id="featured" class="form-check-input" value="1" <?= ((int)($product['featured'] ?? 0) === 1) ? 'checked' : '' ?>><label for="featured" class="form-check-label"><i class="bi bi-star-fill text-warning"></i> Featured product</label></div>
      </div></div>
      <div class="card mb-3"><div class="card-body">
        <h6 class="card-title">Product images</h6>
        <div class="text-center mb-2"><img src="<?= e(product_image_url($primaryImage)) ?>" class="img-fluid rounded border" style="max-height:180px" alt="Primary image preview"></div>
        <input type="file" name="images[]" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
        <small class="text-muted">Select multiple JPG, PNG, GIF, or WEBP files. Max 4 MB each, max 8000×8000 px.</small>
      </div></div>
      <div class="card"><div class="card-body"><button class="btn btn-primary w-100 mb-2"><i class="bi bi-check2"></i> <?= $id ? 'Save changes' : 'Create product' ?></button><a href="<?= e(admin_url('products.php')) ?>" class="btn btn-light w-100 mb-2">Cancel</a><?php if ($id && !empty($product['slug'])): ?><a href="<?= e(url('product.php?slug=' . urlencode($product['slug']))) ?>" target="_blank" class="btn btn-outline-secondary w-100"><i class="bi bi-box-arrow-up-right"></i> View on store</a><?php endif; ?></div></div>
    </div>
  </div>
</form>

<?php if ($id): ?>
  <div class="card mt-3" id="gallery"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3"><h5 class="card-title mb-0">Gallery images</h5><span class="badge text-bg-light"><?= number_format(count($gallery)) ?> image<?= count($gallery) === 1 ? '' : 's' ?></span></div>
    <?php if (!$gallery): ?><p class="text-muted small mb-0">No gallery images yet. Upload images above.</p><?php else: ?>
      <div class="row g-3">
        <?php foreach ($gallery as $index => $image): ?>
          <div class="col-6 col-md-4 col-xl-3"><div class="border rounded p-2 h-100">
            <img src="<?= e(product_image_url($image['filename'])) ?>" class="img-fluid rounded gallery-admin-image" alt="<?= e($image['alt_text'] ?: $product['name']) ?>">
            <div class="small mt-2 text-truncate" title="<?= e($image['original_name']) ?>"><?= e($image['original_name']) ?></div>
            <?php if ((int)$image['is_primary'] === 1): ?><span class="badge text-bg-primary mt-1">Primary</span><?php endif; ?>
            <div class="d-flex flex-wrap gap-1 mt-2">
              <?php if ((int)$image['is_primary'] !== 1): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="image_primary"><input type="hidden" name="image_id" value="<?= (int)$image['id'] ?>"><button class="btn btn-sm btn-outline-primary">Make primary</button></form><?php endif; ?>
              <form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="image_move"><input type="hidden" name="image_id" value="<?= (int)$image['id'] ?>"><input type="hidden" name="direction" value="-1"><button class="btn btn-sm btn-light" <?= $index === 0 ? 'disabled' : '' ?> title="Move left"><i class="bi bi-chevron-left"></i></button></form>
              <form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="image_move"><input type="hidden" name="image_id" value="<?= (int)$image['id'] ?>"><input type="hidden" name="direction" value="1"><button class="btn btn-sm btn-light" <?= $index === count($gallery)-1 ? 'disabled' : '' ?> title="Move right"><i class="bi bi-chevron-right"></i></button></form>
              <form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="image_delete"><input type="hidden" name="image_id" value="<?= (int)$image['id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete this image?" title="Delete"><i class="bi bi-trash"></i></button></form>
            </div>
          </div></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div></div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
