<?php
/** Secure multi-image gallery helpers. */
require_once __DIR__ . '/functions.php';

function product_image_rows(int $productId): array
{
    if ($productId < 1) return [];
    try {
        $stmt = db()->prepare(
            "SELECT id, product_id, filename, original_name, alt_text, sort_order, is_primary
             FROM product_images
             WHERE product_id = :product_id
             ORDER BY is_primary DESC, sort_order ASC, id ASC"
        );
        $stmt->execute([':product_id' => $productId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function product_primary_image(int $productId, ?string $legacyImage = null): ?string
{
    $rows = product_image_rows($productId);
    if ($rows) return $rows[0]['filename'];
    return $legacyImage ?: null;
}

function product_image_filename_is_safe(string $filename): bool
{
    return $filename !== ''
        && basename($filename) === $filename
        && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/', $filename) === 1;
}

/** Filenames for every gallery image of a product (collect before rows cascade away). */
function product_image_filenames(int $productId): array
{
    $stmt = db()->prepare('SELECT filename FROM product_images WHERE product_id = :product_id');
    $stmt->execute([':product_id' => $productId]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Remove a product image file from disk if its name is safe. */
function product_image_unlink_file(string $filename): void
{
    if (!product_image_filename_is_safe($filename)) return;
    $path = UPLOADS_PATH . '/products/' . $filename;
    if (is_file($path)) @unlink($path);
}

function product_image_upload_files(array $files): array
{
    $files = normalize_product_image_files($files);
    $stored = [];
    $errors = [];
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    $uploadDir = UPLOADS_PATH . '/products';

    if (!$files) return ['files' => [], 'errors' => []];
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) {
        return ['files' => [], 'errors' => ['The product image folder could not be created.']];
    }
    if (!is_writable($uploadDir)) {
        return ['files' => [], 'errors' => ['The product image folder is not writable by the web server.']];
    }

    foreach ($files as $file) {
        $name = (string)($file['name'] ?? 'image');
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = $name . ': ' . upload_error_message($error);
            continue;
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if (!is_uploaded_file($tmp)) {
            $errors[] = $name . ': invalid upload source.';
            continue;
        }
        if ($size < 1 || $size > 4 * 1024 * 1024) {
            $errors[] = $name . ': image must be between 1 byte and 4 MB.';
            continue;
        }

        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $tmp) : null;
            if ($finfo) finfo_close($finfo);
        }
        if (!$mime && function_exists('mime_content_type')) $mime = mime_content_type($tmp);
        $dimensions = @getimagesize($tmp);
        if (!isset($allowedMimes[$mime])) {
            $errors[] = $name . ': only JPG, PNG, GIF, and WEBP images are allowed.';
            continue;
        }
        if (!$dimensions || (int)$dimensions[0] < 1 || (int)$dimensions[1] < 1
            || (int)$dimensions[0] > 8000 || (int)$dimensions[1] > 8000) {
            $errors[] = $name . ': image dimensions must be between 1 and 8000 pixels.';
            continue;
        }

        $filename = 'pi_' . bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mime];
        if (!product_image_filename_is_safe($filename)
            || !move_uploaded_file($tmp, $uploadDir . '/' . $filename)) {
            $errors[] = $name . ': could not save the image.';
            continue;
        }
        // Safety-net registration happens after the loop (see below).
        $stored[] = [
            'filename' => $filename,
            'original_name' => substr(basename($name), 0, 255),
            'width' => (int)$dimensions[0],
            'height' => (int)$dimensions[1],
        ];
    }

    if ($stored) {
        product_images_track_pending($stored);
    }

    return ['files' => $stored, 'errors' => $errors];
}

// Pending uploads awaiting their DB insert; swept at shutdown so a crashed
// request cannot leave orphaned image files on disk.
$GLOBALS['__pending_product_images'] = [];

/**
 * Track freshly moved files. A single shutdown hook deletes anything still
 * pending when the script ends without a matching mark_committed() call.
 */
function product_images_track_pending(array $stored): void
{
    static $hookRegistered = false;
    foreach ($stored as $row) {
        $GLOBALS['__pending_product_images'][] = (string)$row['filename'];
    }
    if (!$hookRegistered) {
        $hookRegistered = true;
        register_shutdown_function(function () {
            foreach ($GLOBALS['__pending_product_images'] ?? [] as $pendingFile) {
                product_image_unlink_file((string)$pendingFile);
            }
        });
    }
}

/** Caller finished persisting these files: exempt them from the sweep. */
function product_images_mark_committed(array $stored): void
{
    $committedNames = array_column($stored, 'filename');
    $GLOBALS['__pending_product_images'] = array_values(array_diff(
        $GLOBALS['__pending_product_images'] ?? [],
        $committedNames
    ));
}

function normalize_product_image_files(array $files): array
{
    if (isset($files['name']) && is_array($files['name'])) {
        $normalized = [];
        foreach ($files['name'] as $index => $name) {
            // Skip empty slots of an unselected multiple-file input: PHP
            // still reports them as UPLOAD_ERR_NO_FILE entries, which would
            // otherwise block every save that does not add a new image.
            if ((string)$name === ''
                && (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $normalized[] = [
                'name' => $name,
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }
        return $normalized;
    }
    if (!empty($files['name'])) return [$files];
    return [];
}

function product_image_next_sort_order(int $productId): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = :id');
    $stmt->execute([':id' => $productId]);
    return (int)$stmt->fetchColumn();
}

function product_image_sync_legacy(int $productId, ?string $filename): void
{
    if ($productId < 1 || !$filename || !product_image_filename_is_safe($filename)) return;
    $stmt = db()->prepare('SELECT id FROM product_images WHERE product_id = :product_id AND filename = :filename LIMIT 1');
    $stmt->execute([':product_id' => $productId, ':filename' => $filename]);
    if (!$stmt->fetch()) {
        $hasPrimary = db()->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = :product_id AND is_primary = 1');
        $hasPrimary->execute([':product_id' => $productId]);
        $stmt = db()->prepare(
            "INSERT INTO product_images (product_id, filename, original_name, sort_order, is_primary)
             VALUES (:product_id, :filename, :original_name, :sort_order, :is_primary)"
        );
        $stmt->execute([
            ':product_id' => $productId,
            ':filename' => $filename,
            ':original_name' => $filename,
            ':sort_order' => product_image_next_sort_order($productId),
            ':is_primary' => (int)$hasPrimary->fetchColumn() === 0 ? 1 : 0,
        ]);
    }
}

function product_image_set_primary(int $productId, int $imageId): bool
{
    $stmt = db()->prepare('SELECT filename FROM product_images WHERE id = :id AND product_id = :product_id LIMIT 1');
    $stmt->execute([':id' => $imageId, ':product_id' => $productId]);
    $image = $stmt->fetch();
    if (!$image) return false;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = :product_id')->execute([':product_id' => $productId]);
        $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = :id AND product_id = :product_id')
            ->execute([':id' => $imageId, ':product_id' => $productId]);
        $pdo->prepare('UPDATE products SET image = :image WHERE id = :product_id')
            ->execute([':image' => $image['filename'], ':product_id' => $productId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function product_image_delete(int $productId, int $imageId): ?string
{
    $stmt = db()->prepare('SELECT filename, is_primary FROM product_images WHERE id = :id AND product_id = :product_id LIMIT 1');
    $stmt->execute([':id' => $imageId, ':product_id' => $productId]);
    $image = $stmt->fetch();
    if (!$image) return null;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM product_images WHERE id = :id AND product_id = :product_id')
            ->execute([':id' => $imageId, ':product_id' => $productId]);
        $remainingStmt = $pdo->prepare('SELECT id, filename FROM product_images WHERE product_id = :product_id ORDER BY sort_order ASC, id ASC LIMIT 1');
        $remainingStmt->execute([':product_id' => $productId]);
        $remaining = $remainingStmt->fetch();
        if ($remaining && (int)$image['is_primary'] === 1) {
            $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = :product_id')
                ->execute([':product_id' => $productId]);
            $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = :id AND product_id = :product_id')
                ->execute([':id' => (int)$remaining['id'], ':product_id' => $productId]);
            $pdo->prepare('UPDATE products SET image = :image WHERE id = :product_id')
                ->execute([':image' => $remaining['filename'], ':product_id' => $productId]);
        } elseif (!$remaining) {
            $pdo->prepare('UPDATE products SET image = NULL WHERE id = :product_id AND image = :image')
                ->execute([':product_id' => $productId, ':image' => $image['filename']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if (product_image_filename_is_safe($image['filename'])) {
        $path = UPLOADS_PATH . '/products/' . $image['filename'];
        if (is_file($path)) @unlink($path);
    }
    return $image['filename'];
}

function product_image_move(int $productId, int $imageId, int $direction): bool
{
    if (!in_array($direction, [-1, 1], true)) return false;
    $rows = product_image_rows($productId);
    $index = null;
    foreach ($rows as $key => $row) if ((int)$row['id'] === $imageId) $index = $key;
    if ($index === null) return false;
    $otherIndex = $index + $direction;
    if (!isset($rows[$otherIndex])) return false;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE product_images SET sort_order = :sort WHERE id = :id AND product_id = :product_id')
            ->execute([':sort' => (int)$rows[$otherIndex]['sort_order'], ':id' => $rows[$index]['id'], ':product_id' => $productId]);
        $pdo->prepare('UPDATE product_images SET sort_order = :sort WHERE id = :id AND product_id = :product_id')
            ->execute([':sort' => (int)$rows[$index]['sort_order'], ':id' => $rows[$otherIndex]['id'], ':product_id' => $productId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
