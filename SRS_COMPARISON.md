# SRS Compliance Comparison

**Compared against:** `SRS-ONLINE-STORE.md`  
**Project:** Bilal Store PHP E-commerce + Admin Panel  
**Review date:** 21 August 2026  
**Status:** Implementation review after the requested feature work and local integration testing

## Status legend

- **Implemented** — present in the codebase and covered by local tests.
- **Implemented with deployment/configuration dependency** — code exists, but requires hosting credentials, HTTPS, or an external provider to complete production verification.
- **Partial / follow-up recommended** — the main behavior exists, but one or more SRS details remain incomplete.
- **Not found** — no corresponding implementation was found.

## Executive summary

The project covers the majority of the SRS scope. The storefront, admin portal, M-Pesa payment/refund flows, payment-method management, delivery/pickup, distance pricing, donations, driver accounts/assignments, image galleries, order privacy, and security hardening are implemented in the current workspace.

The remaining SRS risks are mostly deployment or scope details rather than missing core workflows:

1. Pickup hours are not configurable; pickup address and instructions are configurable.
2. New public order links use strong access tokens, but legacy orders created before the token migration can still use the older order-number-plus-email tracking fallback.
3. A real Safaricom Daraja transaction and a real cPanel account were not available in the sandbox; provider callbacks and local hosting behavior were tested instead.
4. PHP stores financial values in MySQL `DECIMAL` columns, but some existing application calculations use PHP floats before rounding. A full integer-cents money refactor would be required for strict compliance with the SRS wording about never using floating point for currency.
5. There is a cPanel deployment checklist, but no cPanel hosting environment is available for live deployment verification.

---

## Requirement matrix

