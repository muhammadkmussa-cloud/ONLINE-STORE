<?php
/**
 * Demo data seeder — populates the store with a realistic dataset for
 * development and manual testing.
 *
 * CLI only:
 *   php scripts/seed_demo_data.php
 *   DB_HOST=... DB_PORT=... DB_NAME=... php scripts/seed_demo_data.php
 *
 * Creates: categories, products + generated gallery images, orders across
 * all statuses/dates (with delivery/pickup snapshots and access tokens),
 * reviews, delivery driver accounts, newsletter subscribers, activity log
 * entries and distance-pricing settings.
 *
 * Credentials set on every run:
 *   Admin   admin@alasusa.test / Admin@12345
 *   Drivers driver1@alasusa.test driver2@alasusa.test driver3@alasusa.test / Driver@12345
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/functions.php';

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    exit("DB connection failed: {$e->getMessage()}\nCheck DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS env values.\n");
}

echo 'Seeding demo data into ' . DB_NAME . "...\n";

function pick(array $pool)
{
    return $pool[array_rand($pool)];
}

/** Generate a colorful placeholder JPEG with product name + price. */
function make_product_image(string $dir, string $filename, string $label, string $price, array $rgb): void
{
    $w = 800;
    $h = 600;
    $img = imagecreatetruecolor($w, $h);

    $base   = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    $dark   = imagecolorallocate($img, max(0, $rgb[0] - 60), max(0, $rgb[1] - 60), max(0, $rgb[2] - 60));
    $light  = imagecolorallocate($img, min(255, $rgb[0] + 70), min(255, $rgb[1] + 70), min(255, $rgb[2] + 70));
    $white  = imagecolorallocate($img, 255, 255, 255);
    $shadow = imagecolorallocate($img, 0, 0, 0);

    imagefilledrectangle($img, 0, 0, $w, $h, $base);
    for ($i = -$h; $i < $w; $i += 90) {
        imagefilledpolygon(
            $img,
            [
                max(0, $i), $h,
                min($w, $i + 40), $h,
                min($w, $i + 40 + $h), 0,
                max(0, $i + $h), 0,
            ],
            mt_rand(0, 1) ? $light : $dark
        );
    }
    imagefilledrectangle($img, 60, 200, $w - 60, 420, imagecolorallocatealpha($img, 255, 255, 255, 55));
    imagerectangle($img, 60, 200, $w - 60, 420, $white);

    $lines = [];
    foreach (explode("\n", wordwrap(strtoupper($label), 24, "\n", false)) as $line) {
        if (count($lines) >= 3) break;
        $lines[] = $line;
    }
    $y = 250 - (count($lines) - 1) * 15;
    foreach ($lines as $line) {
        $x = (int)(($w - imagefontwidth(5) * strlen($line)) / 2);
        imagestring($img, 5, $x + 2, $y + 2, $line, $shadow);
        imagestring($img, 5, $x, $y, $line, $white);
        $y += 30;
    }

    $px = (int)(($w - imagefontwidth(5) * strlen($price)) / 2);
    imagestring($img, 5, $px + 1, 371, $price, $shadow);
    imagestring($img, 5, $px, 370, $price, $white);

    imagejpeg($img, $dir . '/' . $filename, 88);
    imagedestroy($img);
}

