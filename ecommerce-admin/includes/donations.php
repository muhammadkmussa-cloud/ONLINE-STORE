<?php
/** Optional charity donation configuration and order-snapshot helpers. */
require_once __DIR__ . '/functions.php';

function donation_settings(): array
{
    $enabled = setting('donations_enabled', '0') === '1';
    $presets = [];
    foreach (preg_split('/[,\s]+/', (string)setting('donation_presets', '50,100,250')) as $value) {
        if ($value === '' || !is_numeric($value)) continue;
        $amount = round((float)$value, 2);
        if ($amount > 0 && $amount <= 1000000) $presets[] = $amount;
    }
    $presets = array_values(array_unique($presets, SORT_NUMERIC));
    sort($presets, SORT_NUMERIC);
    return [
        'enabled' => $enabled,
        'charity_name' => trim((string)setting('charity_name', '')),
        'charity_description' => trim((string)setting('charity_description', '')),
        'charity_website' => trim((string)setting('charity_website', '')),
        'presets' => $presets,
    ];
}

function donation_configuration_errors(?array $settings = null): array
{
    $settings = $settings ?? donation_settings();
    if (!$settings['enabled']) return [];
    $errors = [];
    if ($settings['charity_name'] === '') $errors[] = 'Configure a charity name before enabling donations.';
    if ($settings['charity_website'] !== ''
        && !filter_var($settings['charity_website'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Charity website must be a valid URL.';
    }
    return $errors;
}

function donations_are_available(): bool
{
    $settings = donation_settings();
    return $settings['enabled'] && !donation_configuration_errors($settings);
}

function donation_amount_normalize($amount): float
{
    if ($amount === '' || $amount === null) return 0.0;
    if (!is_numeric($amount)) throw new InvalidArgumentException('Donation amount must be a valid number.');
    $amount = (float)$amount;
    if (!is_finite($amount) || $amount < 0) throw new InvalidArgumentException('Donation amount cannot be negative or invalid.');
    if ($amount > 1000000) throw new InvalidArgumentException('Donation amount is too large.');
    return round($amount, 2);
}

function order_donation_snapshot(int $orderId): ?array
{
    $stmt = db()->prepare(
        "SELECT donation_amount, currency_code, charity_name, charity_description,
                charity_website, created_at
         FROM order_donations WHERE order_id = :order_id LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    $snapshot = $stmt->fetch();
    return $snapshot ?: null;
}
