<?php
/**
 * Storefront bootstrap.
 * Reuses the admin panel's config/DB and adds a few shop-specific helpers.
 */
require_once __DIR__ . '/functions.php';

/**
 * Build a URL relative to the storefront (project root).
 * The shop now lives at the root, so shop_url() and url() are equivalent.
 * Kept as a separate helper for readability in shop pages.
 */
function shop_url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

/** Active category list for the navbar. */
function shop_active_categories(): array
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = db()->query(
                "SELECT id, name, slug FROM categories
                 WHERE status = 'active'
                 ORDER BY name ASC"
            )->fetchAll();
        } catch (Throwable $e) {
            error_log('Active categories query failed: ' . $e->getMessage());
            $cache = [];
        }
    }
    return $cache;
}

/**
 * Effective price for a product: sale_price only counts as a real sale when
 * it is positive AND strictly below the regular price. This keeps hero,
 * cards, and cart pricing identical even if an admin misconfigures a sale
 * price at or above the regular price.
 */
function product_effective_price(array $p): float
{
    $price = (float)($p['price'] ?? 0);
    if (isset($p['sale_price']) && $p['sale_price'] !== null
        && (float)$p['sale_price'] > 0
        && (float)$p['sale_price'] < $price) {
        return (float)$p['sale_price'];
    }
    return $price;
}

/** True if the product is on sale. */
function product_is_on_sale(array $p): bool
{
    return isset($p['sale_price']) && $p['sale_price'] !== null
        && (float)$p['sale_price'] > 0
        && (float)$p['sale_price'] < (float)($p['price'] ?? 0);
}

/** % off for a sale product. */
function product_discount_percent(array $p): int
{
    if (!product_is_on_sale($p)) return 0;
    $price = (float)$p['price'];
    $sale  = (float)$p['sale_price'];
    return (int) round((($price - $sale) / $price) * 100);
}

// ----------------------------------------------------------------
// Shopping cart (session-based)
// Cart shape: $_SESSION['cart'] = [productId => quantity, ...]
// ----------------------------------------------------------------

/** Add (or increment) a product to the cart. Returns the new quantity. */
function cart_add(int $productId, int $qty = 1): int
{
    if ($qty < 1) $qty = 1;
    $_SESSION['cart'] = $_SESSION['cart'] ?? [];
    $current = (int)($_SESSION['cart'][$productId] ?? 0);
    $_SESSION['cart'][$productId] = $current + $qty;
    return $_SESSION['cart'][$productId];
}

/** Set the quantity of a cart item. 0 removes the item. */
function cart_set(int $productId, int $qty): void
{
    $_SESSION['cart'] = $_SESSION['cart'] ?? [];
    if ($qty <= 0) {
        unset($_SESSION['cart'][$productId]);
    } else {
        $_SESSION['cart'][$productId] = $qty;
    }
}

/** Remove a single item from the cart. */
function cart_remove(int $productId): void
{
    unset($_SESSION['cart'][$productId]);
}

/** Wipe the cart. */
function cart_clear(): void
{
    unset($_SESSION['cart']);
}

/** Return the raw cart array (productId => qty), or empty array. */
function cart_raw(): array
{
    return $_SESSION['cart'] ?? [];
}

/** Total number of items in the cart (sum of quantities). */
function cart_count(): int
{
    return array_sum(cart_raw());
}

/**
 * Hydrate the cart with current product data from DB.
 * Returns: [
 *   'items'    => [['product' => row, 'qty' => n, 'unit_price' => x, 'line_total' => y], ...],
 *   'subtotal' => float,
 * ]
 *
 * Stale items (deleted/inactive products) are silently dropped from the cart.
 */