/* ---------------------------------------------------------------- */
/* Wipe previous demo data                                           */
/* ---------------------------------------------------------------- */

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ([
    'delivery_status_history', 'delivery_assignments',
    'order_donations', 'order_delivery_distance_pricing',
    'order_delivery_pricing', 'order_delivery_locations',
    'order_pickup_snapshots', 'order_access_tokens', 'payments',
    'order_items', 'orders', 'product_reviews', 'product_images',
    'products', 'categories', 'newsletter_subscribers',
] as $table) {
    $pdo->exec("TRUNCATE TABLE `$table`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$uploadDir = UPLOADS_PATH . '/products';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}
foreach (glob($uploadDir . '/*.jpg') ?: [] as $f) {
    @unlink($f);
}

/* ---------------------------------------------------------------- */
/* Accounts: admin reset + drivers                                   */
/* ---------------------------------------------------------------- */

$adminHash = password_hash('Admin@12345', PASSWORD_BCRYPT, ['cost' => 10]);
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = 'admin@alasusa.test'");
$stmt->execute();
if ($row = $stmt->fetch()) {
    $pdo->prepare("UPDATE users SET name='Store Admin', password=?, role='admin', status='active' WHERE id=?")
        ->execute([$adminHash, $row['id']]);
    $adminId = (int)$row['id'];
} else {
    $pdo->prepare("INSERT INTO users (name,email,password,role,status) VALUES ('Store Admin','admin@alasusa.test',?,'admin','active')")
        ->execute([$adminHash]);
    $adminId = (int)$pdo->lastInsertId();
}
echo "  admin@alasusa.test / Admin@12345\n";
// Report any other admin accounts so the operator knows what exists.
foreach ($pdo->query("SELECT email FROM users WHERE role IN ('admin','editor') AND email <> 'admin@alasusa.test'")->fetchAll() as $other) {
    echo "  existing staff account kept: {$other['email']}\n";
}

$drivers = [
    ['John Otieno',   'driver1@alasusa.test', '+254700111222'],
    ['Fatuma Hassan', 'driver2@alasusa.test', '+254700333444'],
    ['Peter Kimani',  'driver3@alasusa.test', '+254700555666'],
];
$driverHash = password_hash('Driver@12345', PASSWORD_BCRYPT, ['cost' => 10]);
$driverIds = [];
foreach ($drivers as [$name, $email, $phone]) {
    $pdo->prepare(
        "INSERT INTO users (name,email,password,role,status,phone)
         VALUES (:n,:e,:p,'delivery_driver','active',:ph)
         ON DUPLICATE KEY UPDATE password=:p, role='delivery_driver', status='active', phone=:ph"
    )->execute([':n' => $name, ':e' => $email, ':p' => $driverHash, ':ph' => $phone]);
    $q = $pdo->prepare('SELECT id FROM users WHERE email = :e');
    $q->execute([':e' => $email]);
    $driverIds[$email] = (int)$q->fetchColumn();
}
echo "  3 delivery drivers (Driver@12345)\n";

/* ---------------------------------------------------------------- */
/* Categories                                                        */
/* ---------------------------------------------------------------- */

$categoryData = [
    ['Electronics',      'Phones, laptops, audio and gadgets.'],
    ['Fashion',          'Clothing, shoes and accessories for everyone.'],
    ['Home & Kitchen',   'Everything to cook, clean and decorate.'],
    ['Beauty & Health',  'Skincare, fragrance and wellness.'],
    ['Sports & Fitness', 'Gear for training and outdoor life.'],
    ['Books',            'Bestsellers, classics and children books.'],
    ['Toys & Games',     'Fun for kids and the whole family.'],
    ['Groceries',        'Daily essentials and treats.'],
];
$catIds = [];
$catColors = [
    [41, 128, 185], [231, 76, 60], [39, 174, 96], [155, 89, 182],
    [243, 156, 18], [52, 73, 94], [22, 160, 133], [211, 84, 0],
];
foreach ($categoryData as $i => [$name, $desc]) {
    $pdo->prepare('INSERT INTO categories (name, slug, description, status) VALUES (:n,:s,:d,"active")')
        ->execute([':n' => $name, ':s' => slugify($name), ':d' => $desc]);
    $catIds[$name] = (int)$pdo->lastInsertId();
}
echo '  ' . count($catIds) . " categories\n";

/* ---------------------------------------------------------------- */
/* Products                                                          */
/* ---------------------------------------------------------------- */

$productNames = [
    'Electronics' => [
        ['Wireless Bluetooth Headphones', 4500, null],
        ['Smart Fitness Watch Pro', 8900, 7999],
        ['65W GaN Fast Charger', 2200, null],
        ['Portable Bluetooth Speaker', 3100, null],
        ['4K Action Camera Ultra', 12500, 10999],
        ['Noise Cancelling Earbuds Air', 6200, null],
        ['USB-C Hub 7-in-1', 2800, null],
        ['Robot Vacuum Cleaner X2', 34900, 29900],
        ['27" QHD Monitor ViewMax', 28900, null],
        ['Mechanical Gaming Keyboard RGB', 5400, null],
    ],
    'Fashion' => [
        ["Men's Classic Denim Jacket", 3200, null],
        ["Women's Summer Maxi Dress", 2700, 1999],
        ['Leather Casual Sneakers', 4100, null],
        ['Polarized Aviator Sunglasses', 1500, null],
        ['Wool Blend Winter Scarf', 1200, null],
        ["Kids' Graphic Print T-Shirt", 850, null],
        ['Genuine Leather Belt', 1350, null],
        ['Canvas Backpack 25L', 2450, null],
        ['Ankle Chelsea Boots', 5200, 4499],
        ['Sportswear Running Leggings', 1800, null],
    ],
    'Home & Kitchen' => [
        ['Stainless Steel Cookware Set 8pc', 9800, 8499],
        ['Espresso Coffee Machine Barista', 24900, null],
        ['Air Fryer 5.5L Digital', 8900, null],
        ['Memory Foam Pillow Pair', 2300, null],
        ['Ceramic Dinner Set 16pc', 6700, null],
        ['LED Floor Lamp Modern', 3400, null],
        ['Cotton Bath Towel Set 6pc', 2900, 2400],
        ['Glass Storage Jars Set of 4', 1600, null],
        ['Non-stick Frying Pan 28cm', 1900, null],
        ['Aroma Diffuser Ultrasonic', 2600, null],
    ],
    'Beauty & Health' => [
        ['Vitamin C Brightening Serum', 1450, null],
        ['Electric Toothbrush Sonic', 3800, 3299],
        ['Argan Hair Oil 100ml', 950, null],
        ['SPF50 Sunscreen Lotion', 1100, null],
        ['Aloe Vera Moisturizer 200ml', 1250, null],
        ['Digital Blood Pressure Monitor', 4900, null],
        ['Manicure Kit Stainless 12pc', 1700, null],
        ['Beard Trimmer Precision', 2400, null],
    ],
    'Sports & Fitness' => [
        ['Adjustable Dumbbell Set 20kg', 11500, null],
        ['Yoga Mat Premium 6mm', 1900, null],
        ['Mountain Bike Helmet Aero', 2600, null],
        ['Resistance Bands Set 5pc', 1300, null],
        ['Football Size 5 Pro Match', 2100, null],
        ['Camping Tent 4-Person', 8700, 7499],
        ['Jump Rope Weighted Speed', 800, null],
        ['Cycling Water Bottle 750ml', 650, null],
    ],
    'Books' => [
        ['The Great Adventure Novel', 950, null],
        ['Cookbook: East African Kitchen', 1650, null],
        ["Children's Illustrated Atlas", 1400, null],
        ['Business Strategy Handbook', 1850, 1499],
        ['Science Encyclopedia for Kids', 2100, null],
        ['Poetry Collection Deluxe', 750, null],
    ],
    'Toys & Games' => [
        ['Building Blocks Set 500pcs', 2800, null],
        ['Remote Control Racing Car', 3500, 2999],
        ['Family Board Game Classic', 1900, null],
        ['Plush Teddy Bear XL', 1500, null],
        ['Puzzle 1000 Pieces Landscape', 1100, null],
        ['Educational STEM Robot Kit', 5600, null],
    ],
    'Groceries' => [
        ['Premium Arabica Coffee Beans 1kg', 1750, null],
        ['Organic Honey Jar 500g', 850, null],
        ['Mixed Nuts Tray 400g', 950, null],
        ['Dark Chocolate Box 250g', 700, null],
        ['Green Tea Bags Pack of 100', 600, null],
        ['Extra Virgin Olive Oil 1L', 1600, null],
    ],
];

$statuses = ['active', 'active', 'active', 'active', 'draft', 'inactive']; // weighted mix

$count = 0;
foreach ($productNames as $catName => $items) {
    $catId = $catIds[$catName];
    $baseColor = $catColors[$catId % count($catColors)];
    foreach ($items as [$name, $price, $salePrice]) {
        $count++;
        $sku = strtoupper(substr(slugify($catName), 0, 3)) . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
        $status = $statuses[$count % count($statuses)];
        $stock = mt_rand(0, 120);
        $featured = ($count % 7 === 0) ? 1 : 0;
        $shortDesc = "Top quality {$name} in the {$catName} range. Fast delivery in Nairobi.";
        $desc = "{$name} — a customer favourite from our {$catName} collection. "
            . 'Carefully selected materials, tested quality and a 12-month warranty. '
            . 'Order today and enjoy same-day dispatch within Nairobi and next-day countrywide delivery.';

        // Generate 2-3 gallery images.
        $files = [];
        $variants = ['', ' - detail view', ' - in use'];
        $nImages = mt_rand(2, 3);
        for ($g = 0; $g < $nImages; $g++) {
            $filename = strtolower($sku) . '-' . ($g + 1) . '.jpg';
            $shade = [
                min(255, $baseColor[0] + $g * 26),
                min(255, $baseColor[1] + (($g * 17) % 60)),
                min(255, $baseColor[2] + $g * 21),
            ];
            make_product_image(
                $uploadDir,
                $filename,
                $name . $variants[$g],
                'KSH ' . number_format((float)($salePrice ?? $price), 0),
                $shade
            );
            $files[] = $filename;
        }

        $pdo->prepare(
            'INSERT INTO products (category_id,name,slug,sku,short_description,description,price,sale_price,stock,image,status,featured)
             VALUES (:cid,:n,:s,:sku,:sd,:d,:p,:sp,:st,:img,:status,:feat)'
        )->execute([
            ':cid' => $catId, ':n' => $name, ':s' => slugify($name), ':sku' => $sku,
            ':sd' => $shortDesc, ':d' => $desc,
            ':p' => number_format((float)$price, 2, '.', ''),
            ':sp' => $salePrice ? number_format((float)$salePrice, 2, '.', '') : null,
            ':st' => $stock, ':img' => $files[0], ':status' => $status, ':feat' => $featured,
        ]);
        $pid = (int)$pdo->lastInsertId();

        foreach ($files as $sortIdx => $file) {
            $pdo->prepare(
                'INSERT INTO product_images (product_id,filename,original_name,sort_order,is_primary)
                 VALUES (:pid,:f,:o,:so,:ip)'
            )->execute([
                ':pid' => $pid, ':f' => $file, ':o' => $name . ' photo ' . ($sortIdx + 1),
                ':so' => $sortIdx, ':ip' => $sortIdx === 0 ? 1 : 0,
            ]);
        }
    }
}
echo "  {$count} products with generated gallery images\n";

/* ---------------------------------------------------------------- */
/* Reviews                                                           */
/* ---------------------------------------------------------------- */

$reviewers = [
    ['Amina Yusuf', 'amina@example.com'], ['Brian Ochieng', 'brian@example.com'],
    ['Cynthia Mwangi', 'cynthia@example.com'], ['David Kariuki', 'david@example.com'],
    ['Esther Njeri', 'esther@example.com'], ['Faisal Abdi', 'faisal@example.com'],
];
$bodies = [
    'Exactly as described. Delivery was quick and the packaging was solid.',
    'Great value for money. Would buy again from this shop.',
    'Good product overall, though I expected slightly better finishing.',
    'Works perfectly, my family loves it. Highly recommended!',
    'Arrived two days earlier than expected. Super happy with this purchase.',
    'Decent quality but the colour looks a bit different from the photos.',
];
$titles = ['Excellent purchase', 'Very satisfied', 'Good but not perfect', 'Highly recommended', 'Fast delivery', 'As described'];

$pids = $pdo->query("SELECT id FROM products WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
$nReviews = 0;
for ($r = 0; $r < 45; $r++) {
    $who = pick($reviewers);
    $rStatus = $r < 30 ? 'approved' : ($r < 42 ? 'pending' : 'rejected');
    try {
        $pdo->prepare(
            'INSERT INTO product_reviews (product_id,customer_name,customer_email,rating,title,body,status,created_at)
             VALUES (:pid,:cn,:ce,:rt,:t,:b,:st,:ca)'
        )->execute([
            ':pid' => pick($pids), ':cn' => $who[0], ':ce' => $who[1],
            ':rt' => mt_rand(3, 5), ':t' => pick($titles), ':b' => pick($bodies),
            ':st' => $rStatus,
            ':ca' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, 20) . ' days')),
        ]);
        $nReviews++;
    } catch (Throwable $ignored) {
        // duplicate reviewer/product pair is fine to skip
    }
}
echo "  {$nReviews} reviews (approved/pending/rejected)\n";

