# ForkPOS

**POS Awesome V15, rewritten in Laravel** — a lightweight point-of-sale for
[ERPNext](https://erpnext.com). A web register (Blade) with an API-first REST
layer, backed by SQLite (or MySQL). No Frappe framework.

`Laravel 13 · PHP 8.4 · SQLite / MySQL · Sanctum · Blade + Tailwind (CDN)` · GPL-3.0

## Features

- **Web POS register** — item grid/list, search + barcode, categories, cart with
  per-line price & discounts, whole-sale discount, payments, change and a
  printable receipt.
- **Credit sales** (7 / 14 / 30-day terms or a due date, down payments, overdue
  tracking), **returns** priced from the original sale, **saved drafts**, and
  audited **sale cancellation** with stock restore.
- **Credit collect** — unpaid credit invoices from *every* van, with a register
  credit-alert drawer and a dedicated collect screen; payments push back to
  ERPNext as Payment Entries (idempotent, rejected collections can be discarded).
- **Van-aware numbering** — invoice/collection numbers carry a per-profile van ID
  (`ACC-SINV-TVP-2026-00001`), so two vans never mint the same number.
- **Shifts** with per-payment balances and expected-vs-counted reconciliation.
- **Catalog, customers, coupons, loyalty, stock ledger**, and a daily report with
  Excel export.
- **ERPNext is the source of truth** — POS profiles (company, currency, price
  list, warehouse, payment methods, pricing flags), the invoice numbering series
  and the catalog are pulled from ERPNext; invoices push back as Sales Invoices.
- Roles (admin / manager / cashier), PIN login, and offline-safe sales
  (`offline_id` idempotency).

## Quick start

```bash
git clone https://github.com/SAI-THIHA-AUNG/forkpos.git
cd forkpos
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed      # SQLite default; one bootstrap admin, no demo data
php artisan serve               # or: composer run dev (server + queue together)
php artisan queue:work
```

Sign in with `admin@forkpos.local` / `admin12345` (override via
`FORKPOS_ADMIN_EMAIL` / `FORKPOS_ADMIN_PASSWORD`), then follow the guided setup:
set a new password → connect ERPNext → choose POS profiles.

Optional demo data: `php artisan db:seed --class=DemoSeeder`
(`admin@pos.test` / `password`, cashier `cashier@pos.test` / `password`).
It adds 32 items with stock, 11 customers, a `Main POS` profile (van `MP`) and
three open credit invoices from vans `V1` / `V2` / `MP` — one overdue and one
part-paid — so the Credit collect page and the register's credit alert have
something to show. `Kyaw Min` and `Su Su Hlaing` carry a credit limit.

In production a single cron line drains the queue:

```cron
* * * * * cd /path/to/forkpos && php artisan schedule:run
```

## ERPNext sync

```bash
php artisan forkpos:sync-profiles     # profiles enabled on this terminal
php artisan forkpos:sync-catalog      # profile-scoped, queued, delta cursors
php artisan pos:push-invoices         # push pending invoices as Sales Invoices
php artisan pos:submit-drafts         # one-off: submit drafts left by older pushes (dry run; --apply)
```

Pushes are idempotent: if ERPNext refuses a submit (for example insufficient
stock), the draft's name is kept and the next push finishes that document instead
of creating a second invoice. `pos:submit-drafts` repairs invoices pushed by
versions that left them as drafts, oldest posting date first.

Configure the connector on the **Connect ERPNext** page (or
`php artisan erpnext:setup`). API key/secret are verified against ERPNext and the
secret is stored encrypted.

## API

JSON REST at `/api/v1` (`{"data": …}` envelope, `Authorization: Bearer <token>`).
Sign in with `{email, password}` or `{pin}`. The core flow is
`/setup/status → /pos-profiles → /shifts/open → /items → /invoices → /shifts/{id}/close`.
See `app/Http/Controllers/Api/V1` for the full surface.

## Development

```bash
php artisan test          # PHPUnit feature tests (in-memory SQLite) — 124 tests / 666 assertions
vendor/bin/pint --dirty   # code style
```

## License

GPL-3.0. A Laravel rewrite of [POS Awesome](https://github.com/yrestom/POS-Awesome)
/ [POS-Awesome-V15](https://github.com/defendicon/POS-Awesome-V15) by Y Restom.
See `LICENSE.md`.
