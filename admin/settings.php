<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/payment_methods.php';
require_once __DIR__ . '/../includes/delivery.php';
require_once __DIR__ . '/../includes/donations.php';
require_role('admin');

$pageTitle = 'Settings';

$keys = ['site_name', 'site_email', 'site_about', 'items_per_page',
         'currency_code', 'currency_symbol', 'store_pickup_address',
         'store_pickup_instructions', 'store_latitude', 'store_longitude',
         'delivery_price_per_km', 'delivery_max_radius_km',
         'charity_name', 'charity_description',
         'charity_website', 'donation_presets'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $newMpesaEnabled = isset($_POST['mpesa_enabled']);
    $newCodEnabled = isset($_POST['cod_enabled']);
    $newCurrencyCode = trim((string)($_POST['currency_code'] ?? setting('currency_code', 'USD')));
    $paymentErrors = payment_method_configuration_errors(
        $newMpesaEnabled,
        $newCodEnabled,
        $newCurrencyCode
    );
    $newStoreLatitude = trim((string)($_POST['store_latitude'] ?? setting('store_latitude', '')));
    $newStoreLongitude = trim((string)($_POST['store_longitude'] ?? setting('store_longitude', '')));
    $newDeliveryRate = trim((string)($_POST['delivery_price_per_km'] ?? setting('delivery_price_per_km', '0.00')));
    $newMaxRadius = trim((string)($_POST['delivery_max_radius_km'] ?? setting('delivery_max_radius_km', '25')));
    $deliveryErrors = delivery_pricing_settings_errors($newStoreLatitude, $newStoreLongitude, $newDeliveryRate, $newMaxRadius);

    // Basic sanity for the remaining free-text fields.
    $settingsErrors = [];
    $newSiteEmail = trim((string)($_POST['site_email'] ?? setting('site_email', '')));
    $newCurrencyCode = strtoupper(trim((string)($_POST['currency_code'] ?? setting('currency_code', 'USD'))));
    $newCurrencySymbol = trim((string)($_POST['currency_symbol'] ?? setting('currency_symbol', '$')));
    if ($newSiteEmail !== '' && !filter_var($newSiteEmail, FILTER_VALIDATE_EMAIL)) {
        $settingsErrors[] = 'Contact email must be a valid email address.';
    }
    if (!preg_match('/^[A-Z]{3,5}$/', $newCurrencyCode)) {
        $settingsErrors[] = 'Currency code must be 3-5 letters (e.g. KES, USD).';
    }
    if (mb_strlen($newCurrencySymbol) > 5 || $newCurrencySymbol === '') {
        $settingsErrors[] = 'Currency symbol is required and must be at most 5 characters.';
    }
    $newDonationsEnabled = isset($_POST['donations_enabled']);
    $newCharityName = trim((string)($_POST['charity_name'] ?? ''));
    $newCharityDescription = trim((string)($_POST['charity_description'] ?? ''));
    $newCharityWebsite = trim((string)($_POST['charity_website'] ?? ''));
    $newDonationPresets = trim((string)($_POST['donation_presets'] ?? ''));
    $proposedDonationSettings = [
        'enabled' => $newDonationsEnabled,
        'charity_name' => $newCharityName,
        'charity_description' => $newCharityDescription,
        'charity_website' => $newCharityWebsite,
        'presets' => [],
    ];
    $donationErrors = donation_configuration_errors($proposedDonationSettings);
    if ($newCharityWebsite !== '' && !filter_var($newCharityWebsite, FILTER_VALIDATE_URL)) {
        $donationErrors[] = 'Charity website must be a valid URL.';
    }
    foreach (preg_split('/[,\s]+/', $newDonationPresets) as $preset) {
        if ($preset === '') continue;
        try {
            $presetAmount = donation_amount_normalize($preset);
            if ($presetAmount <= 0) throw new InvalidArgumentException('Donation presets must be positive.');
            $proposedDonationSettings['presets'][] = $presetAmount;
        } catch (Throwable $e) {
            $donationErrors[] = 'Donation presets must be positive valid amounts.';
            break;
        }
    }
    if ($paymentErrors || $deliveryErrors || $donationErrors || $settingsErrors) {

        foreach (array_merge($paymentErrors, $deliveryErrors, $donationErrors, $settingsErrors) as $configurationError) {
            flash('danger', $configurationError);
        }
        admin_redirect('settings.php');
    }

    $previousSummary = payment_method_configuration_summary();
    $previousDeliverySummary = 'Store ' . (string)setting('store_latitude', '') . ','
        . (string)setting('store_longitude', '') . ' @ '
        . (string)setting('delivery_price_per_km', '0.00') . '/km';
    $previousDonationSummary = donation_settings();
    $pdo = db();
    try {
        $pdo->beginTransaction();
        foreach ($keys as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            if ($k === 'items_per_page') {
                $v = (string)max(5, min(100, (int)$v));
            }
            if ($k === 'currency_code') {
                // Save the normalized uppercase form.
                $v = $newCurrencyCode;
            }
            update_setting($k, $v);
        }
        update_setting('mpesa_enabled', $newMpesaEnabled ? '1' : '0');
        update_setting('cod_enabled', $newCodEnabled ? '1' : '0');
        // Bank Transfer remains a future method and is deliberately locked off.
        update_setting('bank_transfer_enabled', '0');
        update_setting('donations_enabled', $newDonationsEnabled ? '1' : '0');
        $pdo->commit();

        $newSummary = 'M-Pesa ' . ($newMpesaEnabled ? 'enabled' : 'disabled')
            . '; Payment on Delivery ' . ($newCodEnabled ? 'enabled' : 'disabled')
            . '; Bank Transfer disabled (future)';
        log_activity(
            'settings.payment_methods',
            'Payment methods changed: ' . $previousSummary . ' → ' . $newSummary
        );
        $newDeliverySummary = 'Store ' . $newStoreLatitude . ',' . $newStoreLongitude
            . ' @ ' . $newDeliveryRate . '/km';
        if ($newDeliverySummary !== $previousDeliverySummary) {
            log_activity(
                'settings.delivery_pricing',
                'Delivery pricing changed: ' . $previousDeliverySummary . ' → ' . $newDeliverySummary
            );
        }
        $oldDonationSummary = ($previousDonationSummary['enabled'] ? 'enabled' : 'disabled')
            . ' / ' . $previousDonationSummary['charity_name'];
        $newDonationSummary = ($newDonationsEnabled ? 'enabled' : 'disabled')
            . ' / ' . $newCharityName;
        if ($newDonationSummary !== $oldDonationSummary) {
            log_activity('settings.donations', 'Donation settings changed: ' . $oldDonationSummary . ' → ' . $newDonationSummary);
        }
        flash('success', 'Settings and payment methods saved.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', APP_ENV === 'development'
            ? 'Could not save settings: ' . $e->getMessage()
            : 'Could not save settings.');
    }
    admin_redirect('settings.php');
}