| SRS section | Requirement area | Status | Evidence / implementation |
|---|---|---:|---|
| 3.1 | Customer role and storefront-only access | **Implemented** | Public storefront pages; no admin/driver session access required. |
| 3.2 | Administrator role and `/admin` portal | **Implemented** | `admin/includes/auth.php`, role checks on admin routes, admin navigation. |
| 3.3 | Delivery Driver role and `/delivery` portal | **Implemented** | `delivery_driver` role migration, `delivery/login.php`, `delivery/index.php`, ownership queries. |
| 4 | Storefront, categories, product detail, cart, checkout, confirmation | **Implemented** | `index.php`, `shop.php`, `category.php`, `product.php`, `cart.php`, `checkout.php`, `order_confirmation.php`. |
| 5.1 | Product fields, price, stock, category, availability | **Implemented** | `admin/product_form.php`, server-side validation, product status and stock checks. |
| 5.2 | Multiple product-image storage | **Implemented** | `product_images` table, migration preservation of legacy `products.image`, `includes/product_images.php`. |
| 5.2 | Multiple image upload | **Implemented** | Multi-file upload in `admin/product_form.php`. |
| 5.2 | Delete, primary selection, and reorder | **Implemented** | `image_delete`, `image_primary`, and `image_move` actions with CSRF and role checks. |
| 5.2 | Customer gallery/thumbnails | **Implemented** | Main image plus thumbnail navigation in `product.php`; gallery CSS/JavaScript. |
| 5.2 | Upload MIME, extension, size, dimensions, safe filenames | **Implemented** | `product_image_upload_files()`, `finfo`, `getimagesize`, 4 MB/8000 px limits, randomized filenames, safe filename checks, upload `.htaccess`. |
| 6 | Cart add/remove/quantity management | **Implemented** | Session cart helpers and `cart.php`. |
| 6 | Server-side stock/price/product validation | **Implemented** | `cart_load()`, checkout revalidation, current product hydration. |
| 6 | Atomic inventory decrement | **Implemented** | Conditional `UPDATE products SET stock = stock - ... WHERE stock >= ...` inside the checkout transaction. |
| 7 | Delivery and Store Pickup checkout | **Implemented** | Fulfillment controls in `checkout.php`. |
| 7 | Server-authoritative final total | **Implemented** | Delivery quote and donation are recomputed before order insertion; client totals are display-only. |
| 8 | Globally configurable payment methods | **Implemented** | `includes/payment_methods.php`, Admin → Settings payment method controls. |
| 8 | M-Pesa and Payment on Delivery | **Implemented** | Dynamic checkout visibility and server-side allow-list. |
| 8 | Bank Transfer retained but disabled for current checkout | **Implemented** | Historical label support; locked future-method setting; not rendered in new checkout. |
| 8 | At least one payment method must remain enabled | **Implemented** | `payment_method_configuration_errors()` blocks disabling all supported methods. |
| 8 | Historical payment method preservation | **Implemented** | Settings changes affect new checkouts; order `payment_method` snapshots remain unchanged. |
| 9 | Server-side Daraja authentication and requests | **Implemented with deployment dependency** | `includes/mpesa.php`, OAuth, STK Push, cURL/TLS validation, env-only credentials. |
| 9 | M-Pesa payment states | **Implemented** | `initiated`, `pending`, `successful`, `failed`, `cancelled`, `expired`. |
| 9 | Callback verification and idempotency | **Implemented** | Callback token, request IDs, amount/phone/receipt checks, unique IDs, row locks. |
| 9 | Customer/admin payment result UI | **Implemented** | `mpesa_wait.php`, order confirmation, tracking, admin order view. |
| 9 | Live Safaricom transaction | **Not locally verifiable** | No live Daraja credentials or real Safaricom account were available; controlled callback/state tests passed. |
| 10 | Admin-only refund/reversal | **Implemented with deployment dependency** | Admin-only `request_refund`, CSRF, reversal API, `mpesa_refunds`. |
| 10 | Refund lifecycle and history | **Implemented** | `requested`, `processing`, `refunded`, `failed`, `unknown`, `requires_review`, callback history, manual resolution. |
| 10 | Refund idempotency/original-payment preservation | **Implemented** | Unique refund keys, payment row locks, separate refund table, original payment unchanged. |
| 11 | Delivery/Pickup fulfillment method | **Implemented** | `delivery.php`, checkout radio controls, `order_delivery_locations`. |
| 11 | Browser location sharing and graceful errors | **Implemented** | Geolocation JavaScript handles denied, unavailable, timeout, unsupported browser; Delivery is blocked when pricing requires a coordinate and the customer can choose Pickup. |
| 11 | Location confirmation/map | **Implemented** | Coordinate preview and OpenStreetMap link; admin/driver map access. |
| 11 | Private coordinates | **Implemented** | Public pages query snapshots without coordinates; admin/assigned driver only for map access. |
| 12 | Store coordinates/rate per km | **Implemented** | Admin settings and `delivery_pricing_settings()`. |
| 12 | Server Haversine distance and delivery fee | **Implemented** | `delivery_distance_km()`, `delivery_billable_distance_km()` using `ceil(actual)`, `delivery_calculate_quote()`, and immutable pricing snapshots. |
| 12 | Store rate/fee/distance historical snapshot | **Implemented** | `order_delivery_pricing` stores mode, store/customer coordinates, distance, rate, fee, currency, timestamp. |
| 12 | Missing delivery-pricing configuration | **Implemented** | Delivery checkout is blocked with a clear message; `shipping_fee` is not used as a silent fallback. Store Pickup remains available. |
| 13 | Store Pickup, zero fee, no coordinates, no driver | **Implemented** | Pickup clears/ignores coordinates, uses zero fee, creates `order_pickup_snapshots`, blocks assignment. |
| 13 | Pickup address and instructions | **Implemented** | Admin settings and immutable `order_pickup_snapshots`; customer confirmation/tracking display. |
| 13 | Optional pickup hours | **Partial** | Address and instructions exist; pickup hours are not currently configurable. |
| 14 | Admin-created driver accounts | **Implemented** | `admin/drivers.php`; no self-registration route. |
| 14 | Driver password hashing/status/timestamps | **Implemented** | bcrypt hashes, active/inactive status, users timestamps. |
| 14 | Separate driver authentication | **Implemented** | `delivery/includes/auth.php`, separate `driver` session, role checks on every request. |
| 14 | Disabled driver rejection | **Implemented** | Login validation and per-request active-status verification. |
| 15 | Driver assigned-order dashboard | **Implemented** | `delivery/index.php` only joins active assignments for current driver. |
| 15 | Driver customer contact/call action | **Implemented** | `tel:` customer phone link. |
| 15 | Driver map/address/items/quantities/payment status/fee/notes | **Implemented** | `delivery/order.php`; no payment/refund controls exposed. |
| 15 | Customer collection workflow | **Implemented** | Driver portal explains Arrived → call customer → customer collects goods. |
| 16 | Admin assignment/reassignment/history | **Implemented** | `admin/order_view.php`, `delivery_assignments`, assignment history and audit logs. |
| 16 | Required delivery statuses | **Implemented** | Assigned, Picked Up, Out for Delivery, Arrived, Delivered, Unable to Deliver. |
| 16 | Driver status authorization/audit | **Implemented** | Own assignment query, CSRF, status history, changed-by driver, activity log. |
| 17 | Optional donation controls/admin configuration | **Implemented** | Donation settings, charity configuration, presets/custom amount. |
| 17 | Donation server validation/disabled rejection | **Implemented** | `donation_amount_normalize()`, disabled-request rejection, max/negative checks. |
| 17 | Subtotal + delivery + donation total | **Implemented** | Donation added before final order/M-Pesa amount calculation. |
| 17 | Charity/amount historical snapshot | **Implemented** | `order_donations` preserves amount and charity identity/details. |
| 18 | Complete order snapshot | **Implemented** | Order, item, fulfillment, pricing, donation, payment, refund, driver and status records. |
| 18 | Stable historical financial values | **Implemented in storage** | Order totals/fees/rates/donations/payment history are stored separately from current settings. |
| 19 | Non-enumerable new public order access | **Implemented for new orders** | Random 32-byte token, SHA-256 stored hash, token required on confirmation/payment pages. |
| 19 | Legacy order privacy | **Partial** | Legacy orders without an access-token row retain the older order-number-plus-email tracking fallback. They are not accessible by order number alone, but a token migration/secure re-issue process would provide stricter uniformity. |
| 20 | Password hashing/session regeneration/role checks | **Implemented** | Admin and driver auth middleware, bcrypt, session regeneration, ownership checks. |
| 20 | CSRF and safe logout | **Implemented** | POST + CSRF admin/driver logout and state-changing forms. |
| 20 | Login rate limiting | **Implemented** | `admin_login_attempts`, `driver_login_attempts`, 5-fail/15-minute throttle. |
| 20 | Secure cookies/HTTPS production behavior | **Implemented** | `config/config.php`, Secure/HttpOnly/SameSite, strict sessions, HSTS. |
| 21 | Public setup/destructive utility hardening | **Implemented** | Installer/migrator tokens, CSRF, production lockout; no destructive seed scripts found. |
| 22 | Safe migrations, FK/indexes, unique constraints | **Implemented** | Idempotent `CREATE IF NOT EXISTS`, `INSERT ... SELECT` image preservation, FKs/indexes/unique keys. |
| 22 | Fixed-precision financial storage | **Implemented in database; PHP calculation caveat** | Monetary columns use `DECIMAL`; some pre-existing and new PHP calculations use rounded floats before persistence. |
| 23 | External integrations/timeouts/error handling | **Implemented with deployment dependency** | cURL timeouts/TLS/error handling, callback guards, local Haversine fallback. Live provider/service failure testing is simulated locally. |
| 24 | Admin orders/products/drivers/payment/delivery/donation/audit sections | **Implemented** | Admin navigation and pages cover the requested management areas. |
| 25 | Customer/location privacy by role | **Implemented** | Public pages omit coordinates and private driver queries enforce ownership. |
| 26 | File-upload security | **Implemented** | MIME/dimension/size validation, generated names, upload `.htaccess`, primary/gallery controls. |
| 27 | cPanel/PHP/MySQL deployment | **Implemented as deployment package/documentation** | `docs/cpanel-deployment.md`, env configuration, `.htaccess`, PHP/MySQL smoke-tested locally. |
| 27 | Actual cPanel host deployment | **Not locally verifiable** | No cPanel account/environment is available in the workspace. |
| 28 | Security/performance/reliability/maintainability/usability | **Mostly implemented** | Modular helpers, transactions, server validation, mobile Bootstrap UI; live production load testing remains outstanding. |
| 29 | Safe customer errors/no secret leakage | **Implemented** | Production error suppression, sanitized customer messages, no password/API-secret logging. |
| 30 | Functional/security testing | **Implemented locally** | Integration/regression scripts and HTTP smoke tests executed against PHP 8.4/MariaDB. No committed PHPUnit suite currently exists. |
| 31 | Backup before migrations/data preservation | **Implemented operationally** | `mysqldump --single-transaction` backup was created before final migration reruns; test data cleaned. |
| 32 | Recommended implementation order | **Completed** | Features were layered through migrations and regression-tested after integration. |
| 33 | Definition of Done | **Mostly met** | Core requirements pass; pickup hours, legacy token uniformity, live cPanel/provider verification, and strict integer-cents refactor remain follow-up items. Delivery no-fallback behavior is implemented and regression-tested. |

