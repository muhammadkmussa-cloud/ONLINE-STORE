<?php
/**
 * Migration runner — applies sql/migrations.sql.
 * Safe to re-run: every CREATE / INSERT uses IF NOT EXISTS / ON DUPLICATE KEY.
 *
 * After confirming everything is in order, you can delete this file.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

$migrationToken = getenv('MIGRATION_TOKEN') ?: '';
$providedMigrationToken = (string)($_GET['token'] ?? $_POST['setup_token'] ?? '');
if (APP_ENV === 'production' || $migrationToken === '' || $providedMigrationToken === ''
    || !hash_equals($migrationToken, $providedMigrationToken)) {
    http_response_code(404);
    exit('Migration runner unavailable.');
}

$messages = [];
$errors   = [];
$applied  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $pdo = db();
        $sql = file_get_contents(__DIR__ . '/../sql/migrations.sql');
        // Strip SQL comments and split.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => $s !== ''
        );
        foreach ($statements as $oneSql) {
            $pdo->exec($oneSql);
        }
        $messages[] = 'Migration applied (' . count($statements) . ' statements).';
        $applied = true;
    } catch (Throwable $e) {
        $errors[] = 'Migration failed: ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Migrate · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>body { background:#f5f7fb; min-height:100vh; display:flex; align-items:center; }</style>
</head>
<body>
<div class="container">
  <div class="card shadow-sm mx-auto" style="max-width:560px">
    <div class="card-body p-4 p-md-5">
      <h3><i class="bi bi-arrow-up-circle"></i> Run migrations</h3>
      <p class="text-muted">
        Applies <code>sql/migrations.sql</code>: adds e-commerce, review, payment,
        refund, delivery, and pricing-snapshot tables plus checkout settings.
      </p>

      <?php foreach ($messages as $m): ?>
        <div class="alert alert-success py-2"><?= e($m) ?></div>
      <?php endforeach; ?>
      <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger py-2"><?= e($err) ?></div>
      <?php endforeach; ?>

      <?php if ($applied): ?>
        <div class="alert alert-info">Migration successful.
          You can now <a href="<?= e(admin_url('products.php')) ?>">manage products</a>.</div>
        <p class="small text-muted">For security, please delete <code>migrate.php</code>.</p>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="setup_token" value="<?= e($providedMigrationToken) ?>">
          <button class="btn btn-primary w-100">
            <i class="bi bi-rocket-takeoff"></i> Apply migration
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
