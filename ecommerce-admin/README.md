# Bilal Store — PHP E-commerce + Admin Panel

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5-7952B3?logo=bootstrap&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green.svg)

A small e-commerce site with a public storefront and a self-contained admin
panel, written in plain PHP 7.4+ on top of MySQL. No Composer, no framework,
no JS build step. Drop the folder into `htdocs`, run the installer, and you
have a working shop.

The codebase is deliberately flat — every page is a single PHP file you can
read top-to-bottom, which makes it easy to fork, customize, or use as a
learning reference. Around 5.2k lines of PHP and 1.2k lines of CSS at the
time of writing.

## Screenshots

Drop your screenshots into a `docs/screenshots/` folder and reference them
here. Suggested set:

```
docs/screenshots/
├── home.png
├── shop.png
├── product.png
├── cart.png
├── checkout.png
├── track-order.png
├── admin-dashboard.png
├── admin-orders.png
└── admin-products.png
```

Then add to this README:

```markdown
![Home](docs/screenshots/home.png)
![Admin dashboard](docs/screenshots/admin-dashboard.png)
```

## What's inside

**Public storefront** (root of the project)

- Landing page with hero, featured/latest products, categories, about,
  CTA banner, newsletter signup
- Product list with search, category filter, sort, pagination
- Product detail with related products
- Session-based shopping cart with quantity management and per-row removal
- Guest wishlist with save/remove/clear actions
- Recently viewed products on product detail pages
- Checkout that creates an order in a single DB transaction (also decrements
  stock atomically)
- Optional M-Pesa Daraja STK Push checkout with server-side callbacks,
  verification, idempotency, and payment status tracking
- Admin-only M-Pesa refund/reversal workflow with asynchronous callbacks,
  partial refunds, idempotency, and auditable history
- Global payment-method management: enable/disable M-Pesa and Payment on
  Delivery; Bank Transfer remains disabled as a future method
- Admin-managed Delivery Driver accounts with a separate restricted `/delivery/`
  portal, assignments, status updates, and login throttling
- Order confirmation page
- Order tracking page — lookup by order number + email
- Product reviews with star ratings and admin moderation
- Secure multiple product-image galleries with primary-image selection, ordering, and thumbnails
- Delivery or Store Pickup checkout with optional browser geolocation sharing,
  server-side coordinate validation, admin-only map access, and historical snapshots

**Admin panel** (everything under `/admin/`)

- Login + role-based access (`admin`, `editor`)
- Dashboard: order stats, revenue, pending count, low-stock alerts,
  recent orders, recent activity, Chart.js 7-day trend
- Orders: filter by status / date / search, full detail view, inline
  status updates, CSV export, M-Pesa payment status / receipt details, and
  admin-only refund/reversal history
- Products: image upload, SKU, regular + sale price, stock tracking,
  featured flag, status (active / draft / inactive)
- Reviews: search and filter submissions, approve/reject reviews, and delete
  unwanted content
- Categories: CRUD with auto-generated slugs
- Settings: currency, items per page, shipping fee, contact email,
  about copy
- Activity log
- Profile + password change
- Light/dark theme toggle (persisted in `localStorage`)

Checkout supports Cash on Delivery and Bank Transfer, plus an optional
M-Pesa Daraja STK Push integration. Other gateways can be added using the same
payment-attempt pattern (see *Extending* below).

## Stack

| Layer    | Used                                                       |
| -------- | ---------------------------------------------------------- |
| Backend  | PHP 7.4+, PDO, cURL                                        |
| Database | MySQL 5.7+ / MariaDB 10.x                                  |
| Frontend | Bootstrap 5.3, Bootstrap Icons, Chart.js (all via CDN)     |
| Server   | Anything that runs PHP. Tested on XAMPP / Apache 2.4.      |

No Node, no Composer, no migrations framework. The "migrations" are a pair
of `.sql` files and two browser-runnable scripts (`install.php`,
`migrate.php`).

