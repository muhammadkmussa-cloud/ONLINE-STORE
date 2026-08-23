# Client Setup Checklist

Complete checklist for deploying this application for a new client.
Everything maps to real configuration surfaces in the codebase.

---

## Phase 0 — Prerequisites

- [ ] Client's own **M-Pesa Paybill/Till shortcode** + Daraja credentials
      (never reuse another business's shortcode or keys).
- [ ] Hosting with **Apache + PHP 8.x + MySQL**
      (cPanel path documented in `cpanel-deployment.md`) or nginx —
      `tests/run.php` self-guards against web execution on any stack.
- [ ] Valid TLS certificate installed for the client's domain.

## Phase 1 — Environment variables

Copy `.env.example` values into the server environment (PHP-FPM pool,
Apache SetEnv, or system-wide). Never commit real secrets.

| Variable | Action |
|---|---|
| `APP_ENV` | `production` in live. Development must opt in explicitly. |
| `APP_TIMEZONE` | Client's timezone (default `Africa/Nairobi`). |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | **Create a dedicated least-privilege MySQL user** (SELECT/INSERT/UPDATE/DELETE only). Never run production as `root`. |
| `INSTALL_TOKEN`, `MIGRATION_TOKEN` | Long random one-time tokens (`php -r "echo bin2hex(random_bytes(32));"`). |
| `MPESA_ENVIRONMENT` | `sandbox` while testing → `production` at go-live. |
| `MPESA_CONSUMER_KEY` / `MPESA_CONSUMER_SECRET` | Client's Daraja app credentials. |
| `MPESA_SHORTCODE` / `MPESA_PARTY_B` | Client's paybill/till number. |
| `MPESA_TRANSACTION_TYPE` | `CustomerPayBillOnline` (paybill) or `CustomerBuyGoodsOnline` (till). |
| `MPESA_PASSKEY` | Client's passkey for the chosen environment. |
| `MPESA_CALLBACK_URL` | `https://<client-domain>/mpesa_callback.php`. |
| `MPESA_CALLBACK_TOKEN` | Fresh ≥16-char random token **per client**. |
| `MPESA_INITIATOR_NAME` / `MPESA_SECURITY_CREDENTIAL` | Only if admin refunds are wanted. Credential must be encrypted with Safaricom's public certificate — never a plaintext password. |
| `MPESA_REVERSAL_RESULT_URL` / `MPESA_REVERSAL_TIMEOUT_URL` / `MPESA_REVERSAL_CALLBACK_TOKEN` | Same per-client treatment. |

## Phase 2 — Database

- [ ] Create an empty database, then import
      `sql/schema.sql` followed by `sql/migrations.sql`.
- [ ] **Applying the migration is mandatory before launch**: the public
      order-tracking throttle fails closed, so a missing
      `tracking_attempts` table disables order tracking entirely.
- [ ] Run `install.php` once in the browser to create the client's admin
      account with their own strong password.
- [ ] **Delete `install.php` and `migrate.php` immediately afterwards.**

## Phase 3 — Daraja portal (client's account)

- [ ] Register both callback URLs against the client's domain
      (token appended by `mpesa_callback_url()` automatically).
- [ ] If refunds/reversals are wanted: register reversal result/timeout
      URLs and generate the SecurityCredential with Safaricom's cert.
- [ ] Confirm the store currency is set to **KES** in Admin → Settings
      before enabling M-Pesa (the application enforces this).

## Phase 4 — Admin Settings UI

Rebrand and configure the business rules through Admin → Settings:

- [ ] Site name, contact email, about text (rendered across storefront,
      admin sidebar, and login screens).
- [ ] Currency code + symbol (e.g. `KES` / `KSh `).
- [ ] Payment methods: Payment-on-Delivery and/or M-Pesa
      (at least one must remain enabled).
- [ ] Delivery pricing: store latitude/longitude (client's physical
      location), price per km, maximum delivery radius in km.
- [ ] Store-pickup address + pickup instructions.
- [ ] Charity donations: fully configured **or left disabled** —
      presets, charity name, description, website URL.
- [ ] Items-per-page preference.

## Phase 5 — Content & accounts

- [ ] Import the client's categories, products, and product images
      (uploads live under `assets/uploads/products/`).
- [ ] Create delivery-driver accounts under Admin → Drivers
      (minimum password length is 10 characters).
- [ ] Verify the site email is a mailbox someone actually monitors.

## Phase 6 — Deployment hygiene

- [ ] Ship using `scripts/package-production.sh` — it excludes
      `.git`, `.env`, key files, `tests/`, and the install/migration
      utilities, and verifies none of them leaked into the archive.
      Never deploy a raw copy of the repository.
- [ ] `assets/uploads/products/` writable by PHP (755 or 775),
      script execution disabled.
- [ ] Confirm all shipped `.htaccess` files made it to the server
      (they guard `config/`, `sql/`, `includes/`, `tests/`).

## Phase 7 — Go-live smoke test

- [ ] HTTPS loads cleanly; cookies arrive `Secure` + `HttpOnly`;
      security headers present (`X-Content-Type-Options`,
      `X-Frame-Options`, `Referrer-Policy`, HSTS in production).
- [ ] End-to-end payment test in sandbox: STK push arrives → customer
      pays → callback marks the order paid server-side.
- [ ] Abandoned prompt expires (~2 min window) and releases reserved stock.
- [ ] Order tracking works for customers; rapid guessing trips the
      per-IP throttle (20 failed lookups / 15 min) with a friendly message.
- [ ] Driver workflow: assignment → picked up → delivered updates the
      order status end-to-end.
- [ ] CSV export opens correctly in Excel/Google Sheets
      (spreadsheet formula injection guard prefixes risky cells).

---

*Keep this checklist with the project. Tick items off per client during
onboarding so nothing ships half-configured.*
