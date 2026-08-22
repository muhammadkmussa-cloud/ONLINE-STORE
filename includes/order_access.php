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

/**
 * Mint an access token for an order that predates token protection.
 * Returns the one-time plaintext token, or null when the order already has
 * one (plaintext is never stored, so it cannot be re-issued here) or when
 * issuance fails. Lets legacy orders upgrade to secure links as customers
 * legitimately reach them.
 */
function order_access_token_ensure(int $orderId): ?string
{
    if ($orderId < 1 || order_has_access_token($orderId)) {
        return null;
    }
    $token = bin2hex(random_bytes(32));
    try {
        db()->prepare('INSERT INTO order_access_tokens (order_id, token_hash) VALUES (:order_id, :token_hash)')
            ->execute([':order_id' => $orderId, ':token_hash' => order_access_token_hash($token)]);
        return $token;
    } catch (Throwable $e) {
        return null;
    }
}
