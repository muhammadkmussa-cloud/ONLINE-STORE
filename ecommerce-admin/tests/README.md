# Automated regression tests

Run the dependency-free integration suite from the project root:

```bash
TEST_DB_NAME=php_admin_panel_test php tests/run.php
```

The runner:

- Creates a dedicated test database.
- Applies `sql/schema.sql` and `sql/migrations.sql`.
- Exercises product images, cart totals, atomic stock protection, delivery/pickup,
  distance pricing, donation validation, payment methods, mocked M-Pesa callbacks,
  refund idempotency, order access tokens, driver ownership, and CSRF rules.
- Drops the dedicated test database at the end.

It refuses production-like database names such as `php_admin_panel`, `prod`, or
`production`. Set `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_ADMIN_USER`, and
`TEST_DB_ADMIN_PASS` when the test database administrator is not the local root
user. The suite never calls a real M-Pesa endpoint.

The broader deployment smoke checks used during integration also run PHP lint,
JavaScript syntax checks, upload HTTP checks, and the production-package script.
