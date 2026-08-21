-- =======================================================
--  E-commerce migration — safe to re-run.
--  Creates: categories, products, orders, order_items, product_reviews, payments,
--           mpesa_refunds, order_delivery_locations, pricing, drivers, assignments.
--  Adds:    currency settings.
-- =======================================================

-- ---------------------------------------------------------
-- Categories
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(150) NOT NULL,
  `slug`        VARCHAR(160) NOT NULL UNIQUE,
  `description` TEXT         DEFAULT NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_categories_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Products
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `category_id`      INT UNSIGNED  DEFAULT NULL,
  `name`             VARCHAR(200)  NOT NULL,
  `slug`             VARCHAR(220)  NOT NULL UNIQUE,
  `sku`              VARCHAR(80)   DEFAULT NULL,
  `short_description` VARCHAR(500) DEFAULT NULL,
  `description`      TEXT          DEFAULT NULL,
  `price`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `sale_price`       DECIMAL(10,2) DEFAULT NULL,
  `stock`            INT           NOT NULL DEFAULT 0,
  `image`            VARCHAR(255)  DEFAULT NULL,
  `status`           ENUM('active','inactive','draft') NOT NULL DEFAULT 'active',
  `featured`         TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_status`   (`status`),
  KEY `idx_products_featured` (`featured`),
  CONSTRAINT `fk_products_category`
      FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Make SKU unique only when not null.
-- (Older MySQL versions don't support partial indexes, so we use a regular UNIQUE.)
-- We intentionally don't add a UNIQUE constraint on SKU so blank SKUs are allowed.

-- ---------------------------------------------------------
-- Orders
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `orders` (
  `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `order_number`      VARCHAR(40)   NOT NULL UNIQUE,
  `customer_name`     VARCHAR(150)  NOT NULL,
  `customer_email`    VARCHAR(150)  NOT NULL,
  `customer_phone`    VARCHAR(40)   DEFAULT NULL,
  `shipping_address`  TEXT          NOT NULL,
  `shipping_city`     VARCHAR(100)  DEFAULT NULL,
  `shipping_zip`      VARCHAR(20)   DEFAULT NULL,
  `shipping_country`  VARCHAR(100)  DEFAULT NULL,
  `subtotal`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `shipping_fee`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total`             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method`    VARCHAR(40)   NOT NULL DEFAULT 'cod',
  `status`            ENUM('pending','processing','shipped','completed','cancelled')
                      NOT NULL DEFAULT 'pending',
  `notes`             TEXT          DEFAULT NULL,
  `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_orders_status`     (`status`),
  KEY `idx_orders_email`      (`customer_email`),
  KEY `idx_orders_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Order items (line items, with product snapshot)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_items` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `order_id`       INT UNSIGNED  NOT NULL,
  `product_id`     INT UNSIGNED  DEFAULT NULL,
  `product_name`   VARCHAR(200)  NOT NULL,
  `product_sku`    VARCHAR(80)   DEFAULT NULL,
  `product_image`  VARCHAR(255)  DEFAULT NULL,
  `unit_price`     DECIMAL(10,2) NOT NULL,
  `quantity`       INT           NOT NULL,
  `line_total`     DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_oi_order`   (`order_id`),
  KEY `idx_oi_product` (`product_id`),
  CONSTRAINT `fk_oi_order`   FOREIGN KEY (`order_id`)   REFERENCES `orders`(`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_oi_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Default settings additions
-- ---------------------------------------------------------
INSERT INTO `settings` (`key_name`, `value`) VALUES
  ('currency_code',   'USD'),
  ('currency_symbol', '$'),
  ('shipping_fee',          '0.00'),
  ('mpesa_enabled',         '0'),
  ('cod_enabled',           '1'),
  ('bank_transfer_enabled', '0'),
  ('store_pickup_address',    'Store pickup location to be configured.'),
  ('store_pickup_instructions', 'Collect your order from the store after confirmation.'),
  ('store_latitude',         ''),
  ('store_longitude',        ''),
  ('delivery_price_per_km',  '0.00'),
  ('donations_enabled',      '0'),
  ('charity_name',            ''),
  ('charity_description',    ''),
  ('charity_website',        ''),
  ('donation_presets',       '50,100,250')
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;