/* ---------------------------------------------------------------- */
/* Orders                                                            */
/* ---------------------------------------------------------------- */

$prodRows = $pdo->query(
    "SELECT p.id, p.name, p.sku, p.image, COALESCE(p.sale_price, p.price) AS unit_price
     FROM products p WHERE p.status = 'active'"
)->fetchAll();

$customers = [
    ['Grace Wanjiru', 'grace@example.com', '+254711000111'],
    ['Ali Abdi Noor', 'ali@example.com', '+254722333444'],
    ['Mercy Chebet', 'mercy@example.com', '+254733444555'],
    ['Samuel Mutua', 'samuel@example.com', '+254745555666'],
    ['Zainab Mohamed', 'zainab@example.com', '+254796666777'],
    ['Kevin Omondi', 'kevin@example.com', '+254707888999'],
    ['Nancy Achieng', 'nancy@example.com', '+254718999000'],
    ['Yusuf Ibrahim', 'yusuf@example.com', '+254729000111'],
];
$cities = [['Nairobi', '00100'], ['Mombasa', '80100'], ['Kisumu', '40100'], ['Nakuru', '20100']];
$streets = ['Ngong Road', 'Moi Avenue', 'Kenyatta Avenue', 'Ringen Road', 'Nyali Road', 'Tom Mboya Street'];

