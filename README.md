# Friction Scan

Friction Scan is a PHP/SQLite MVP that scans a public URL for buyer-friction signals and stores shareable reports plus paid-fix lead requests.

## Automation And CRM

- `/admin/` is the private CRM for leads, payments, scan history, automation targets, and run history.
- `bin/run-automation.php --run` scans enabled targets, stores score deltas, and updates the CRM.
- `bin/run-automation.php --import=/path/to/targets.txt` imports one domain or URL per line.
- Paid packages use Stripe Checkout with inline prices:
  - Fix List: $49
  - Landing Fix Sprint: $249
  - Agency Partner Queue: custom review

## Required Production Config

Put these in `/home/fricscan/private/.env` or the php-fpm pool environment:

```bash
FRICTIONSCAN_PRIVATE_DIR=/home/fricscan/private
FRICTIONSCAN_BASE_URL=https://frictionscan.cc
FRICTIONSCAN_STRIPE_SECRET_KEY=sk_live_...
FRICTIONSCAN_ADMIN_SECRET=long-random-string
FRICTIONSCAN_ADMIN_PASSWORD_HASH=php-password-hash
FRICTIONSCAN_HASH_SALT=long-random-string
FRICTIONSCAN_NOTIFY_TO=you@example.com
```

Optional:

```bash
FRICTIONSCAN_STRIPE_WEBHOOK_SECRET=whsec_...
```

## Local Smoke

```bash
php -S 127.0.0.1:8787 -t public
curl -s http://127.0.0.1:8787/?health=1
```

Private data is stored in `private/frictionscan.sqlite` by default. In production set `FRICTIONSCAN_PRIVATE_DIR` outside the document root.

## Repository Safety

This repository intentionally excludes private runtime data, `.env` files, SQLite databases, smoke-test captures, and generated screenshots. Keep production secrets in `/home/fricscan/private/.env` or the service manager environment, not in git.