function cart_load(): array
{
    $cart = cart_raw();
    if (!$cart) return ['items' => [], 'subtotal' => 0.0];

    $ids = array_map('intval', array_keys($cart));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    try {
        $stmt = db()->prepare(
            "SELECT * FROM products
             WHERE id IN ($placeholders) AND status = 'active'"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Cart load failed: ' . $e->getMessage());
        return ['items' => [], 'subtotal' => 0.0];
    }

    $items    = [];
    $subtotal = 0.0;
    $byId     = [];
    foreach ($rows as $r) $byId[(int)$r['id']] = $r;

    foreach ($cart as $pid => $qty) {
        $pid = (int)$pid;
        $qty = (int)$qty;
        if (!isset($byId[$pid]) || $qty < 1) {
            unset($_SESSION['cart'][$pid]);
            continue;
        }
        $product   = $byId[$pid];
        $unitPrice = product_effective_price($product);
        $lineTotal = $unitPrice * $qty;
        $subtotal += $lineTotal;
        $items[] = [
            'product'    => $product,
            'qty'        => $qty,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
        ];
    }

    return ['items' => $items, 'subtotal' => $subtotal];
}

/** Generate a unique order number, e.g. ORD-202605-0007. */
function generate_order_number(): string
{
    $prefix = 'ORD-' . date('Ym') . '-';
    // FOR UPDATE next-key-locks the matching range of the UNIQUE index, so
    // two concurrent checkouts cannot compute the same next number; the
    // second waits until the first commits and then reads the newer max.
    $stmt   = db()->prepare(
        "SELECT order_number FROM orders
         WHERE order_number LIKE :p
         ORDER BY id DESC LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':p' => $prefix . '%']);
    $last = $stmt->fetchColumn();
    $nextNum = 1;
    if ($last && preg_match('/-(\d+)$/', $last, $m)) {
        $nextNum = (int)$m[1] + 1;
    }
    return $prefix . str_pad((string)$nextNum, 4, '0', STR_PAD_LEFT);
}

// ----------------------------------------------------------------
// Wishlist (session-based, available to guests)
// Wishlist shape: $_SESSION['wishlist'] = [productId, productId, ...]
// ----------------------------------------------------------------

/** Return a clean list of product IDs saved in the current visitor's wishlist. */
function wishlist_raw(): array
{
    $raw = $_SESSION['wishlist'] ?? [];
    if (!is_array($raw)) $raw = [];

    $ids = [];
    foreach ($raw as $id) {
        $id = (int)$id;
        if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
    }
    $_SESSION['wishlist'] = $ids;
    return $ids;
}

function wishlist_has(int $productId): bool
{
    return in_array($productId, wishlist_raw(), true);
}

function wishlist_add(int $productId): void
{
    $ids = wishlist_raw();
    if ($productId > 0 && !in_array($productId, $ids, true)) {
        $ids[] = $productId;
        $_SESSION['wishlist'] = $ids;
    }
}

function wishlist_remove(int $productId): void
{
    $_SESSION['wishlist'] = array_values(array_filter(
        wishlist_raw(),
        static function ($id) use ($productId): bool { return (int)$id !== $productId; }
    ));
}

function wishlist_clear(): void
{
    unset($_SESSION['wishlist']);
}

function wishlist_count(): int
{
    return count(wishlist_raw());
}

/** Hydrate saved wishlist IDs with currently active products, preserving save order. */
function wishlist_load(): array
{
    $ids = wishlist_raw();
    if (!$ids) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = db()->prepare(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id IN ($placeholders) AND p.status = 'active'"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Session product list load failed: ' . $e->getMessage());
        return [];
    }

    $byId = [];
    foreach ($rows as $row) $byId[(int)$row['id']] = $row;

    $products = [];
    $validIds = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $products[] = $byId[$id];
            $validIds[] = $id;
        }
    }
    $_SESSION['wishlist'] = $validIds;
    return $products;
}

// ----------------------------------------------------------------
// Recently viewed products (session-based)
// ----------------------------------------------------------------

function remember_recent_product(int $productId, int $limit = 6): void
{
    if ($productId < 1) return;
    $ids = array_values(array_filter(
        $_SESSION['recently_viewed'] ?? [],
        static function ($id): bool { return (int)$id > 0; }
    ));
    $ids = array_values(array_filter($ids, static function ($id) use ($productId): bool {
        return (int)$id !== $productId;
    }));
    array_unshift($ids, $productId);
    $_SESSION['recently_viewed'] = array_slice($ids, 0, max(1, $limit));
}

function recently_viewed_products(int $limit = 6): array
{
    $ids = $_SESSION['recently_viewed'] ?? [];
    if (!is_array($ids)) return [];
    $ids = array_values(array_filter(array_map('intval', $ids), static function ($id): bool {
        return $id > 0;
    }));
    $ids = array_slice($ids, 0, max(1, $limit));
    if (!$ids) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = db()->prepare(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id IN ($placeholders) AND p.status = 'active'"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Recently viewed products load failed: ' . $e->getMessage());
        return [];
    }

    $byId = [];
    foreach ($rows as $row) $byId[(int)$row['id']] = $row;
    $products = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) $products[] = $byId[$id];
    }
    return $products;
}