---

## Security and privacy findings

### Satisfied

- Admin and driver authentication are separate.
- Driver pages enforce role and assignment ownership on every request.
- Driver payment/refund controls are absent.
- Admin-only refund, assignment, settings, image, and driver operations use CSRF and role checks.
- Public order confirmation/payment access uses strong tokens for new orders.
- Public pages do not render delivery coordinates.
- Upload paths reject traversal and executable extensions.
- Installer/migrator are locked by environment tokens and production mode.
- Session fixation is addressed with regeneration; production cookies are Secure, HttpOnly, SameSite and strict.
- Inventory decrement uses an atomic conditional update inside a transaction.

### Follow-up risks

1. **Legacy public-order access:** old orders created before `order_access_tokens` do not have raw tokens to distribute. The current fallback requires order number plus checkout email; it should be replaced or supplemented with a secure re-issue flow before a strict privacy audit.
2. **Floating-point money calculations:** storage is fixed-precision `DECIMAL`, but PHP calculations still use rounded floats in legacy cart/price paths. A strict financial audit would introduce integer cents or a decimal-money helper across all monetary calculations.
3. **Live integration verification:** no real Daraja credentials, real payment, refund, or cPanel host were available. Local callbacks, failure states, idempotency, and database transitions were tested.
4. **Pickup hours:** address/instructions are present, but hours are not modeled.
5. **Load testing:** concurrency was tested at the atomic stock-update level; full browser-level load testing has not been run.