// Store coordinates (must match settings below) for distance pricing rows.
$storeLat = -1.292066;
$storeLng = 36.821945;
$ratePerKm = 2.50;

$statusPlan = [
    'pending', 'pending', 'pending', 'processing', 'processing',
    'shipped', 'shipped', 'shipped', 'completed', 'completed',
    'completed', 'completed', 'cancelled',
];

$orderCount = 0;
$driverList = array_values($driverIds);
for ($i = 0; $i < 40; $i++) {
    $cust = $customers[$i % count($customers)];
    [$city, $zip] = $cities[$i % count($cities)];
    $status = $statusPlan[$i % count($statusPlan)];
    $createdAt = date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, 29) . ' days -' . mt_rand(0, 720) . ' minutes'));
    $isPickup = ($i % 5 === 4); // every 5th order is pickup

    // 1-3 distinct items per order.
    $picked = (array)array_rand($prodRows, mt_rand(1, 3));
    $subtotal = 0.0;
    $items = [];
    foreach ($picked as $idx) {
        $p = $prodRows[$idx];
        $qty = mt_rand(1, 3);
        $unit = (float)$p['unit_price'];
        $line = round($qty * $unit, 2);
        $subtotal += $line;
        $items[] = $p + ['qty' => $qty, 'unit' => $unit, 'line' => $line];
    }

    $fee = 0.0;
    $distance = null;
    if (!$isPickup) {
        $distance = mt_rand(50, 220) / 10.0; // 5-22 km
        $fee = round(ceil($distance) * $ratePerKm, 2);
    }
    $total = round($subtotal + $fee, 2);

    $orderNumber = sprintf('ORD-%s-%04d', date('Ym', strtotime($createdAt)), $orderCount + 1);

    $pdo->prepare(
        "INSERT INTO orders
         (order_number,customer_name,customer_email,customer_phone,shipping_address,
          shipping_city,shipping_zip,shipping_country,subtotal,shipping_fee,total,
          payment_method,status,notes,created_at,updated_at)
         VALUES (:on,:cn,:ce,:cp,:sa,:sc,:sz,:sco,:sub,:fee,:tot,'cod',:st,:notes,:ca,:ua)"
    )->execute([
        ':on' => $orderNumber, ':cn' => $cust[0], ':ce' => $cust[1], ':cp' => $cust[2],
        ':sa' => $isPickup
            ? 'Store pickup - counter'
            : mt_rand(1, 200) . ' ' . pick($streets),
        ':sc' => $city, ':sz' => $zip, ':sco' => 'Kenya',
        ':sub' => number_format($subtotal, 2, '.', ''),
        ':fee' => number_format($fee, 2, '.', ''),
        ':tot' => number_format($total, 2, '.', ''),
        ':st' => $status,
        ':notes' => ($i % 6 === 0) ? 'Please call before delivery.' : null,
        ':ca' => $createdAt, ':ua' => $createdAt,
    ]);
    $orderId = (int)$pdo->lastInsertId();
    $orderCount++;

    foreach ($items as $it) {
        $pdo->prepare(
            'INSERT INTO order_items (order_id,product_id,product_name,product_sku,product_image,unit_price,quantity,line_total)
             VALUES (:oid,:pid,:pn,:ps,:pi,:up,:q,:lt)'
        )->execute([
            ':oid' => $orderId, ':pid' => $it['id'], ':pn' => $it['name'],
            ':ps' => $it['sku'], ':pi' => $it['image'],
            ':up' => number_format($it['unit'], 2, '.', ''),
            ':q' => $it['qty'], ':lt' => number_format($it['line'], 2, '.', ''),
        ]);
    }

    // Fulfilment snapshot tables (mirrors what checkout writes).
    if ($isPickup) {
        $pdo->prepare(
            'INSERT INTO order_pickup_snapshots (order_id,pickup_address,pickup_instructions)
             VALUES (:oid,:pa,:pi)'
        )->execute([
            ':oid' => $orderId,
            ':pa' => 'Bilal Store, ' . pick($streets) . ', Nairobi CBD',
            ':pi' => 'Collect your order from the store after confirmation.',
        ]);
        $pdo->prepare(
            "INSERT INTO order_delivery_pricing (order_id,pricing_mode,delivery_fee,currency_code)
             VALUES (:oid,'pickup',0.00,'KSH')"
        )->execute([':oid' => $orderId]);
        $pdo->prepare("INSERT INTO order_delivery_locations (order_id,delivery_method) VALUES (:oid,'pickup')")
            ->execute([':oid' => $orderId]);
    } else {
        $angle = deg2rad(mt_rand(0, 359));
        $cLat = $storeLat + sin($angle) * $distance / 111.0;
        $cLng = $storeLng + cos($angle) * $distance / (111.32 * max(0.2, abs(cos(deg2rad($storeLat)))));
        $pdo->prepare(
            'INSERT INTO order_delivery_locations
             (order_id,delivery_method,latitude,longitude,accuracy_meters,location_source,captured_at)
             VALUES (:oid,"delivery",:la,:lo,:acc,"browser_geolocation",:cap)'
        )->execute([
            ':oid' => $orderId,
            ':la' => number_format($cLat, 7, '.', ''), ':lo' => number_format($cLng, 7, '.', ''),
            ':acc' => mt_rand(15, 80) . '.00', ':cap' => $createdAt,
        ]);
        $pdo->prepare(
            'INSERT INTO order_delivery_pricing
             (order_id,pricing_mode,store_latitude,store_longitude,customer_latitude,customer_longitude,
              distance_km,rate_per_km,delivery_fee,currency_code)
             VALUES (:oid,"distance",:sla,:slo,:cla,:clo,:dkm,:rkm,:fee,"KSH")'
        )->execute([
            ':oid' => $orderId,
            ':sla' => $storeLat, ':slo' => $storeLng,
            ':cla' => number_format($cLat, 7, '.', ''), ':clo' => number_format($cLng, 7, '.', ''),
            ':dkm' => number_format($distance, 4, '.', ''), ':rkm' => number_format($ratePerKm, 4, '.', ''),
            ':fee' => number_format($fee, 2, '.', ''),
        ]);
        $pdo->prepare(
            'INSERT INTO order_delivery_distance_pricing
             (order_id,pricing_mode,store_latitude,store_longitude,customer_latitude,customer_longitude,
              actual_distance_km,billable_distance_km,rate_per_km,delivery_fee,currency_code)
             VALUES (:oid,"distance",:sla,:slo,:cla,:clo,:adkm,:bdkm,:rkm,:fee,"KSH")'
        )->execute([
            ':oid' => $orderId,
            ':sla' => $storeLat, ':slo' => $storeLng,
            ':cla' => number_format($cLat, 7, '.', ''), ':clo' => number_format($cLng, 7, '.', ''),
            ':adkm' => number_format($distance, 4, '.', ''),
            ':bdkm' => ceil($distance), ':rkm' => number_format($ratePerKm, 4, '.', ''),
            ':fee' => number_format($fee, 2, '.', ''),
        ]);
    }

    // Public tracking token: deterministic dev tokens so manual tracking
    // tests can be reproduced:
    //   seedtok-<order id>-<first 24 hex of sha256("seed<order id>")>
    // (meets the app's >=32 char token requirement)
    $token = 'seedtok-' . $orderId . '-' . substr(hash('sha256', 'seed' . $orderId), 0, 24);
    $pdo->prepare('INSERT INTO order_access_tokens (order_id, token_hash, created_at) VALUES (:oid, :th, :ca)')
        ->execute([
            ':oid' => $orderId,
            ':th' => hash('sha256', $token),
            ':ca' => $createdAt,
        ]);

    // Driver assignment + status history for non-pickup live orders.
    if (!$isPickup && in_array($status, ['processing', 'shipped', 'completed'], true)) {
        $driverId = $driverList[$i % count($driverList)];
        $pdo->prepare(
            'INSERT INTO delivery_assignments (order_id,driver_id,assigned_by,status,assigned_at)
             VALUES (:oid,:did,:by,"assigned",:at)'
        )->execute([':oid' => $orderId, ':did' => $driverId, ':by' => $adminId, ':at' => $createdAt]);
        $assignmentId = (int)$pdo->lastInsertId();

        $flow = match ($status) {
            'processing' => [],
            'shipped'    => [['picked_up', 'Driver collected the package']],
            'completed'  => [
                ['picked_up', 'Driver collected the package'],
                ['out_for_delivery', 'Package is on the way to the customer'],
                ['arrived', 'Driver arrived at the delivery location'],
                ['delivered', 'Delivered successfully'],
            ],
        };
        $stepTime = strtotime($createdAt) + mt_rand(1800, 14400);
        foreach ($flow as [$step, $note]) {
            $stepTime += mt_rand(1800, 10800);
            if ($step !== 'delivered') {
                $pdo->prepare(
                    'INSERT INTO delivery_status_history
                     (assignment_id,order_id,driver_id,status,note,created_at)
                     VALUES (:aid,:oid,:did,:st,:no,:ra)'
                )->execute([
                    ':aid' => $assignmentId, ':oid' => $orderId, ':did' => $driverId,
                    ':st' => $step, ':no' => $note,
                    ':ra' => date('Y-m-d H:i:s', $stepTime),
                ]);
            }
        }
    }
}
echo "  {$orderCount} orders with items and fulfilment snapshots\n";

