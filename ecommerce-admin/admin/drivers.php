<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');

$pageTitle = 'Delivery drivers';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'save_driver') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true)
            ? (string)$_POST['status'] : 'active';
        $password = (string)($_POST['password'] ?? '');

        if ($name === '') $errors[] = 'Driver name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid driver email is required.';
        if ($id === 0 && strlen($password) < 8) $errors[] = 'New driver passwords must be at least 8 characters.';
        if ($id > 0 && $password !== '' && strlen($password) < 8) $errors[] = 'Reset passwords must be at least 8 characters.';

        if (!$errors) {
            $stmt = db()->prepare(
                'SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1'
            );
            $stmt->execute([':email' => $email, ':id' => $id]);
            if ($stmt->fetch()) $errors[] = 'That email address is already in use.';
        }

        if (!$errors) {
            if ($id > 0) {
                $stmt = db()->prepare(
                    "SELECT id FROM users WHERE id = :id AND role = 'delivery_driver' LIMIT 1"
                );
                $stmt->execute([':id' => $id]);
                if (!$stmt->fetch()) {
                    $errors[] = 'Driver account not found.';
                } else {
                    $sql = 'UPDATE users SET name = :name, email = :email, phone = :phone, status = :status';
                    $params = [':name' => $name, ':email' => $email, ':phone' => $phone, ':status' => $status, ':id' => $id];
                    if ($password !== '') {
                        $sql .= ', password = :password';
                        $params[':password'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    }
                    $sql .= ' WHERE id = :id AND role = \'delivery_driver\'';
                    db()->prepare($sql)->execute($params);
                    log_activity('driver.update', 'Updated delivery driver ' . $email);
                    if ($status === 'inactive') log_activity('driver.disable', 'Disabled delivery driver ' . $email);
                    flash('success', 'Driver account updated.');
                }
            } else {
                db()->prepare(
                    "INSERT INTO users (name, email, password, role, status, phone)
                     VALUES (:name, :email, :password, 'delivery_driver', :status, :phone)"
                )->execute([
                    ':name' => $name,
                    ':email' => $email,
                    ':password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                    ':status' => $status,
                    ':phone' => $phone,
                ]);
                log_activity('driver.create', 'Created delivery driver ' . $email);
                flash('success', 'Delivery driver created.');
            }
        }
    } elseif ($action === 'toggle_status') {
        $stmt = db()->prepare(
            "SELECT id, email, status FROM users WHERE id = :id AND role = 'delivery_driver' LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        if ($driver = $stmt->fetch()) {
            $newStatus = $driver['status'] === 'active' ? 'inactive' : 'active';
            db()->prepare('UPDATE users SET status = :status WHERE id = :id')
                ->execute([':status' => $newStatus, ':id' => $id]);
            log_activity(
                $newStatus === 'active' ? 'driver.enable' : 'driver.disable',
                ($newStatus === 'active' ? 'Enabled ' : 'Disabled ') . 'delivery driver ' . $driver['email']
            );
            flash('success', 'Driver account ' . $newStatus . '.');
        } else {
            flash('warning', 'Driver account not found.');
        }
    } elseif ($action === 'reset_password') {
        $password = (string)($_POST['password'] ?? '');
        if (strlen($password) < 8) {
            $errors[] = 'Reset passwords must be at least 8 characters.';
        } else {
            $stmt = db()->prepare(
                "SELECT email FROM users WHERE id = :id AND role = 'delivery_driver' LIMIT 1"
            );
            $stmt->execute([':id' => $id]);
            if ($driver = $stmt->fetch()) {
                // session_epoch bump invalidates any driver sessions that
                // were created before this reset (checked per request).
                db()->prepare('UPDATE users SET password = :password, session_epoch = session_epoch + 1 WHERE id = :id')
                    ->execute([':password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), ':id' => $id]);
                log_activity('driver.password_reset', 'Reset password for delivery driver ' . $driver['email']);
                flash('success', 'Driver password reset.');
            } else {
                flash('warning', 'Driver account not found.');
            }
        }
    }

    if (!$errors) admin_redirect('drivers.php');

    // Failed save: repopulate the form with what the operator typed
    // (never the password) so nothing is lost.
    $failedDriverSave = false;
    if ($action === 'save_driver') {
        $failedDriverSave = true;
        $editDriver = [
            'id'     => $id,
            'name'   => $name,
            'email'  => $email,
            'phone'  => $phone,
            'status' => $status,
        ];
        $_GET['edit'] = $id > 0 ? $id : '';
    }
}

$editId = (int)($_GET['edit'] ?? 0);
if (empty($failedDriverSave)) {
    // Normal render (no failed save): load defaults or the DB row.
    $editDriver = ['id' => 0, 'name' => '', 'email' => '', 'phone' => '', 'status' => 'active'];
    if ($editId > 0) {
        $stmt = db()->prepare(
            "SELECT id, name, email, phone, status FROM users
             WHERE id = :id AND role = 'delivery_driver' LIMIT 1"
        );
        $stmt->execute([':id' => $editId]);
        if ($found = $stmt->fetch()) $editDriver = $found;
    }
}
// A failed save leaves the operator's typed values in $editDriver untouched.

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$where = ["role = 'delivery_driver'"];
$params = [];
if ($search !== '') {
    $where[] = '(name LIKE :q1 OR email LIKE :q2 OR phone LIKE :q3)';
    $params[':q1'] = like_pattern($search);
    $params[':q2'] = like_pattern($search);
    $params[':q3'] = like_pattern($search);
}
if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[] = 'status = :status';
    $params[':status'] = $statusFilter;
}
$stmt = db()->prepare(
    'SELECT id, name, email, phone, status, created_at, updated_at FROM users WHERE '
    . implode(' AND ', $where) . ' ORDER BY name ASC'
);
$stmt->execute($params);
$drivers = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title"><?= $editDriver['id'] ? 'Edit driver' : 'Create driver' ?></h5>
        <p class="text-muted small">Drivers cannot self-register and cannot access the admin panel.</p>
        <?php foreach ($errors as $error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endforeach; ?>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_driver">
          <input type="hidden" name="id" value="<?= (int)$editDriver['id'] ?>">
          <div class="mb-3"><label class="form-label">Name</label><input name="name" class="form-control" value="<?= e($editDriver['name']) ?>" required></div>
          <div class="mb-3"><label class="form-label">Email</label><input name="email" type="email" class="form-control" value="<?= e($editDriver['email']) ?>" required></div>
          <div class="mb-3"><label class="form-label">Phone</label><input name="phone" class="form-control" value="<?= e($editDriver['phone']) ?>"></div>
          <div class="mb-3"><label class="form-label"><?= $editDriver['id'] ? 'New password (optional)' : 'Password' ?></label><input name="password" type="password" class="form-control" minlength="8" <?= $editDriver['id'] ? '' : 'required' ?> autocomplete="new-password"></div>
          <div class="mb-3"><label class="form-label">Status</label><select name="status" class="form-select"><option value="active" <?= $editDriver['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $editDriver['status'] === 'inactive' ? 'selected' : '' ?>>Disabled</option></select></div>
          <button class="btn btn-primary"><i class="bi bi-check2"></i> Save driver</button>
          <?php if ($editDriver['id']): ?><a href="<?= e(admin_url('drivers.php')) ?>" class="btn btn-link">Cancel</a><?php endif; ?>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-md-6"><label class="form-label small text-muted">Search drivers</label><input name="q" class="form-control" value="<?= e($search) ?>" placeholder="Name, email or phone"></div>
        <div class="col-md-3"><label class="form-label small text-muted">Status</label><select name="status" class="form-select"><option value="">All</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Disabled</option></select></div>
        <div class="col-md-3 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-funnel"></i> Filter</button></div>
      </form>
    </div></div>

    <div class="card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3"><h5 class="card-title mb-0">Driver accounts</h5><span class="badge text-bg-light"><?= number_format(count($drivers)) ?></span></div>
      <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Driver</th><th>Phone</th><th>Status</th><th>Created</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($drivers as $driver): ?>
          <tr>
            <td><div class="fw-semibold"><?= e($driver['name']) ?></div><small class="text-muted"><?= e($driver['email']) ?></small></td>
            <td><?= e($driver['phone'] ?: '—') ?></td>
            <td><span class="badge <?= $driver['status'] === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= ucfirst($driver['status']) ?></span></td>
            <td><small><?= e(date('M j, Y', strtotime($driver['created_at']))) ?></small></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-light" href="<?= e(admin_url('drivers.php?edit=' . (int)$driver['id'])) ?>"><i class="bi bi-pencil"></i></a>
              <form method="post" class="d-inline"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?= (int)$driver['id'] ?>"><button class="btn btn-sm btn-outline-<?= $driver['status'] === 'active' ? 'danger' : 'success' ?>" data-confirm="Change this driver account status?"><i class="bi bi-power"></i></button></form>
              <details class="d-inline-block text-start"><summary class="btn btn-sm btn-outline-secondary"><i class="bi bi-key"></i></summary><form method="post" class="border rounded p-2 mt-1 bg-body position-absolute" style="z-index:5;right:1rem;min-width:220px"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= (int)$driver['id'] ?>"><input name="password" type="password" minlength="8" class="form-control form-control-sm mb-2" placeholder="New password" required><button class="btn btn-sm btn-warning w-100">Reset password</button></form></details>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$drivers): ?><tr><td colspan="5" class="text-center text-muted py-4">No delivery drivers found.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div></div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
