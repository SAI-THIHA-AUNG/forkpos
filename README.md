# ForkPOS

**POS Awesome V15, rewritten in Laravel** — a lightweight point-of-sale for
[ERPNext](https://erpnext.com). A web register (Blade) with an API-first REST
layer, backed by SQLite (or MySQL). No Frappe framework.

`Laravel 13 · PHP 8.3+ · SQLite / MySQL · Sanctum · Blade + Tailwind (CDN)` · GPL-3.0

## Features

- **Web POS register** — item grid/list, search + barcode, categories, cart with
  per-line price & discounts, whole-sale discount, payments, change and a
  printable receipt.
- **Credit sales** (7 / 14 / 30-day terms or a due date, down payments, overdue
  tracking, per-customer credit limit), **returns** priced from the original
  sale, **saved drafts**, and audited **sale cancellation** with stock restore.
- **Credit collect** — unpaid credit invoices from *every* van. Picking a customer
  with unpaid invoices shows a small alert on the register (it never blocks the
  sale); click it for the invoice list, the invoice detail and a quick collect.
  A dedicated **Credit** screen and a quick-collect icon in the sales list do the
  same. Collections push to ERPNext as Payment Entries (idempotent; one ERPNext
  rejects — for example because another van already collected — is marked failed
  and can be discarded).
- **Van-aware numbering** — invoice and collection numbers carry a per-profile van
  ID (`ACC-SINV-TVP-2026-00001`, `COL-TVP-2026-00001`), so two vans never mint the
  same number. The van ID is editable in **Settings**.
- **Shifts** with per-payment balances and expected-vs-counted reconciliation;
  cash collected on credit invoices counts into the shift.
- **Catalog, customers, coupons, loyalty, stock ledger**, and a daily report with
  line-level sales, per-date inventory movement and Excel export.
- **ERPNext is the source of truth** — POS profiles (company, currency, price
  list, warehouse, payment methods, pricing flags), the invoice numbering series,
  the catalog and unpaid invoices are pulled from ERPNext; invoices and
  collections push back as Sales Invoices and Payment Entries.
- Roles (admin / manager / cashier), PIN login, and offline-safe sales
  (`offline_id` idempotency).

## Vans, POS profiles and shifts

A **POS profile is a van** (or a till). Its settings come from different places:

| Setting | Source |
|---|---|
| Company, currency, price list, warehouse, tax and pricing flags | ERPNext POS Profile (pulled) |
| **Payment methods** (the register's payment buttons) | ERPNext POS Profile → *Payments* table (pulled). Demo data seeds Cash / Card / Mobile Wallet |
| **Shift required** | Local setting — ERPNext POS Profiles have no such option. Defaults to *required*; a manager changes it per profile in **Settings** |
| **Van ID** | Local setting, generated from the profile name (`Test Van POS` → `TVP`), unique, editable in **Settings** |
| Invoice number series | ERPNext Sales Invoice series, plus the van ID |

Several vans can sell at the same time. A cashier has **one open shift at a time**
(cash accountability); different cashiers can each have a shift on their own
profile, or on the same one. Give each salesman their own login. A manager
account can look at every profile, but cannot hold shifts on two profiles at once.

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
something to show. `Kyaw Min` and `Su Su Hlaing` carry a credit limit. The demo
invoices are not real ERPNext documents: the first real credit pull closes them.

In production a single cron line drains the queue:

```cron
* * * * * cd /path/to/forkpos && php artisan schedule:run
```

## Configuration

ERPNext URL, API key and secret are entered on the **Connect ERPNext** page (or
`php artisan erpnext:setup`) and stored in the database, the secret encrypted.
Never put them in code, tests or `.env.example`.

| `.env` key | Default | Meaning |
|---|---|---|
| `FORKPOS_ADMIN_EMAIL` / `FORKPOS_ADMIN_PASSWORD` / `FORKPOS_ADMIN_NAME` | `admin@forkpos.local` / `admin12345` / `Administrator` | Bootstrap administrator created by `db:seed` |
| `FORKPOS_CREDIT_DAYS` | `30` | Due date when a credit sale has no terms of its own |
| `FORKPOS_REGISTER_VAN_SERIES` | `true` | Add a van's ERPNext naming series (`ACC-SINV-TVP-.YYYY.-`) to Sales Invoice on first push. Set `false` to manage series in ERPNext yourself; ERPNext then uses its default series |

## ERPNext sync

```bash
php artisan forkpos:sync-profiles     # profiles enabled on this terminal
php artisan forkpos:sync-catalog      # profile-scoped, queued, delta cursors
php artisan pos:push-invoices         # push pending invoices, then collections
php artisan pos:submit-drafts         # one-off: submit drafts left by older pushes (dry run; --apply)
```

- **Pull:** besides items, prices, customers and stock, each sync pulls the
  Sales Invoice naming series and the full list of **unpaid Sales Invoices from
  every van**. An invoice ERPNext no longer lists was paid or cancelled elsewhere
  and is closed locally. Use **Refresh from ERPNext** on the Credit screen for an
  on-demand pull.
- **Push:** invoices first, then collections (a Payment Entry needs its invoice in
  ERPNext). Both are submitted after creation. Pushes are idempotent: if ERPNext
  refuses a submit (for example insufficient stock), the draft's name is kept and
  the next push finishes that document instead of creating a second one.
- **Two vans, one invoice:** ERPNext decides. The second Payment Entry is rejected,
  the collection shows as *rejected* with ERPNext's message, and **Discard** frees
  the balance.
- `pos:submit-drafts` repairs invoices pushed by versions that left them as
  drafts, oldest posting date first. It posts stock and accounting entries, so
  run `--apply` only with the owner's approval.

## API

JSON REST at `/api/v1` (`{"data": …}` envelope, `Authorization: Bearer <token>`).
Sign in with `{email, password}` or `{pin}`. The core flow is
`/setup/status → /pos-profiles → /shifts/open → /items → /invoices → /shifts/{id}/close`.
See `app/Http/Controllers/Api/V1` for the full surface. Credit collection is a web
feature (`/credit`); it has no API endpoints yet.

## Development

```bash
php artisan test          # PHPUnit feature tests (in-memory SQLite) — 136 tests / 735 assertions
vendor/bin/pint --dirty   # code style
```

Contributor and agent rules (numbering, ERPNext push, credit logic, tests,
definition of done) are in `AGENTS.md`. Keep this README and `CHANGELOG.md`
current when behaviour, commands or the test count change.

## License

GPL-3.0. A Laravel rewrite of [POS Awesome](https://github.com/yrestom/POS-Awesome)
/ [POS-Awesome-V15](https://github.com/defendicon/POS-Awesome-V15) by Y Restom.
See `LICENSE.md`.