-- ---------------------------------------------------------
-- Product reviews
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_reviews` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`     INT UNSIGNED NOT NULL,
  `customer_name`  VARCHAR(100) NOT NULL,
  `customer_email` VARCHAR(150) DEFAULT NULL,
  `rating`         TINYINT UNSIGNED NOT NULL,
  `title`          VARCHAR(200) DEFAULT NULL,
  `body`           TEXT NOT NULL,
  `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reviews_product_status` (`product_id`, `status`),
  KEY `idx_reviews_status` (`status`),
  CONSTRAINT `fk_reviews_product`
      FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Payment attempts (currently used by M-Pesa STK Push)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id`                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`                 INT UNSIGNED NOT NULL,
  `provider`                 VARCHAR(30) NOT NULL,
  `idempotency_key`          CHAR(64) NOT NULL,
  `amount`                   DECIMAL(12,2) NOT NULL,
  `currency_code`            VARCHAR(5) NOT NULL,
  `customer_phone`           VARCHAR(20) DEFAULT NULL,
  `status`                   ENUM('initiated','pending','successful','failed','cancelled','expired')
                             NOT NULL DEFAULT 'initiated',
  `merchant_request_id`      VARCHAR(100) DEFAULT NULL,
  `checkout_request_id`      VARCHAR(100) DEFAULT NULL,
  `provider_transaction_id`  VARCHAR(100) DEFAULT NULL,
  `provider_response_code`   VARCHAR(30) DEFAULT NULL,
  `provider_result_code`     VARCHAR(30) DEFAULT NULL,
  `failure_reason`           TEXT DEFAULT NULL,
  `callback_payload`         LONGTEXT DEFAULT NULL,
  `initiated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`             DATETIME DEFAULT NULL,
  `expires_at`               DATETIME DEFAULT NULL,
  `stock_released_at`        DATETIME DEFAULT NULL,
  `created_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_idempotency` (`idempotency_key`),
  UNIQUE KEY `uq_payments_checkout_request` (`checkout_request_id`),
  UNIQUE KEY `uq_payments_provider_transaction` (`provider_transaction_id`),
  KEY `idx_payments_order` (`order_id`),
  KEY `idx_payments_status` (`status`),
  CONSTRAINT `fk_payments_order`
      FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- M-Pesa refund / reversal history
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mpesa_refunds` (
  `id`                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id`                 BIGINT UNSIGNED NOT NULL,
  `order_id`                   INT UNSIGNED NOT NULL,
  `provider`                   VARCHAR(30) NOT NULL DEFAULT 'mpesa',
  `idempotency_key`            CHAR(64) NOT NULL,
  `original_transaction_id`    VARCHAR(100) NOT NULL,
  `amount`                     DECIMAL(12,2) NOT NULL,
  `currency_code`              VARCHAR(5) NOT NULL,
  `reason`                     TEXT NOT NULL,
  `status`                     ENUM('requested','processing','successful','refunded',
                                    'failed','unknown','requires_review')
                               NOT NULL DEFAULT 'requested',
  `admin_user_id`              INT UNSIGNED DEFAULT NULL,
  `originator_conversation_id` VARCHAR(100) DEFAULT NULL,
  `conversation_id`            VARCHAR(100) DEFAULT NULL,
  `provider_response_code`     VARCHAR(30) DEFAULT NULL,
  `provider_result_code`       VARCHAR(30) DEFAULT NULL,
  `provider_transaction_id`    VARCHAR(100) DEFAULT NULL,
  `provider_result_desc`       TEXT DEFAULT NULL,
  `failure_reason`             TEXT DEFAULT NULL,
  `callback_payload`           LONGTEXT DEFAULT NULL,
  `requested_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processing_at`              DATETIME DEFAULT NULL,
  `completed_at`               DATETIME DEFAULT NULL,
  `created_at`                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refunds_idempotency` (`idempotency_key`),
  KEY `idx_refunds_payment_status` (`payment_id`, `status`),
  KEY `idx_refunds_order` (`order_id`),
  KEY `idx_refunds_originator` (`originator_conversation_id`),
  KEY `idx_refunds_conversation` (`conversation_id`),
  CONSTRAINT `fk_refunds_payment`
      FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_refunds_order`
      FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_refunds_admin`
      FOREIGN KEY (`admin_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Delivery method and location snapshot per order
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_delivery_locations` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`        INT UNSIGNED NOT NULL,
  `delivery_method` ENUM('delivery','pickup') NOT NULL DEFAULT 'delivery',
  `latitude`        DECIMAL(10,7) DEFAULT NULL,
  `longitude`       DECIMAL(10,7) DEFAULT NULL,
  `accuracy_meters` DECIMAL(10,2) DEFAULT NULL,
  `location_source` VARCHAR(30) DEFAULT NULL,
  `captured_at`     DATETIME DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_location_order` (`order_id`),
  KEY `idx_delivery_method` (`delivery_method`),
  CONSTRAINT `fk_delivery_location_order`
      FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Immutable distance-pricing snapshot per order
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_delivery_pricing` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`          INT UNSIGNED NOT NULL,
  `pricing_mode`      ENUM('distance','flat','pickup') NOT NULL,
  `store_latitude`    DECIMAL(10,7) DEFAULT NULL,
  `store_longitude`   DECIMAL(10,7) DEFAULT NULL,
  `customer_latitude` DECIMAL(10,7) DEFAULT NULL,
  `customer_longitude` DECIMAL(10,7) DEFAULT NULL,
  `distance_km`       DECIMAL(12,4) DEFAULT NULL,
  `rate_per_km`       DECIMAL(12,4) DEFAULT NULL,
  `delivery_fee`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency_code`     VARCHAR(5) NOT NULL,
  `calculated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_pricing_order` (`order_id`),
  KEY `idx_delivery_pricing_mode` (`pricing_mode`),
  CONSTRAINT `fk_delivery_pricing_order`
      FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Immutable Store Pickup snapshot per pickup order
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_pickup_snapshots` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`           INT UNSIGNED NOT NULL,
  `pickup_address`     TEXT NOT NULL,
  `pickup_instructions` TEXT NOT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pickup_snapshot_order` (`order_id`),
  CONSTRAINT `fk_pickup_snapshot_order`
      FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Delivery drivers and secure login throttling
-- ---------------------------------------------------------
ALTER TABLE `users`
  MODIFY `role` ENUM('admin','editor','user','delivery_driver') NOT NULL DEFAULT 'user';

CREATE TABLE IF NOT EXISTS `driver_login_attempts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`        VARCHAR(150) NOT NULL,
  `ip_address`   VARCHAR(45) DEFAULT NULL,
  `successful`   TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_driver_login_rate` (`email`, `ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Assignment history for delivery orders
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `delivery_assignments` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      INT UNSIGNED NOT NULL,
  `driver_id`     INT UNSIGNED NOT NULL,
  `assigned_by`   INT UNSIGNED DEFAULT NULL,
  `status`        ENUM('assigned','completed','unassigned') NOT NULL DEFAULT 'assigned',
  `assigned_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`  DATETIME DEFAULT NULL,
  `unassigned_at` DATETIME DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_assignment_order_status` (`order_id`, `status`),
  KEY `idx_assignment_driver_status` (`driver_id`, `status`),
  CONSTRAINT `fk_assignment_order`
      FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assignment_driver`
      FOREIGN KEY (`driver_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_assignment_admin`
      FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Driver fulfillment status history
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `delivery_status_history` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assignment_id` BIGINT UNSIGNED NOT NULL,
  `order_id`      INT UNSIGNED NOT NULL,
  `driver_id`     INT UNSIGNED NOT NULL,
  `status`        ENUM('assigned','picked_up','out_for_delivery','arrived','delivered','unable_to_deliver') NOT NULL,
  `note`          TEXT DEFAULT NULL,
  `changed_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_delivery_status_assignment` (`assignment_id`, `created_at`),
  KEY `idx_delivery_status_order` (`order_id`, `created_at`),
  CONSTRAINT `fk_delivery_status_assignment`
      FOREIGN KEY (`assignment_id`) REFERENCES `delivery_assignments`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_delivery_status_order`
      FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_delivery_status_driver`
      FOREIGN KEY (`driver_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_delivery_status_changed_by`
      FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Multiple product images
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_images` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`  INT UNSIGNED NOT NULL,
  `filename`    VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) DEFAULT NULL,
  `alt_text`    VARCHAR(255) DEFAULT NULL,
  `sort_order`  INT NOT NULL DEFAULT 0,
  `is_primary`  TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_image_filename` (`filename`),
  KEY `idx_product_images_product_order` (`product_id`, `sort_order`),
  CONSTRAINT `fk_product_images_product`
      FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve each existing single product image as its first primary gallery image.
INSERT INTO `product_images` (`product_id`, `filename`, `original_name`, `sort_order`, `is_primary`)
SELECT p.id, p.image, p.image, 0, 1
FROM products p
LEFT JOIN product_images pi ON pi.product_id = p.id
WHERE p.image IS NOT NULL AND p.image <> '' AND pi.id IS NULL;

-- ---------------------------------------------------------
-- Optional charity donation order snapshot
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_donations` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`            INT UNSIGNED NOT NULL,
  `donation_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency_code`       VARCHAR(5) NOT NULL,
  `charity_name`        VARCHAR(200) DEFAULT NULL,
  `charity_description` TEXT DEFAULT NULL,
  `charity_website`     VARCHAR(500) DEFAULT NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_donation_order` (`order_id`),
  KEY `idx_order_donation_amount` (`donation_amount`),
  CONSTRAINT `fk_order_donation_order`
      FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Non-enumerable public order access tokens
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_access_tokens` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      INT UNSIGNED NOT NULL,
  `token_hash`    CHAR(64) NOT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_access_order` (`order_id`),
  UNIQUE KEY `uq_order_access_hash` (`token_hash`),
  CONSTRAINT `fk_order_access_order`
      FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Admin login throttling
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_login_attempts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`        VARCHAR(150) NOT NULL,
  `ip_address`   VARCHAR(45) DEFAULT NULL,
  `successful`   TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_login_rate` (`email`, `ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Authoritative billable-distance pricing snapshot
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_delivery_distance_pricing` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`             INT UNSIGNED NOT NULL,
  `pricing_mode`         ENUM('distance','pickup') NOT NULL,
  `store_latitude`       DECIMAL(10,7) DEFAULT NULL,
  `store_longitude`      DECIMAL(10,7) DEFAULT NULL,
  `customer_latitude`    DECIMAL(10,7) DEFAULT NULL,
  `customer_longitude`   DECIMAL(10,7) DEFAULT NULL,
  `actual_distance_km`   DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  `billable_distance_km` INT UNSIGNED NOT NULL DEFAULT 0,
  `rate_per_km`          DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  `delivery_fee`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency_code`        VARCHAR(5) NOT NULL,
  `calculated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_distance_order` (`order_id`),
  CONSTRAINT `fk_delivery_distance_order`
      FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
