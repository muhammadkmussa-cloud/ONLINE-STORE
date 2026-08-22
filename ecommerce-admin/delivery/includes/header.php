<?php
require_once __DIR__ . '/auth.php';
require_driver_login();
$pageTitle = $pageTitle ?? 'Delivery portal';
$driver = current_driver();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · Delivery portal</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_url('delivery/assets/style.css')) ?>">
</head>
<body class="delivery-body">
<nav class="navbar navbar-dark delivery-navbar">
  <div class="container">
    <a class="navbar-brand fw-bold" href="<?= e(driver_url()) ?>">
      <i class="bi bi-truck"></i> Delivery portal
    </a>
    <div class="d-flex align-items-center gap-3 text-white">
      <span class="small d-none d-sm-inline"><i class="bi bi-person-circle"></i> <?= e($driver['name']) ?></span>
      <form method="post" action="<?= e(driver_url('logout.php')) ?>" class="m-0">
        <?= csrf_field() ?>
        <button class="btn btn-sm btn-outline-light" type="submit">
          <i class="bi bi-box-arrow-right"></i> Sign out
        </button>
      </form>
    </div>
  </div>
</nav>
<main class="container py-4">
  <?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
      <?= e($f['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endforeach; ?>