## Quick start

For XAMPP on macOS / Linux / Windows:

1. Clone the repo into your `htdocs` folder. The folder name becomes the
   URL prefix:

   ```
   /Applications/XAMPP/xamppfiles/htdocs/bilal-store/
   ```

2. Start Apache and MySQL from the XAMPP control panel.

3. Database credentials are read from environment variables by
   `config/config.php` (`app_config_env()`), not hardcoded constants. The
   XAMPP defaults — host `127.0.0.1`, user `root`, empty password, database
   `php_admin_panel` — work as-is on a fresh install. To override any of them,
   set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, or `DB_PASS` in the server
   environment before PHP boots (shell export, vhost config, or whatever your
   process manager loads).

4. The one-time scripts refuse to run once the app considers itself
   production — and unset `APP_ENV` now defaults to production for safety.
   For local setup, opt into development mode plus a setup token:
   - XAMPP/Apache: add `SetEnv APP_ENV development` to your vhost config.
   - PHP built-in server: `APP_ENV=development php -S localhost:8000`
   Then set a temporary random `INSTALL_TOKEN` in the same environment and open:

   ```
   http://localhost/bilal-store/admin/install.php?token=YOUR_INSTALL_TOKEN
   ```

   Fill in admin name / email / password and submit. The installer creates
   the database, runs `sql/schema.sql`, and creates your admin user.

5. **Delete `admin/install.php`.** It's a one-time bootstrap script and
   shouldn't sit in production.

6. Set a temporary random `MIGRATION_TOKEN` in the server environment, then open:

   ```
   http://localhost/bilal-store/admin/migrate.php?token=YOUR_MIGRATION_TOKEN
   ```

   Click *Apply migration*. This adds the `products`, `categories`, `orders`,
   `order_items`, `product_reviews`, `payments`, `mpesa_refunds`,
   `order_delivery_locations`, `order_delivery_pricing`, `order_pickup_snapshots`,
   `driver_login_attempts`, `admin_login_attempts`, `delivery_assignments`,
   `delivery_status_history`, `product_images`, `order_donations`, and
   `order_access_tokens` tables. Delete the file when done.

7. Optional: configure M-Pesa. Copy `.env.example` to your deployment secret
   store and provide the Daraja credentials, a long random callback token, and
   a public **HTTPS** `MPESA_CALLBACK_URL` pointing to `mpesa_callback.php`.
   Use `MPESA_TRANSACTION_TYPE=CustomerPayBillOnline` for a PayBill or
   `CustomerBuyGoodsOnline` for a Till, with `MPESA_PARTY_B` set when needed.
   The PHP cURL extension must be enabled. In admin → Settings, set the
   currency code to `KES` and enable *Show M-Pesa at checkout* only after the
   environment checks pass. Use `MPESA_ENVIRONMENT=sandbox` for testing and
   `production` only with approved production Daraja credentials.

8. You're up:

   - Storefront: `http://localhost/bilal-store/`
   - Admin: `http://localhost/bilal-store/admin/login.php`
   - M-Pesa callback: `https://your-public-domain.example/mpesa_callback.php?token=…`

If you skip step 6 the dashboard will show a warning and still load — it
just won't have any e-commerce data to show.

## Project layout

