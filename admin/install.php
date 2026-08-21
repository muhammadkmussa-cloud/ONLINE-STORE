<?php
/**
 * One-time installer.
 *  1. Creates the database (if it doesn't exist).
 *  2. Runs schema.sql.
 *  3. Creates the default admin user with a properly-hashed password.
 *
 * After running successfully, DELETE this file.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

$installToken = getenv('INSTALL_TOKEN') ?: '';
$providedInstallToken = (string)($_GET['token'] ?? $_POST['setup_token'] ?? '');
if (APP_ENV === 'production' || $installToken === '' || $providedInstallToken === ''
    || !hash_equals($installToken, $providedInstallToken)) {
    http_response_code(404);
    exit('Installer unavailable.');
}

$messages = [];
$errors   = [];
$done     = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $adminName  = trim($_POST['name']     ?? 'Super Admin');
    $adminEmail = trim($_POST['email']    ?? '');
    $adminPass  = (string)($_POST['password'] ?? '');

    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid admin email is required.';
    }
    if (strlen($adminPass) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    if (!$errors) {
        try {
            // 1. Connect without a database, create it if missing.
            $rootDsn = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
            $pdo = new PDO($rootDsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
                        DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $messages[] = 'Database <code>' . e(DB_NAME) . '</code> ready.';

            // 2. Run schema.sql.
            $pdo->exec('USE `' . DB_NAME . '`');
            $sql = file_get_contents(__DIR__ . '/../sql/schema.sql');
            // Strip the CREATE DATABASE / USE statements (already executed).
            $sql = preg_replace('/CREATE DATABASE.*?;/is', '', $sql);
            $sql = preg_replace('/USE\s+`?\w+`?\s*;/i', '', $sql);
            // Strip SQL comments to avoid splitting issues.
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            // Split into individual statements (naive but works for our schema).
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                fn($s) => $s !== ''
            );
            foreach ($statements as $oneSql) {
                $pdo->exec($oneSql);
            }
            $messages[] = 'Schema executed (' . count($statements) . ' statements).';

            // 3. Create / update the admin user with a real bcrypt hash.
            $hash = password_hash($adminPass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                'INSERT INTO users (name, email, password, role, status)
                 VALUES (:n, :e, :p, "admin", "active")
                 ON DUPLICATE KEY UPDATE name = VALUES(name),
                                         password = VALUES(password),
                                         role = "admin",
                                         status = "active"'
            );
            $stmt->execute([
                ':n' => $adminName,
                ':e' => $adminEmail,
                ':p' => $hash,
            ]);
            $messages[] = 'Admin user <code>' . e($adminEmail) . '</code> created.';
            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Installer failed: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
    body { background:#f5f7fb; min-height:100vh; display:flex; align-items:center; }
    .install-card { max-width:560px; margin:auto; }
</style>
</head>
<body>
<div class="container">
  <div class="card shadow-sm install-card">
    <div class="card-body p-4 p-md-5">
      <h3 class="mb-1"><i class="bi bi-box-seam"></i> Install <?= e(APP_NAME) ?></h3>
      <p class="text-muted">Create the database and your administrator account.</p>

      <?php foreach ($messages as $m): ?>
        <div class="alert alert-success py-2"><?= $m /* trusted */ ?></div>
      <?php endforeach; ?>
      <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger py-2"><?= e($err) ?></div>
      <?php endforeach; ?>

      <?php if ($done): ?>
        <div class="alert alert-info">
          <strong>Installation complete.</strong><br>
          For security, please <strong>delete <code>install.php</code></strong> now.
        </div>
        <a href="<?= e(admin_url('login.php')) ?>" class="btn btn-primary w-100">
          <i class="bi bi-box-arrow-in-right"></i> Go to login
        </a>
      <?php else: ?>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="setup_token" value="<?= e($providedInstallToken) ?>">
          <div class="mb-3">
            <label class="form-label">Admin name</label>
            <input type="text" name="name" class="form-control"
                   value="<?= e($_POST['name'] ?? 'Super Admin') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Admin email</label>
            <input type="email" name="email" class="form-control"
                   value="<?= e($_POST['email'] ?? '') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Admin password</label>
            <input type="password" name="password" class="form-control" minlength="6" required>
            <div class="form-text">At least 6 characters.</div>
          </div>
          <button class="btn btn-primary w-100">
            <i class="bi bi-rocket-takeoff"></i> Run installer
          </button>
        </form>
        <hr>
        <small class="text-muted d-block">
          DB host: <code><?= e(DB_HOST) ?></code> ·
          DB name: <code><?= e(DB_NAME) ?></code> ·
          User: <code><?= e(DB_USER) ?></code>
          <br>Edit <code>config/config.php</code> if these are wrong.
        </small>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