/* ---------------------------------------------------------------- */
/* Settings needed for delivery pricing + newsletter + activity      */
/* ---------------------------------------------------------------- */

$settingsToSet = [
    'store_latitude'         => (string)$storeLat,
    'store_longitude'        => (string)$storeLng,
    'delivery_price_per_km'  => number_format($ratePerKm, 2, '.', ''),
    'delivery_max_radius_km' => '25',
    'cod_enabled'            => '1',
];
foreach ($settingsToSet as $key => $value) {
    $pdo->prepare('UPDATE settings SET value = :v WHERE key_name = :k')->execute([':v' => $value, ':k' => $key]);
}
echo "  distance-pricing settings applied (rate {$ratePerKm}/km)\n";

$subs = [
    'grace@example.com', 'ali@example.com', 'mercy@example.com', 'samuel@example.com',
    'zainab@example.com', 'trendy shopper <hello@shopper.example>',
];
foreach ($subs as $s) {
    $email = filter_var(trim(str_replace(['<', '>'], '', $s)), FILTER_VALIDATE_EMAIL) ?: trim($s);
    try {
        $pdo->prepare('INSERT INTO newsletter_subscribers (email) VALUES (:e)')
            ->execute([':e' => strtolower(substr($email, 0, 191))]);
    } catch (Throwable $ignored) {
        // duplicates fine
    }
}