```
bilal-store/
├── .htaccess
├── README.md
├── CONTRIBUTING.md
├── LICENSE
│
├── index.php                    # Storefront landing
├── shop.php                     # Product list w/ filters
├── product.php                  # Product detail
├── category.php                 # Category page
├── cart.php                     # Shopping cart
├── wishlist.php                 # Guest wishlist
├── checkout.php                 # Checkout form + order create
├── order_confirmation.php       # Thank-you page
├── mpesa_wait.php               # M-Pesa payment status page
├── mpesa_callback.php           # Daraja STK callback endpoint
├── mpesa_reversal_callback.php  # Daraja reversal callback endpoint
├── track.php                    # Order tracking lookup
├── delivery/
│   ├── login.php                # Driver login
│   ├── index.php                # Assigned delivery dashboard
│   ├── order.php                # Own assigned delivery detail
│   ├── logout.php
│   └── includes/                # Driver-only auth and portal shell
├── _product_card.php            # Reusable card partial
│
├── admin/
│   ├── index.php                # Redirects to login or dashboard
│   ├── login.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── orders.php
│   ├── order_view.php
│   ├── reviews.php
│   ├── drivers.php
│   ├── products.php
│   ├── product_form.php
│   ├── categories.php
│   ├── category_form.php
│   ├── activity.php
│   ├── settings.php
│   ├── profile.php
│   ├── install.php              # Delete after first run
│   ├── migrate.php              # Delete after running migrations
│   └── includes/
│       ├── auth.php             # Login guard + role helpers
│       ├── header.php           # Admin shell (topbar + sidebar)
│       ├── sidebar.php
│       └── footer.php
│
├── includes/
│   ├── functions.php            # Shared: e(), price(), redirect(), CSRF, …
│   ├── shop_bootstrap.php       # Storefront helpers + cart functions
│   ├── payment_methods.php      # Global checkout payment configuration
│   ├── delivery.php             # Delivery/pickup and location helpers
│   ├── mpesa.php                # Daraja client + callback/payment helpers
│   ├── shop_header.php
│   └── shop_footer.php
│
├── config/
│   ├── config.php               # DB creds + app constants
│   └── database.php             # PDO connection
│
├── sql/
│   ├── schema.sql               # Initial schema
│   └── migrations.sql           # Adds e-commerce/payment tables (idempotent)
├── .env.example                 # Server-side Daraja configuration template
│
└── assets/
    ├── css/
    │   ├── style.css            # Admin styles
    │   └── shop.css             # Storefront styles
    ├── js/script.js             # Sidebar toggle + theme toggle
    └── uploads/                 # User uploads (avatars, product images)
        └── products/
```

## How it's wired

There's no router. Every URL maps directly to a `.php` file. Each page
generally follows the same pattern:

1. `require_once __DIR__ . '/config/config.php';`
   (this calls `session_start()` and connects to the DB via
   `config/database.php`)
2. PHP at the top — handle POST, load data
3. `include 'includes/.../header.php';`
4. The HTML markup
5. `include 'includes/.../footer.php';`

Storefront pages pull `includes/shop_bootstrap.php`, which sets up the
shop helpers (`shop_url()`, `cart_load()`, `wishlist_load()`,
`product_effective_price()`, `generate_order_number()`, …). Admin pages pull
`admin/includes/auth.php`, which calls `require_login()` /
`require_role(...)` and exposes `current_user()`.

**CSRF.** Every POST form embeds `<?= csrf_field() ?>` (a hidden `_csrf`
input). Every POST handler calls `require_csrf()` before doing anything.
The token regenerates on login.

**URLs.** Two helpers in `includes/functions.php`:

- `url('shop.php')` builds a URL relative to the project root.
- `admin_url('orders.php')` builds a URL relative to `/admin/`.

Same split for `redirect()` vs `admin_redirect()`. Use whichever matches
the page you're linking *to*.

**`BASE_URL` auto-detection.** `config/config.php` figures out the
project's URL prefix from `$_SERVER['SCRIPT_NAME']`, including when the
request is for `/admin/...`. That means the same config works whether the
project lives at `/bilal-store/` or at the document root.

## Settings

Settings live in a `settings` table as key/value rows and are edited from
admin → Settings. Defaults shipped:

| Key               | Purpose                                            |
| ----------------- | -------------------------------------------------- |
| `site_name`       | Navbar brand, page titles, transactional messages  |
| `site_about`      | Footer description, default meta description       |
| `site_email`      | Public contact email                               |
| `currency_code`   | ISO code (`USD`, `PKR`, …)                         |
| `currency_symbol` | What gets prefixed to prices (`$`, `Rs`, …)        |
| `shipping_fee`    | Flat fee added at checkout. Set `0.00` for free.   |
| `items_per_page`  | Pagination size for product/category/order lists   |
| `mpesa_enabled`         | Admin toggle for the optional M-Pesa checkout       |
| `cod_enabled`           | Admin toggle for Payment on Delivery                 |
| `bank_transfer_enabled` | Reserved future setting; current checkout keeps it off |
| `store_pickup_instructions` | Instructions shown for Store Pickup              |
| `store_latitude`            | Store latitude used for distance pricing           |
| `store_longitude`           | Store longitude used for distance pricing          |
| `delivery_price_per_km`     | Delivery rate used for distance pricing            |

Payment method changes are audited in `activity_log` and apply to new
checkouts immediately. Existing orders keep their saved `payment_method`.

Read with `setting('shipping_fee', '0')`. Write through the settings page,
or directly via SQL.

## M-Pesa / Daraja integration

The optional M-Pesa method uses Safaricom Daraja's Lipa na M-Pesa Online
(STK Push) flow. `includes/mpesa.php` uses PHP cURL to request a server-side
OAuth token, initiate the STK prompt, and process the HTTPS callback at
`mpesa_callback.php`.

Security and payment-state rules:

- Consumer key, consumer secret, shortcode, passkey, callback URL, and callback
  token are read from environment variables; they are never saved in `settings`.
- The checkout option is shown only when an admin enables it, the store currency
  is `KES`, cURL is available, and all required environment values pass checks.
- Orders reserve stock and create an `initiated` payment row before the external
  request. A unique checkout idempotency key prevents a double-submit from
  creating a second order or STK prompt.
- Only a valid server callback with matching checkout ID, amount, phone number,
  and a new provider receipt can transition a payment to `successful`.
- Failed, cancelled, or expired callbacks release the reserved stock once and
  cancel the still-pending order. A browser page never marks a payment as paid.

For production, use a public HTTPS callback URL, approved production Daraja
credentials, a strong random callback token, server logs/monitoring, and a
reconciliation process for payments whose callback is delayed.

### Refunds and reversals

Admin users can request full or partial reversals from an eligible successful
M-Pesa order. Reversal credentials are separate environment values:
`MPESA_INITIATOR_NAME`, an encrypted `MPESA_SECURITY_CREDENTIAL`,
`MPESA_REVERSAL_RESULT_URL`, and `MPESA_REVERSAL_TIMEOUT_URL`. The request is
stored in `mpesa_refunds` before the provider call, claimed atomically to avoid
duplicate requests, and only becomes `refunded` after a verified reversal
callback. Provider/network uncertainty is recorded as `unknown` or
`requires_review`, never as a successful refund.

### Delivery locations

Checkout supports Delivery and Store Pickup. Delivery customers may use the
browser Geolocation API; denial, timeout, and unavailable-location errors fall
back to the manually entered address. Coordinates are range-validated on the
server and stored in the `order_delivery_locations` snapshot table. Pickup
address and instructions are preserved in `order_pickup_snapshots`. Store
Pickup never requests or stores coordinates. Public confirmation/tracking pages
show the fulfillment method but not coordinates; only administrators can open
the location map from the admin order view.

### Distance-based delivery pricing

Set `store_latitude`, `store_longitude`, and `delivery_price_per_km` in
Admin → Settings to activate server-side distance pricing. The checkout uses
the Haversine calculation between the store and shared customer coordinates.
The actual distance is rounded up with `ceil(actual_distance_km)` for billing,
then the billable distance, rate, currency, and fee are stored in the immutable
`order_delivery_distance_pricing` snapshot. Store Pickup always has a zero fee. If the
store coordinates or rate are not configured, new Delivery checkout is blocked
with a clear configuration message; the old `shipping_fee` is not used as a
silent fallback.

### Delivery driver portal

