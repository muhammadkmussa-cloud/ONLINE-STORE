# cPanel deployment checklist

This project is plain PHP and MySQL/MariaDB, so it can run on cPanel shared hosting.
Use PHP 8.x when available; the code remains compatible with the documented PHP 7.4+
syntax. Do not upload the repository's test data or real credentials.

## 1. Build the production package

From the developer checkout, run:

```bash
scripts/package-production.sh /tmp/bilal-store-production.zip
```

The script excludes `.git`, Git objects/refs, `.env` files, local config secrets,
private-key files, logs, development caches, the packaging script, and the
one-time installer/migration utilities. It verifies the ZIP contents before
returning. The developer checkout and its Git history are not modified.

## 2. Hosting setup

1. Create a MySQL database and a dedicated MySQL user in cPanel.
2. Grant only the required database privileges.
3. Set the PHP version and enable:
   - PDO MySQL
   - cURL
   - Fileinfo
   - JSON
   - OpenSSL
4. Upload the project into the intended document root.
5. Ensure `assets/uploads/products/` is writable by PHP, normally `755` or `775`.
6. Keep HTTPS enabled with a valid certificate.

## 3. Environment configuration

Provide the values from `.env.example` through the hosting environment or cPanel
Application Manager. At minimum set:

- `APP_ENV=production`
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `APP_TIMEZONE=Africa/Nairobi` (or the store timezone)
- `INSTALL_TOKEN` only if you use the temporary installer window from
  section 4 (`MIGRATION_TOKEN` is not needed — migrations run via phpMyAdmin)
- Daraja values only when M-Pesa is being enabled

Do not put secrets in customer-facing forms, JavaScript, Git, or activity logs.

## 4. One-time setup

The production package does **not** contain `admin/install.php` or
`admin/migrate.php` — the packaging script excludes them and refuses to build a
ZIP that includes them, and both scripts also refuse to run while
`APP_ENV=production`. Do not follow older checklists that tell you to open them
on the server.

1. Create the schema by importing the SQL files through cPanel → phpMyAdmin,
   into the database created in step 2, in this order:
   1. `sql/schema.sql`
   2. `sql/migrations.sql`
2. Create the first admin account. Pick ONE of:
   - **Temporary installer window:** upload `admin/install.php` from your
     developer checkout, temporarily set `APP_ENV=development` plus a strong
     random `INSTALL_TOKEN` in the hosting environment, open
     `admin/install.php?token=...` once, then immediately restore
     `APP_ENV=production`, remove the token, and delete the uploaded file.
   - **SQL directly:** generate a hash locally with
     `php -r "echo password_hash('YOUR-PASSWORD', PASSWORD_BCRYPT);"` and
     insert the admin row into `users` (`role='admin'`, `status='active'`).
3. Confirm the expected tables exist, including `users`, `settings`,
   `payments`, `mpesa_refunds`, `order_delivery_locations`,
   `order_delivery_pricing`, `order_pickup_snapshots`,
   `delivery_assignments`, `delivery_status_history`, `product_images`,
   `order_donations`, and `order_access_tokens`.
4. Ensure no copy of `admin/install.php` or `admin/migrate.php` remains in the
   production document root.
   > **Upgrading an existing installation:** `migrate.php` is intentionally
   > not in Git (see `.gitignore`) because it is a one-time utility. Copy it
   > from your developer checkout, run it once through the temporary
   > development window described above, then delete it. It applies
   > re-runnable SQL plus conditional column/index upgrades that
   > `CREATE TABLE IF NOT EXISTS` cannot express on existing tables.
5. Remove the temporary setup tokens from the hosting environment.
6. Log in as admin and configure payment methods, pickup, delivery pricing,
   donations, drivers, and Daraja only as needed.

## 5. M-Pesa production requirements

- Use approved production Daraja credentials.
- Use public HTTPS URLs for STK and reversal callbacks.
- Set the encrypted reversal `SecurityCredential`; never use a plaintext initiator password.
- Verify callbacks from the application logs and payment/refund history.
- Do not treat a browser success message or an accepted request as payment/refund success.

## 6. Smoke test after deployment

- Storefront home, shop search, category, product gallery, cart, and checkout load.
- Delivery and Store Pickup both render; Pickup does not request geolocation.
- Donation controls appear only when enabled.
- M-Pesa appears only when configured and enabled.
- Admin login, driver login, driver assignment, and admin order view work.
- A driver cannot open another driver's order URL.
- Public order confirmation requires its access token.
- A test image upload accepts a valid image and rejects a non-image.
- Review server logs for PHP warnings/fatal errors.
- Confirm HTTPS, Secure/HttpOnly cookies, CSRF failures, and security headers.
- Run a database backup before future migrations.
