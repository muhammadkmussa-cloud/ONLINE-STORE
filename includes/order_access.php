<?php
/** Strong, non-enumerable public access tokens for new orders. */
require_once __DIR__ . '/functions.php';

function order_access_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function order_access_token_valid(int $orderId, string $token): bool
{
    if ($orderId < 1 || strlen($token) < 32) return false;
    $stmt = db()->prepare(
        'SELECT id FROM order_access_tokens WHERE order_id = :order_id AND token_hash = :token_hash LIMIT 1'
    );
    $stmt->execute([':order_id' => $orderId, ':token_hash' => order_access_token_hash($token)]);
    if (!$stmt->fetch()) return false;
    db()->prepare('UPDATE order_access_tokens SET last_used_at = UTC_TIMESTAMP() WHERE order_id = :order_id')
        ->execute([':order_id' => $orderId]);
    return true;
}

function order_has_access_token(int $orderId): bool
{
    $stmt = db()->prepare('SELECT id FROM order_access_tokens WHERE order_id = :order_id LIMIT 1');
    $stmt->execute([':order_id' => $orderId]);
    return (bool)$stmt->fetch();
}

function order_public_link(string $path, string $orderNumber, string $token): string
{
    return url($path . '?o=' . rawurlencode($orderNumber) . '&t=' . rawurlencode($token));
}