Admins create Delivery Driver accounts from Admin → Delivery drivers. Drivers do
not self-register and cannot access `/admin/`; they sign in at `/delivery/login.php`.
Only active assignments are visible in the driver portal. Driver views expose
only the assigned customer, phone, delivery address, package contents,
payment status, relevant notes, and authorized map location—never payment
controls, refund controls, product, settings, or other-driver data. Drivers can
progress deliveries through Assigned, Picked Up, Out for Delivery, Arrived,
Delivered, and Unable to Deliver. Login failures are throttled and driver
account/status changes are audited.

## Automated regression tests

Run the dependency-free critical business/security suite against a dedicated
throwaway database:

```bash
TEST_DB_NAME=php_admin_panel_test php tests/run.php
```

The suite never calls real M-Pesa endpoints and drops only its dedicated test
database. See [`tests/README.md`](tests/README.md) for configuration.

## Demo data seeder

For local development and manual testing you can populate the store with a
large, realistic dataset:

```bash
php scripts/seed_demo_data.php
```

The script **wipes and replaces** demo tables (categories, products, orders,
reviews, delivery assignments, newsletter subscribers — plus generated product
gallery images under `assets/uploads/products/`) and then inserts:

- 8 categories and ~64 products (mix of active/draft/inactive, sale prices,
  featured flags, 2–3 generated gallery images each)
- 40 COD orders spread over the last 30 days across every status, with order
  items, delivery/pickup snapshots, distance-pricing rows, access tokens, and
  driver assignments + status history where appropriate
- 45 product reviews in approved/pending/rejected states
- Distance-pricing settings so Delivery checkout works immediately

It also resets these accounts on every run:

| Account | Email | Password |
| ------- | ----- | -------- |
| Admin   | `admin@alasusa.test` | `Admin@12345` |
| Drivers | `driver1@alasusa.test` … `driver3@alasusa.test` | `Driver@12345` |

Seeded orders use deterministic tracking tokens:
`seedtok-<order id>-<substr(sha256('seed<id>'),0,24)>`, e.g.
`/track.php?order=ORD-202608-0001&email=<customer email>&token=seedtok-1-…`.
The script prints example tokens when it finishes.

Never run the seeder against production; it truncates data.

## cPanel deployment

See [`docs/cpanel-deployment.md`](docs/cpanel-deployment.md) for the complete
cPanel/PHP/MySQL setup, environment, one-time migration, HTTPS callback, backup,
and smoke-test checklist.

## Production hardening

Set `APP_ENV=production` and provide database credentials through the server
environment. The production configuration enables secure HttpOnly SameSite
cookies, strict session mode, security headers, and hides PHP errors. The
one-time installer and migration runner require separate random environment
tokens and should be deleted after use. Build cPanel packages with
`scripts/package-production.sh`; it excludes `.git`, Git metadata, local secrets,
private keys, logs, caches, and setup utilities. New public order links use strong
access tokens instead of predictable order numbers alone. Admin and driver
login attempts are throttled, logout is POST-only with CSRF, and all privileged
payment, refund, delivery, donation, driver, and image actions are audited.

## Default credentials

The first admin is whatever you entered into `install.php`. There's no
second admin and no UI to add more — we removed user management to keep
the surface area small for a single-merchant shop.

To reset a forgotten password:

```php
<?php
require __DIR__ . '/config/config.php';
$hash = password_hash('newpassword', PASSWORD_BCRYPT);
db()->prepare('UPDATE users SET password = ? WHERE email = ?')
    ->execute([$hash, 'admin@example.com']);
```

Or via SQL with a hash you've generated separately:

```sql
UPDATE users
SET password = '$2y$10$...replace-with-real-hash...'
WHERE email = 'admin@example.com';
```

## Extending the project

A few common things you might want to do:

**Add an admin page.** Create `admin/your_page.php`:

```php
<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');           // or 'admin', 'editor'

$pageTitle = 'Your page';

// load data, handle POST, etc.

include __DIR__ . '/includes/header.php';
?>
<!-- your markup -->
<?php include __DIR__ . '/includes/footer.php'; ?>
```

