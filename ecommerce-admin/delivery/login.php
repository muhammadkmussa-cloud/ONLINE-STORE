<?php
require_once __DIR__ . '/includes/auth.php';

if (driver_is_logged_in()) {
    header('Location: ' . driver_url());
    exit;
}

$pageTitle = 'Driver sign in';
$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $errors[] = 'Enter your driver email and password.';
    } else {
        $result = attempt_driver_login($email, $password);
        if ($result === 'ok') {
            header('Location: ' . driver_url());
            exit;
        }
        $errors[] = $result === 'rate_limited'
            ? 'Too many unsuccessful attempts. Please wait 15 minutes and try again.'
            : 'Invalid driver credentials or disabled account.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(url('delivery/assets/style.css')) ?>">
</head>
<body class="delivery-login-body">
<div class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
  <div class="card delivery-login-card shadow-sm">
    <div class="card-body p-4 p-md-5">
      <div class="text-center mb-4">
        <div class="delivery-login-icon"><i class="bi bi-truck"></i></div>
        <h1 class="h3 mt-3 mb-1">Delivery portal</h1>
        <p class="text-muted mb-0">Sign in with the account created by an administrator.</p>
      </div>
      <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
      <?php endforeach; ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label">Driver email</label>
          <input type="email" name="email" class="form-control form-control-lg"
                 value="<?= e($email) ?>" autocomplete="username" required>
        </div>
        <div class="mb-4">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control form-control-lg"
                 autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary btn-lg w-100"><i class="bi bi-box-arrow-in-right"></i> Sign in</button>
      </form>
      <p class="small text-muted text-center mt-4 mb-0">Need access? Ask an administrator to create or reset your driver account.</p>
    </div>
  </div>
</div>
</body>
</html>
