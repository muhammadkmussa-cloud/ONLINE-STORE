<?php
/** Delivery method and order-location helpers. */
require_once __DIR__ . '/functions.php';

function delivery_method_definitions(): array
{
    return [
        'delivery' => [
            'label' => 'Delivery',
            'description' => 'Deliver to the address and optional shared location below.',
        ],
        'pickup' => [
            'label' => 'Store Pickup',
            'description' => 'Collect your order from the store after confirmation.',
        ],
    ];
}

function delivery_method_label(string $method): string
{
    $definitions = delivery_method_definitions();
    return $definitions[$method]['label'] ?? ucfirst($method);
}

function delivery_method_is_valid(string $method): bool
{
    return isset(delivery_method_definitions()[$method]);
}

function delivery_location_is_valid($latitude, $longitude): bool
{
    return is_numeric($latitude) && is_numeric($longitude)
        && (float)$latitude >= -90 && (float)$latitude <= 90
        && (float)$longitude >= -180 && (float)$longitude <= 180;
}

function delivery_map_url($latitude, $longitude): string
{
    if (!delivery_location_is_valid($latitude, $longitude)) return '#';
    $lat = number_format((float)$latitude, 7, '.', '');
    $lng = number_format((float)$longitude, 7, '.', '');
    return 'https://www.openstreetmap.org/?mlat=' . rawurlencode($lat)
        . '&mlon=' . rawurlencode($lng) . '#map=16/'
        . rawurlencode($lat) . '/' . rawurlencode($lng);
}

