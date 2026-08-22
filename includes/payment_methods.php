<?php
/**
 * System-wide payment method configuration.
 *
 * Checkout availability is derived from settings and provider readiness. The
 * labels and historical-method behavior live here so customer/admin pages do
 * not each maintain their own availability rules.
 */
require_once __DIR__ . '/mpesa.php';

function payment_method_definitions(): array
{
    return [
        'mpesa' => [
            'label' => 'M-Pesa',
            'setting' => 'mpesa_enabled',
            'supported' => true,
            'description' => 'Send an STK Push payment prompt to the customer.',
        ],
        'cod' => [
            'label' => 'Payment on Delivery',
            'setting' => 'cod_enabled',
            'supported' => true,
            'description' => 'Collect payment when the order is delivered.',
        ],
        'bank_transfer' => [
            'label' => 'Bank Transfer',
            'setting' => 'bank_transfer_enabled',
            'supported' => false,
            'description' => 'Reserved for a future payment integration.',
        ],
    ];
}

function payment_method_label(string $method): string
{
    $definitions = payment_method_definitions();
    return $definitions[$method]['label'] ?? ucfirst(str_replace('_', ' ', $method));
}

function payment_method_admin_enabled(string $method): bool
{
    $definitions = payment_method_definitions();
    if (!isset($definitions[$method])) return false;
    return setting($definitions[$method]['setting'], $method === 'cod' ? '1' : '0') === '1';
}

function payment_method_ready(string $method): bool
{
    $definitions = payment_method_definitions();
    if (!isset($definitions[$method]) || !$definitions[$method]['supported']) return false;
    if (!payment_method_admin_enabled($method)) return false;
    return $method === 'mpesa' ? mpesa_is_available() : true;
}

/** Methods that may actually be selected by a new checkout. */
function checkout_payment_methods(): array
{
    $available = [];
    foreach (payment_method_definitions() as $method => $definition) {
        if (payment_method_ready($method)) $available[$method] = $definition;
    }
    return $available;
}

/**
 * Validate a proposed admin configuration before saving it. At least one
 * currently supported method must remain usable. Bank Transfer is deliberately
 * retained as a disabled future method and cannot make checkout available.
 */
function payment_method_configuration_errors(
    bool $mpesaEnabled,
    bool $codEnabled,
    ?string $proposedCurrencyCode = null
): array {
    $errors = [];
    // Same predicate that governs live availability, evaluated against the
    // proposed flags — the two can no longer disagree.
    $mpesaReady = mpesa_would_be_available($mpesaEnabled, $proposedCurrencyCode);
    if (!$mpesaReady && !$codEnabled) {
        $errors[] = 'At least one available payment method must remain enabled.';
    }
    return $errors;
}

function payment_method_configuration_summary(): string
{
    $parts = [];
    foreach (payment_method_definitions() as $method => $definition) {
        $enabled = payment_method_admin_enabled($method);
        if ($method === 'bank_transfer') {
            $parts[] = $definition['label'] . ' disabled (future)';
        } else {
            $parts[] = $definition['label'] . ' ' . ($enabled ? 'enabled' : 'disabled');
        }
    }
    return implode('; ', $parts);
}