---

## Test evidence

The workspace was tested with PHP 8.4 and MariaDB 11.8. The final local checks included:

- PHP lint across the complete current PHP file set.
- JavaScript syntax validation for the checkout location/donation logic.
- Migration backup with `mysqldump --single-transaction` before migration reruns.
- Idempotent migration reruns and table verification.
- Product browsing/category/search HTTP smoke tests.
- Product gallery upload, MIME rejection, traversal filename normalization, primary selection, reorder, delete, and legacy-image preservation tests.
- Cart/checkout delivery and pickup tests.
- Delivery-pricing-without-fallback tests: missing store coordinates/rate blocks Delivery and does not create an order.
- Distance and delivery-fee tests.
- Donation enabled, disabled, snapshot, and manipulated-request tests.
- Payment-method enabled/disabled configuration tests.
- M-Pesa success/cancelled/duplicate callback tests and refund state tests.
- Driver creation/login/disabled/rate-limit/assignment/status/authorization tests.
- Order access-token privacy tests.
- Admin/driver frontend render tests.
- Installer/migration no-token guard tests.
- Production cookie/error-mode checks.
- PHP built-in HTTP server checks with no warning/fatal entries in the server log.

The test database was restored to its normal configuration and all test records/files were removed.

## Recommendation before production sign-off

The implementation is suitable for final staging review after:

1. Configure and validate a real cPanel environment.
2. Configure approved production Daraja credentials and test with a controlled low-value transaction.
3. Decide whether pickup hours are required for the first release.
4. Add a secure legacy-order token re-issue flow.
5. Complete an integer-cents/decimal-money refactor if the SRS financial arithmetic requirement is interpreted strictly.
6. Add a committed PHPUnit/integration test suite so regression coverage is repeatable after deployment.