/** Load a delivery snapshot. Coordinates are returned only when explicitly requested. */
function order_delivery_snapshot(int $orderId, bool $includeCoordinates = false): ?array
{
    $columns = 'delivery_method, accuracy_meters, location_source, captured_at';
    if ($includeCoordinates) $columns .= ', latitude, longitude';
    $stmt = db()->prepare(
        "SELECT $columns FROM order_delivery_locations
         WHERE order_id = :order_id LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    $snapshot = $stmt->fetch();
    return $snapshot ?: null;
}

function delivery_pricing_settings(): array
{
    $storeLatitude = setting('store_latitude', '');
    $storeLongitude = setting('store_longitude', '');
    $rateRaw = setting('delivery_price_per_km', '0.00');
    $rate = is_numeric($rateRaw) ? (float)$rateRaw : -1.0;
    return [
        'store_latitude' => $storeLatitude,
        'store_longitude' => $storeLongitude,
        'rate_per_km' => $rate,
        'ready' => delivery_location_is_valid($storeLatitude, $storeLongitude) && $rate >= 0,
    ];
}

function delivery_pricing_settings_errors(
    string $storeLatitude,
    string $storeLongitude,
    string $ratePerKm
): array {
    $errors = [];
    $hasLat = trim($storeLatitude) !== '';
    $hasLng = trim($storeLongitude) !== '';
    if ($hasLat !== $hasLng) {
        $errors[] = 'Provide both store latitude and store longitude, or leave both blank.';
    } elseif ($hasLat && !delivery_location_is_valid($storeLatitude, $storeLongitude)) {
        $errors[] = 'Store latitude must be between -90 and 90 and longitude between -180 and 180.';
    }
    if ($ratePerKm === '' || !is_numeric($ratePerKm) || (float)$ratePerKm < 0) {
        $errors[] = 'Delivery price per kilometer must be zero or a positive amount.';
    }
    return $errors;
}

function delivery_distance_km(float $storeLatitude, float $storeLongitude, float $customerLatitude, float $customerLongitude): float
{
    if (!delivery_location_is_valid($storeLatitude, $storeLongitude)
        || !delivery_location_is_valid($customerLatitude, $customerLongitude)) {
        throw new InvalidArgumentException('Invalid delivery coordinates.');
    }

    $earthRadiusKm = 6371.0088;
    $latDelta = deg2rad($customerLatitude - $storeLatitude);
    $lngDelta = deg2rad($customerLongitude - $storeLongitude);
    $a = sin($latDelta / 2) ** 2
        + cos(deg2rad($storeLatitude)) * cos(deg2rad($customerLatitude))
        * sin($lngDelta / 2) ** 2;
    $a = min(1.0, max(0.0, $a));
    $distance = 2 * $earthRadiusKm * asin(sqrt($a));
    if (!is_finite($distance) || $distance < 0 || $distance > 20040) {
        throw new InvalidArgumentException('Calculated delivery distance is impossible.');
    }
    return round($distance, 4);
}

function delivery_billable_distance_km(float $actualDistanceKm): int
{
    if (!is_finite($actualDistanceKm) || $actualDistanceKm < 0 || $actualDistanceKm > 20040) {
        throw new InvalidArgumentException('Calculated delivery distance is impossible.');
    }
    return (int)ceil($actualDistanceKm);
}

/**
 * Produce the authoritative delivery quote. Client-provided distance and fee
 * values are deliberately not accepted by this function.
 */
function delivery_calculate_quote(string $deliveryMethod, $customerLatitude = null, $customerLongitude = null): array
{
    if (!delivery_method_is_valid($deliveryMethod)) {
        throw new InvalidArgumentException('Invalid delivery method.');
    }
    $pricing = delivery_pricing_settings();
    if ($deliveryMethod === 'pickup') {
        return [
            'pricing_mode' => 'pickup',
            'store_latitude' => $pricing['ready'] ? (float)$pricing['store_latitude'] : null,
            'store_longitude' => $pricing['ready'] ? (float)$pricing['store_longitude'] : null,
            'customer_latitude' => null,
            'customer_longitude' => null,
            'distance_km' => 0.0,
            'actual_distance_km' => 0.0,
            'billable_distance_km' => 0,
            'rate_per_km' => $pricing['rate_per_km'] >= 0 ? $pricing['rate_per_km'] : 0.0,
            'delivery_fee' => 0.0,
            'currency_code' => strtoupper((string)setting('currency_code', 'USD')),
        ];
    }

    if (!$pricing['ready']) {
        throw new InvalidArgumentException(
            'Delivery is temporarily unavailable because distance-based delivery pricing has not been configured.'
        );
    }

    if (!delivery_location_is_valid($customerLatitude, $customerLongitude)) {
        throw new InvalidArgumentException('Share a valid delivery location to calculate the delivery fee.');
    }
    $distance = delivery_distance_km(
        (float)$pricing['store_latitude'],
        (float)$pricing['store_longitude'],
        (float)$customerLatitude,
        (float)$customerLongitude
    );
    $billableDistance = delivery_billable_distance_km($distance);
    return [
        'pricing_mode' => 'distance',
        'store_latitude' => (float)$pricing['store_latitude'],
        'store_longitude' => (float)$pricing['store_longitude'],
        'customer_latitude' => (float)$customerLatitude,
        'customer_longitude' => (float)$customerLongitude,
        'distance_km' => $distance,
        'actual_distance_km' => $distance,
        'billable_distance_km' => $billableDistance,
        'rate_per_km' => round($pricing['rate_per_km'], 4),
        'delivery_fee' => round($billableDistance * $pricing['rate_per_km'], 2),
        'currency_code' => strtoupper((string)setting('currency_code', 'USD')),
    ];
}

function order_delivery_pricing_snapshot(int $orderId, bool $includeCoordinates = false): ?array
{
    try {
        $columns = 'pricing_mode, actual_distance_km, billable_distance_km,
                    rate_per_km, delivery_fee, currency_code, calculated_at';
        if ($includeCoordinates) {
            $columns .= ', store_latitude, store_longitude, customer_latitude, customer_longitude';
        }
        $stmt = db()->prepare(
            "SELECT $columns FROM order_delivery_distance_pricing
             WHERE order_id = :order_id LIMIT 1"
        );
        $stmt->execute([':order_id' => $orderId]);
        if ($snapshot = $stmt->fetch()) {
            $snapshot['distance_km'] = $snapshot['actual_distance_km'];
            return $snapshot;
        }
    } catch (Throwable $e) {
        // Fall through for orders created before the authoritative table existed.
    }

    $columns = 'pricing_mode, distance_km, rate_per_km, delivery_fee, currency_code, calculated_at';
    if ($includeCoordinates) {
        $columns .= ', store_latitude, store_longitude, customer_latitude, customer_longitude';
    }
    $stmt = db()->prepare(
        "SELECT $columns FROM order_delivery_pricing
         WHERE order_id = :order_id LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    $snapshot = $stmt->fetch();
    if (!$snapshot) return null;
    $snapshot['actual_distance_km'] = $snapshot['distance_km'];
    $snapshot['billable_distance_km'] = null;
    return $snapshot;
}

function order_pickup_snapshot(int $orderId): ?array
{
    $stmt = db()->prepare(
        "SELECT pickup_address, pickup_instructions, created_at
         FROM order_pickup_snapshots
         WHERE order_id = :order_id LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    $snapshot = $stmt->fetch();
    return $snapshot ?: null;
}

function delivery_status_definitions(): array
{
    return [
        'assigned' => ['label' => 'Assigned', 'class' => 'text-bg-secondary'],
        'picked_up' => ['label' => 'Picked Up', 'class' => 'text-bg-info'],
        'out_for_delivery' => ['label' => 'Out for Delivery', 'class' => 'text-bg-primary'],
        'arrived' => ['label' => 'Arrived', 'class' => 'text-bg-warning'],
        'delivered' => ['label' => 'Delivered', 'class' => 'text-bg-success'],
        'unable_to_deliver' => ['label' => 'Unable to Deliver', 'class' => 'text-bg-danger'],
    ];
}

function delivery_status_label(string $status): string
{
    $definitions = delivery_status_definitions();
    return $definitions[$status]['label'] ?? ucfirst(str_replace('_', ' ', $status));
}

function delivery_status_class(string $status): string
{
    $definitions = delivery_status_definitions();
    return $definitions[$status]['class'] ?? 'text-bg-secondary';
}

function delivery_status_is_valid(string $status): bool
{
    return isset(delivery_status_definitions()[$status]);
}

function delivery_current_status(int $assignmentId, string $fallback = 'assigned'): string
{
    try {
        $stmt = db()->prepare(
            "SELECT status FROM delivery_status_history
             WHERE assignment_id = :assignment_id
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':assignment_id' => $assignmentId]);
        return (string)($stmt->fetchColumn() ?: $fallback);
    } catch (Throwable $e) {
        return $fallback;
    }
}

function delivery_status_history(int $assignmentId): array
{
    $stmt = db()->prepare(
        "SELECT h.*, u.name AS changed_by_name
         FROM delivery_status_history h
         LEFT JOIN users u ON u.id = h.changed_by
         WHERE h.assignment_id = :assignment_id
         ORDER BY h.id DESC"
    );
    $stmt->execute([':assignment_id' => $assignmentId]);
    return $stmt->fetchAll();
}

function record_delivery_status(
    PDO $pdo,
    int $assignmentId,
    int $orderId,
    int $driverId,
    string $status,
    ?int $changedBy = null,
    string $note = ''
): void {
    if (!delivery_status_is_valid($status)) {
        throw new InvalidArgumentException('Invalid delivery status.');
    }
    $pdo->prepare(
        "INSERT INTO delivery_status_history
          (assignment_id, order_id, driver_id, status, note, changed_by)
         VALUES (:assignment_id, :order_id, :driver_id, :status, :note, :changed_by)"
    )->execute([
        ':assignment_id' => $assignmentId,
        ':order_id' => $orderId,
        ':driver_id' => $driverId,
        ':status' => $status,
        ':note' => $note !== '' ? substr($note, 0, 2000) : null,
        ':changed_by' => $changedBy,
    ]);
}