// A few activity entries so the log is not empty.
$activities = [
    ['seed', 'Demo dataset seeded via scripts/seed_demo_data.php'],
    ['product.import', 'Imported demo catalogue'],
    ['order.create', 'Seeded historical orders'],
];
foreach ($activities as [$action, $description]) {
    try {
        $pdo->prepare('INSERT INTO activity_log (user_id, action, description, created_at) VALUES (:u,:a,:d,NOW())')
            ->execute([':u' => $adminId, ':a' => $action, ':d' => $description]);
    } catch (Throwable $ignored) {
        // activity_log schema may differ; not critical
    }
}

/* ---------------------------------------------------------------- */
/* Summary                                                           */
/* ---------------------------------------------------------------- */

$summary = [];
foreach (['categories', 'products', 'product_images', 'orders', 'order_items',
          'delivery_assignments', 'delivery_status_history', 'product_reviews'] as $table) {
    $summary[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}
echo "\nDone. Row counts:\n";
foreach ($summary as $table => $n) {
    echo str_pad("  $table", 28) . "$n\n";
}
echo "\nTest credentials:\n";
echo "  Admin panel (/admin/login.php):   admin@alasusa.test / Admin@12345\n";
echo "  Driver portal (/delivery/login.php): driver1@alasusa.test / Driver@12345 (also driver2/driver3)\n";
echo "  Tracking tokens: seedtok-<order id>-<substr(sha256('seed<id>'),0,24)> — e.g.:\n";
foreach ([1, 8, 16] as $exampleId) {
    if ($exampleId <= $orderCount) {
        echo '    order #' . $exampleId . ' token: seedtok-' . $exampleId . '-' . substr(hash('sha256', 'seed' . $exampleId), 0, 24) . "\n";
    }
}
echo "  Track at /track.php?order=<ORDER-NUMBER>&email=<customer email>&token=<token>\n";