$values = [];
foreach ($keys as $k) $values[$k] = setting($k, '');
$values['mpesa_enabled'] = setting('mpesa_enabled', '0');
$values['cod_enabled'] = setting('cod_enabled', '1');
$values['bank_transfer_enabled'] = setting('bank_transfer_enabled', '0');
$values['donations_enabled'] = setting('donations_enabled', '0');
$donationErrors = donation_configuration_errors();
$mpesaErrors = mpesa_configuration_errors();
$mpesaReversalErrors = mpesa_reversal_configuration_errors();

include __DIR__ . '/includes/header.php';
?>

<div class="row">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">General settings</h5>
        <p class="text-muted small">These values are stored in the <code>settings</code> table.</p>

        <form method="post" autocomplete="off">
          <?= csrf_field() ?>

          <div class="mb-3">
            <label class="form-label">Site name</label>
            <input type="text" name="site_name" class="form-control"
                   value="<?= e($values['site_name']) ?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Contact email</label>
            <input type="email" name="site_email" class="form-control"
                   value="<?= e($values['site_email']) ?>">
          </div>

          <div class="mb-3">
            <label class="form-label">About</label>
            <textarea name="site_about" class="form-control" rows="3"><?= e($values['site_about']) ?></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label">Items per page (lists)</label>
            <input type="number" min="5" max="100" name="items_per_page"
                   class="form-control" value="<?= e($values['items_per_page'] ?: '10') ?>">
            <div class="form-text">Used by user list, activity log, etc.</div>
          </div>

          <hr>
          <h6 class="mb-3"><i class="bi bi-currency-exchange"></i> E-commerce</h6>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Currency code</label>
              <input type="text" name="currency_code" maxlength="5"
                     class="form-control" value="<?= e($values['currency_code'] ?: 'USD') ?>">
              <div class="form-text">e.g. USD, EUR, GBP, PKR.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Currency symbol</label>
              <input type="text" name="currency_symbol" maxlength="5"
                     class="form-control" value="<?= e($values['currency_symbol'] ?: '$') ?>">
              <div class="form-text">e.g. $, €, £, ₨.</div>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-3">
              <label class="form-label">Store latitude</label>
              <input type="text" name="store_latitude" class="form-control"
                     value="<?= e($values['store_latitude']) ?>" placeholder="e.g. -4.043477">
            </div>
            <div class="col-md-3">
              <label class="form-label">Store longitude</label>
              <input type="text" name="store_longitude" class="form-control"
                     value="<?= e($values['store_longitude']) ?>" placeholder="e.g. 39.668206">
            </div>
            <div class="col-md-3">
              <label class="form-label">Delivery price / km</label>
              <input type="number" name="delivery_price_per_km" class="form-control"
                     min="0" step="0.01" value="<?= e($values['delivery_price_per_km'] ?: '0.00') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Max delivery radius (km)</label>
              <input type="number" name="delivery_max_radius_km" class="form-control"
                     min="1" step="1" value="<?= e($values['delivery_max_radius_km'] ?: '25') ?>">
              <div class="form-text">Locations beyond this distance are refused.</div>
            </div>
          </div>
          <?php $deliverySettings = delivery_pricing_settings(); ?>
          <?php if ($deliverySettings['ready']): ?>
            <div class="alert alert-success small">Distance pricing is active for Delivery.</div>
          <?php else: ?>
            <div class="alert alert-warning small">Distance pricing is not active. New Delivery checkout is temporarily unavailable until both store coordinates and a non-negative price/km are configured. Store Pickup remains available.</div>
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Store pickup address</label>
            <textarea name="store_pickup_address" class="form-control" rows="2"><?= e($values['store_pickup_address'] ?: 'Store pickup location to be configured.') ?></textarea>
            <div class="form-text">Captured as a snapshot on every pickup order.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Store pickup instructions</label>
            <textarea name="store_pickup_instructions" class="form-control" rows="2"><?= e($values['store_pickup_instructions'] ?: 'Collect your order from the store after confirmation.') ?></textarea>
            <div class="form-text">Shown to customers when Store Pickup is selected.</div>
          </div>

          <hr>
          <h6 class="mb-3"><i class="bi bi-heart"></i> Charity donations</h6>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch"
                   id="donations_enabled" name="donations_enabled" value="1"
                   <?= $values['donations_enabled'] === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="donations_enabled">Enable optional charity donations</label>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6"><label class="form-label">Charity name</label><input name="charity_name" class="form-control" value="<?= e($values['charity_name']) ?>" placeholder="e.g. Mombasa Community Fund"></div>
            <div class="col-md-6"><label class="form-label">Charity website</label><input type="url" name="charity_website" class="form-control" value="<?= e($values['charity_website']) ?>" placeholder="https://example.org"></div>
            <div class="col-12"><label class="form-label">Charity description</label><textarea name="charity_description" class="form-control" rows="2"><?= e($values['charity_description']) ?></textarea></div>
            <div class="col-12"><label class="form-label">Donation presets</label><input name="donation_presets" class="form-control" value="<?= e($values['donation_presets']) ?>" placeholder="50,100,250"><div class="form-text">Comma-separated positive amounts in the store currency. Customers can also enter a custom amount.</div></div>
          </div>
          <?php $donationSettings = donation_settings(); ?>
          <?php if ($donationSettings['enabled'] && !$donationErrors): ?>
            <div class="alert alert-success small">Donations are enabled for <?= e($donationSettings['charity_name']) ?>.</div>
          <?php elseif ($donationSettings['enabled']): ?>
            <div class="alert alert-warning small">Donations are enabled but need a valid charity configuration before they appear at checkout.</div>
          <?php else: ?>
            <div class="alert alert-secondary small">Donations are disabled and will not appear at checkout.</div>
          <?php endif; ?>

          <hr>
          <h6 class="mb-3"><i class="bi bi-wallet2"></i> Payment methods</h6>
          <p class="text-muted small">These settings apply to every new checkout. Historical orders retain their original payment method.</p>

          <div class="payment-method-setting border rounded p-3 mb-2">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="cod_enabled" name="cod_enabled" value="1"
                     <?= $values['cod_enabled'] === '1' ? 'checked' : '' ?>>
              <label class="form-check-label" for="cod_enabled">
                <strong><i class="bi bi-cash-stack"></i> Payment on Delivery</strong>
                <span class="d-block small text-muted">Collect payment when the order is delivered.</span>
              </label>
            </div>
          </div>

          <div class="payment-method-setting border rounded p-3 mb-2">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="mpesa_enabled" name="mpesa_enabled" value="1"
                     <?= $values['mpesa_enabled'] === '1' ? 'checked' : '' ?>>
              <label class="form-check-label" for="mpesa_enabled">
                <strong><i class="bi bi-phone"></i> M-Pesa</strong>
                <span class="d-block small text-muted">Send a server-verified STK Push payment prompt.</span>
              </label>
            </div>
          </div>

          <div class="payment-method-setting border rounded p-3 mb-3 bg-light-subtle">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="bank_transfer_enabled" value="1" disabled>
              <label class="form-check-label" for="bank_transfer_enabled">
                <strong><i class="bi bi-bank"></i> Bank Transfer</strong>
                <span class="d-block small text-muted">Coming soon — retained for historical orders but unavailable for new checkout.</span>
              </label>
            </div>
          </div>

          <div class="alert alert-info small">
            M-Pesa credentials are never stored in this database. Configure them as
            server environment variables. At least one supported method must remain enabled.
          </div>
          <?php if ($mpesaErrors): ?>
            <div class="alert alert-warning small">
              <strong>M-Pesa is not ready:</strong>
              <ul class="mb-0">
                <?php foreach ($mpesaErrors as $mpesaError): ?><li><?= e($mpesaError) ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php else: ?>
            <div class="alert alert-success small">
              Environment checks passed for <?= e(mpesa_environment_label()) ?> Daraja.
            </div>
          <?php endif; ?>

          <?php if ($mpesaErrors): ?>
            <div class="alert alert-secondary small mb-0">
              Reversal settings will be checked after the base M-Pesa configuration is complete.
            </div>
          <?php elseif ($mpesaReversalErrors): ?>
            <div class="alert alert-warning small mb-0">
              <strong>Refunds/reversals are not ready:</strong>
              <ul class="mb-0">
                <?php foreach ($mpesaReversalErrors as $reversalError): ?><li><?= e($reversalError) ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php else: ?>
            <div class="alert alert-success small mb-0">Admin M-Pesa refund/reversal checks passed.</div>
          <?php endif; ?>

          <button class="btn btn-primary"><i class="bi bi-check2"></i> Save settings</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card">
      <div class="card-body">
        <h6 class="card-title">Tips</h6>
        <ul class="small mb-0 ps-3">
          <li>The site name and logo appear in the sidebar and login screen.</li>
          <li>Set <code>currency_code</code> to <code>KES</code> before enabling M-Pesa.</li>
          <li>Configure store coordinates and price/km to activate distance pricing.</li>
          <li>Configure Daraja secrets in server environment variables, not this form.</li>
          <li>Reversal credentials require an encrypted SecurityCredential and HTTPS result URLs.</li>
          <li>Delete <code>install.php</code> after first install.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