Then add a link in `admin/includes/sidebar.php`.

**Add a storefront page.** Same idea but pull
`includes/shop_bootstrap.php` and the `shop_header.php` /
`shop_footer.php` includes. No auth call needed.

**Add a database table.** Append a `CREATE TABLE IF NOT EXISTS` block to
`sql/migrations.sql` (always use `IF NOT EXISTS` so re-running is safe),
then run `admin/migrate.php` from the browser.

**Plug in another payment gateway.** Follow the M-Pesa pattern in
`checkout.php`: create the order, line-item snapshots, stock reservation, and
payment attempt in one transaction; commit; then call the provider. Store the
provider reference and transition the payment only from a server-side callback
or verification response. Do not mark an order paid from browser input.

**i18n.** Strings are inline. There's no gettext or translation table. If
you need multiple languages, the cleanest path is to wrap user-facing
text in a `t('...')` helper backed by a static array per locale.

## Common gotchas

**"Could not save image" on product upload.** On macOS XAMPP, Apache runs
as the `daemon` user, but `assets/uploads/` is probably owned by your
local user. Give ownership to the web-server user instead of opening the
folder to the world:

```bash
sudo chown -R daemon assets/uploads      # XAMPP on macOS (www-data on Linux)
chmod -R 775 assets/uploads
```

World-writable (`777`) upload folders let any local account plant or tamper
with files — avoid them even locally.

**Migration won't run.** The script splits `sql/migrations.sql` on
semicolons and runs each statement separately. If it fails partway
through and you've fixed the SQL, re-run it — every statement uses
`IF NOT EXISTS` or `ON DUPLICATE KEY UPDATE`.

**`/admin/` returns 403.** Make sure `admin/index.php` exists. It's the
directory's entry point and redirects to `login.php` or `dashboard.php`
based on session state.

**Sessions expire mid-session.** `config/config.php` sets hardened cookie
params but does not change `session.gc_maxlifetime` (your PHP default
applies — typically 24 minutes of GC inactivity). Separately, the app
enforces its own idle timeouts: two hours without a request for staff and
driver portals (`require_login()` / `require_driver_login()`). Adjust those
constants if your shop needs longer idle windows.

**Styling looks off.** Hard-refresh (Cmd-Shift-R / Ctrl-F5). The two CSS
files are not versioned and your browser caches them aggressively.

## Going to production

The project ships set up for local development. Before deploying:

1. Switch to a dedicated MySQL user (not `root`) with only the privileges
   this database actually needs.
2. Set `APP_ENV=production` in the **server environment** (cPanel env vars,
   Apache `SetEnv`, or your process manager). `config/config.php` reads it via
   `app_config_env()` — there is no literal to flip inside the file. This hides
   PHP error output from visitors and arms production-only safeguards such as
   the installer lockout and HSTS.
3. Serve over HTTPS. HSTS is sent automatically once `APP_ENV=production` and
   HTTPS are both active (`config/config.php` sends
   `Strict-Transport-Security`); layer any stricter policies at the web-server
   level if you need them.
4. Confirm `install.php` and `migrate.php` are deleted from `admin/`.
5. If your host conflates document root with writable storage, move
   `assets/uploads/` to a writable, non-executable path and update
   `UPLOADS_PATH` in `config/config.php`.

## Contributing

PRs welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for the full guide.
TL;DR:

- One feature/fix per PR.
- Match existing style (4-space indent, single-quoted strings,
  `snake_case` for SQL columns).
- Test against PHP 7.4 *and* PHP 8.x.
- Don't add a build step or a framework dependency without opening an
  issue first.

Bug reports go in GitHub Issues with PHP version, MySQL version, browser,
and steps to reproduce.

## License

[MIT](LICENSE). Copy, modify, sell, sublicense — just keep the copyright
notice.
