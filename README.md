# ForkPOS — POS Awesome V15, rewritten in Laravel

A **Laravel 13 POS for ERPNext**, rewritten from [POS-Awesome-V15](https://github.com/defendicon/POS-Awesome-V15)
(originally a Frappe/ERPNext app). No Frappe framework — plain Laravel with a local
**SQLite** database (MySQL 8 works too), a **web POS console** (Blade) for the shop
floor, and an **API-first REST layer** so an Android client (or any other frontend)
can be built on top of the same services.

```
Laravel 13.42 · PHP 8.4 · SQLite 3 (default) / MySQL 8 (optional) · Sanctum tokens · Blade + Tailwind (CDN)
```

> Licensed under GPL-3.0, same as the original project. See `LICENSE.md`.

---

## What is implemented (v0.4.0)

| Domain | Feature |
| --- | --- |
| **Auth** | Email+password login, **PIN login** (4–8 digits, for POS/Android), Sanctum bearer tokens, roles (admin / manager / cashier) |
| **First-run setup** | Guided flow: replace the bootstrap password → connect ERPNext (verified before saving) → choose POS profiles. Cashiers see an "ask your manager" screen until it is finished |
| **Web console** | Dashboard, register, shifts, invoices, customers, ERPNext sync, settings and users — with manager/cashier role separation and shared navigation |
| **POS Profiles** | Pulled from ERPNext: company, currency, price list, warehouse, payment methods, applicable users, tax template and behaviour flags. ERPNext is the source of truth; local edits of synced values are blocked |
| **Pricing permissions** | Rate change and discounts in the register are gated by the profile (`allow_rate_change` / `allow_discount_change`); discounts can be **percentage or amount**, per line and on the whole sale, with an optional `max_discount_percentage` cap and an item-level `allow_discount` switch |
| **Shifts** | Open shift with per-payment opening balances, cash in/out movements, live summary, close with **expected vs counted reconciliation** |
| **Invoices** | One-call create+complete, **tax-inclusive and tax-exclusive** math, line discounts (%, amount), invoice-level (total) discount, invoice coupons, loyalty points, cancel with stock restore, **offline idempotency** (`offline_id` UUID), printable receipt |
| **Returns** | Return against a completed sale, line by line, tracking how much of each item is still returnable. Refunds use the price actually paid (after line and total discounts), go back to the current shift's cash drawer and restore stock |
| **Catalog** | Items with barcodes, price lists, UOM conversions, item groups, warehouses, live stock quantities |
| **Customers** | Search, quick-create from POS, walk-in customer, loyalty balance, customer-specific prices |
| **Stock** | Balance per item/warehouse, ledger entries, negative-stock policy |
| **Coupons** | Percent / fixed amount, validity window, usage limit, min order, customer-bound |
| **Reports** | Dashboard (today: sales, payment breakdown, top items, hourly) and a daily report with date ranges, invoice / line-item / inventory-movement tabs and a **multi-sheet Excel export** (manager only) |
| **ERPNext sync** | Profile-scoped catalog pull with delta cursors (queued, with a progress screen), scheduled profile re-sync, and invoice push back to ERPNext as **Sales Invoice documents** (`is_pos = 1`, linked to the POS profile) |

## Quick start (first-run setup)

```bash
git clone https://github.com/SAI-THIHA-AUNG/forkpos.git
cd forkpos
composer install
cp .env.example .env
php artisan key:generate

# SQLite is the default. `--seed` creates ONE bootstrap admin and NO demo data.
php artisan migrate --seed

# Serve the app and drain the queue (catalog syncs are queued jobs):
php artisan serve
php artisan queue:work        # or: composer run dev  (server + queue in one terminal)
```

In production a single cron line is enough — the scheduler drains the queue itself:

```cron
* * * * * cd /path/to/forkpos && php artisan schedule:run
```

Open `http://localhost:8000` and sign in with the bootstrap account
(`admin@forkpos.local` / `admin12345`, overridable with `FORKPOS_ADMIN_EMAIL` /
`FORKPOS_ADMIN_PASSWORD` in `.env`). The app then walks you through:

1. **Set a new password** (the default one is refused afterwards).
2. **Connect ERPNext** — server URL, API key, API secret. The credentials are
   verified against ERPNext (`frappe.auth.get_logged_user`) before they are saved;
   the secret is stored encrypted. Nothing ERPNext-related is read from `.env`.
3. **Choose POS profiles** for this terminal. Currency, warehouse, price list,
   payment methods, behaviour flags and "Applicable for Users" all come from the
   ERPNext profile; users listed there are imported (a manager sets their PIN on
   the **Users** page).
4. **Cashier signs in → picks a profile → opens a shift.** Opening the shift
   queues a profile-scoped catalog sync (that profile's warehouse, price list,
   item groups, customer groups, tax template). A progress screen shows each step
   and the register opens when items, prices and stock are in. Later syncs only
   fetch rows changed since the last run.

Optional demo data for development only: `php artisan db:seed --class=DemoSeeder`
(`admin@pos.test` / `password`, `cashier@pos.test` / `password`).

> Existing databases from earlier builds: migrations were adjusted (hardcoded
> currency / price-list defaults removed, setup-flow columns added), so run
> `php artisan migrate:fresh --seed`.

### Switching to MySQL 8 (optional)

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=forkpos
DB_USERNAME=root
DB_PASSWORD=secret
```

All migrations use portable types; the app was developed and tested on SQLite first.

---

## Web console

| Page | What it does |
| --- | --- |
| `/` | Profile picker (manager pages stay reachable when no profile is selected) |
| `/pos` | Register: item grid/list, search + barcode scan, categories, cart with per-line price/discount editors, whole-sale total discount, payments, change, receipt |
| `/shifts` | Shift history, open/close, cash in/out, per-payment reconciliation |
| `/invoices` | Sales and returns (filter by type), invoice detail, return against a sale, cancel |
| `/dashboard`, `/dashboard/daily` | Today's dashboard; daily report with date range, invoice / line-item / inventory-movement tabs and Excel export for managers |
| `/customers` | Search, create, edit (loyalty + territory) |
| `/sync` | Connector health, pull masters, push pending invoices, recent failures |
| `/settings` | Read-only ERPNext "store defaults" (company/currency/price list/flags, invoice numbering series) plus local receipt text |
| `/users` | Manager-only user list, roles and PIN assignment |

The header keeps **Back**/**Home** shortcuts and a role-filtered top bar, so a
manager can jump between the register and the back office without losing the
selected profile.

### Register pricing rules

- A **Price** field appears per line only when the synced profile allows rate
  changes; otherwise the price-list rate is fixed.
- A **%/Amt** line discount control appears only when the profile allows discounts and
  the item is discountable. The cap (`max_discount_percentage`) is applied to both
  kinds of discount.
- A **Total discount** row discounts the whole sale (percentage or amount, taken off
  the tax-inclusive total and spread over net and tax). It follows the same profile
  switch and cap.
- Totals show subtotal, line discounts, total discount, tax and grand total live; the
  receipt prints the same numbers.
- The backend re-validates every rule, so a tampered API payload cannot invent a
  price list rate, bypass a permission or exceed the discount cap.

### Returns

- Start from a sale (invoice list **Return** link or the invoice page) and enter the
  quantity coming back per item; the form shows sold / already returned / left.
- Lines are refunded at the **price actually paid** — line and total discounts
  included — never today's price list, and a return can never exceed what is left.
- A fully returned sale flips to `returned`; partial returns keep it open.
- The refund (method + amount) belongs to the cashier's **current open shift**, so a
  return can still be processed after the original shift was closed. Amount `0`
  records the credit without paying money out.
- Stock goes back to the invoice's warehouse and the return appears in sales history
  with a `RETURN` badge.

### Daily report & Excel export

- Date range (`from`/`to`, defaulting to today) with summary cards: sales, returns,
  line discounts, total discounts, tax and net sales, plus the payment breakdown.
- Tabs: **Invoices**, **Line items** (one row per sold line) and **Inventory
  movement** (opening, stock in, stock out, closing per item/warehouse, rebuilt from
  the stock ledger).
- Managers can export a four-sheet workbook (Summary, Invoices, Line Items, Inventory
  Movement) written by the built-in `XlsxWriter` — no spreadsheet dependency, just
  PHP's `zip` extension.

---

## ERPNext integration

The connector talks to a **real ERPNext** REST API (`/api/resource/...`) using the
standard Frappe token header `Authorization: token <api_key>:<api_secret>`.

1. In ERPNext, create an API user and generate an **API key + secret**
   (user → Settings → API Access).
2. Configure the connector on the first-run **Connect ERPNext** page (recommended)
   or with `erpnext:setup` for automation:

   ```bash
   php artisan erpnext:setup --url=https://erp.example.com --api-key=... --api-secret=...
   ```

3. Sync POS profiles, then catalog data (usually done by the UI).

### POS profiles (source of truth)

`forkpos:sync-profiles` pulls the profiles this terminal serves (or one by name)
and re-maps everything ERPNext owns:

- company, currency, selling price list, warehouse and payment methods;
- `allow_rate_change` / `allow_discount_change` (also reads the legacy
  `allow_user_to_edit_*` names) and, when present, `max_discount_percentage`
  and `allow_negative_stock`;
- applicable users, tax template and default customer.

The profile's company, currency (+symbol/precision from the ERPNext `Currency`
document) and selling price list are mirrored onto the single settings row, so
receipts and invoices use the same store identity without manual entry.

Invoice numbering is also ERPNext's: the profile sync reads the **Sales Invoice**
document naming series (falling back to **POS Invoice**), strips the dynamic
placeholders to a literal prefix (e.g. `ACC-SINV-.YYYY.-` → `ACC-SINV-`) and
stores both the full series and the prefix on settings. Local invoices keep that
prefix plus `YYYY-` and a 5-digit counter, mirroring ERPNext's shape. Currency
precision still comes from ERPNext, but ForkPOS does not force the trailing
decimals on screen: `1,200.00` is shown as `1,200` while `1.25` stays `1.25`.

```bash
php artisan forkpos:sync-profiles                 # profiles enabled on this terminal
php artisan forkpos:sync-profiles "Test Van POS"  # one profile
php artisan forkpos:sync-profiles --all           # every ERPNext profile
```

Scheduled every 15 minutes. You can also click **Re-sync now** on the Settings
page.

### Catalog sync (queued, profile-scoped)

A pull never fetches "everything in ERPNext": it is scoped to one profile's
warehouse, price list, item groups, customer groups and tax template, and keeps
per-entity `modified` cursors for delta runs.

```bash
php artisan forkpos:sync-catalog                  # every profile with an open shift (queued)
php artisan forkpos:sync-catalog 1                # one local profile id (queued)
php artisan forkpos:sync-catalog 1 --now          # run inline instead of queueing
php artisan forkpos:sync-catalog --full           # ignore delta cursors
```

Opening a shift queues the required steps (`item`, `item_price`, `stock`) and the
register shows `/pos/preparing` until they finish. The scheduler re-runs delta
syncs for open shifts every five minutes. **A queue worker must be running** (or
the scheduler cron, which drains the queue) for these jobs to execute.

### Pushing invoices

```bash
php artisan pos:push-invoices                     # all pending invoices
php artisan pos:push-invoices --ids=12,15         # specific local invoices
```

Push payload rules:

- `company`, `pos_profile`, `selling_price_list` and warehouse come from the
  invoice's own POS profile (not from global settings).
- `rate` is the final discounted unit rate and `price_list_rate` the original
  list price; discount fields are **not** re-sent, so ERPNext never applies a
  discount twice.
- Tax-inclusive rates are sent as-is with `included_in_print_rate` on the tax row.
- Successful pushes store the ERPNext document name and mark the invoice
  `synced`; failures are flagged for retry (scheduled every minute, and available
  from the Sync page).

### Manual master pull

```bash
php artisan pos:sync-masters                       # everything (global, no profile scope)
php artisan pos:sync-masters --entities=item,item_price,customer --limit=1000
```

or via API: `POST /api/v1/sync/masters {"entities": ["item","customer"], "limit": 500, "pos_profile_id": 1}`
(`pos_profile_id` scopes the pull to that profile when supplied).

Pull entities: `uom`, `item_group`, `warehouse`, `customer_group`, `territory`,
`tax`, `item`, `item_price`, `customer`, `stock` (stock syncs from ERPNext `Bin`
for the profile's warehouse). Rows are matched on `erpnext_id` so re-running the
sync updates in place.

---

## API overview

Base URL: `http://<host>/api/v1` — JSON everywhere, responses use a `{"data": ...}`
envelope. Authenticate with `Authorization: Bearer <token>`.

Pagination uses Frappe-style parameters: `limit_start`, `limit_page_length`,
and list responses include `meta.total`.

### Auth

| Method | Endpoint | Notes |
| --- | --- | --- |
| POST | `/auth/login` | `{email, password}` **or** `{pin}` → `{token, user}` |
| POST | `/auth/register` | Only while zero users exist (bootstrap) or with an admin token |
| POST | `/auth/logout` | Revokes current token |
| GET | `/auth/me` | Current user + assigned POS profiles |
| GET | `/setup/status` | `{connected, has_profile, bootstrap_password_pending}` — where a client should start |

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@pos.test","password":"password"}'
```

### POS workflow (the flow every client follows)

```
GET  /setup/status              → is first-run setup finished?
GET  /pos-profiles              → pick a profile (payments + pricing flags included)
GET  /shifts/opening-data       → opening dialog data
POST /shifts/open               → {pos_profile_id, balances:[{mode_of_payment, amount}]}
GET  /items?q=                  → product grid (rates + live stock)
POST /invoices                  → sale / return (idempotent via offline_id)
POST /shifts/{id}/cash-movement → cash in/out during the shift
GET  /shifts/{id}/summary       → expected cash per payment mode
POST /shifts/{id}/close         → {payments:[{mode_of_payment, counted_amount}]} → difference
```

### Endpoints

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/health` | Liveness + version |
| GET/PUT | `/settings` | Store defaults, tax-inclusive default, receipt header/footer, ERPNext credentials (write: manager) |
| GET | `/items`, `/items/{id}`, `/items/barcode/{code}` | Catalog with `rate` (effective price) and `stock_qty` |
| GET | `/item-groups`, `/uoms`, `/warehouses`, `/price-lists`, `/taxes` | Lookups |
| GET/POST | `/customers`, GET/PUT `/customers/{id}` | Customer management, `?q=` search |
| GET/POST | `/pos-profiles`, GET `/pos-profiles/{id}` | Profiles + full POS-screen config (currency, warehouse, flags, max discount) |
| GET | `/shifts`, `/shifts/current`, `/shifts/{id}`, `/shifts/{id}/summary` | Shift info |
| GET | `/shifts/opening-data`, `/shifts/pos-profile-data/{id}` | Opening dialog + register bootstrap |
| POST | `/shifts/open`, `/shifts/{id}/cash-movement`, `/shifts/{id}/close` | Shift lifecycle |
| POST | `/invoices` | Create + complete a sale or return (total discount + line discounts; returns priced from the original sale) |
| GET | `/invoices`, `/invoices/{id}` | History & detail (filters: `status`, `date_from`, `date_to`, `shift_id`, `customer_id`, `q`) |
| GET | `/invoices/offline-check?ids=a,b,c` | Which offline UUIDs already exist (client-side dedupe) |
| POST | `/invoices/{id}/cancel` | Cancel + stock restore (cashier owner or manager) |
| GET | `/coupons/check?code=X&amount=Y` | Validate a coupon before applying |
| GET | `/reports/dashboard`, `/reports/daily-summary?date=` | Sales analytics |
| GET | `/sync/status` | ERPNext connector health + pending push count |
| POST | `/sync/test-connection` | Authenticated connector check |
| POST | `/sync/masters` | Pull masters from ERPNext (manager; optional `pos_profile_id` scope) |
| POST | `/sync/push-invoices` | Push invoices to ERPNext (manager) |

### Create invoice — example payload

```json
POST /api/v1/invoices
{
  "pos_profile_id": 1,
  "customer_id": 2,
  "offline_id": "android-uuid-0001",
  "coupon_code": "WELCOME10",
  "additional_discount_percentage": 5,
  "items": [
    { "item_id": 1, "qty": 5, "tax_id": 1 },
    { "item_id": 17, "qty": 2, "tax_id": 1, "rate": 6.00, "discount_percentage": 5 },
    { "item_id": 18, "qty": 1, "discount_amount": 0.40 }
  ],
  "payments": [
    { "mode_of_payment": "Cash", "amount": 40.00 }
  ]
}
```

Rules the backend enforces (ERPNext-compatible math):

- `price_list_rate` always comes from the item's synced price list; a
  client-sent `price_list_rate` is ignored.
- `rate` is the editable **base** unit price. It is accepted only when the POS
  profile allows price changes; otherwise it must match the list rate.
- `discount_percentage` and `discount_amount` (line total) are accepted only when
  the profile allows discounts and the item is discountable; both respect
  `max_discount_percentage` (amounts are validated as an effective percentage).
- `additional_discount_percentage` / `additional_discount_amount` apply to the whole
  invoice (the amount is clamped to the tax-inclusive total) and follow the same
  profile switch and cap. The response returns both fields.
- **Tax-inclusive** lines: `net = amount / (1 + rate%)`, tax = the remainder.
- `grand_total = net_total + tax_total − coupon_discount − loyalty_discount`, where
  `net_total` and `tax_total` are already reduced by the total discount.
- `paid_amount > grand_total` → `change_amount`; shortfall → `partly_paid`/`unpaid`.
- Returns (`is_return: true` + `return_against_id`) are priced from the original
  invoice and limited to the quantity still returnable (per item). They store
  negative quantities, restore stock and never accept their own discount input.
- `offline_id` is unique — re-sending the same payload returns the original
  invoice with `"duplicate": true` (safe retry / offline flush).
- Loyalty: 1 point per 100.00 spent (simple v0.2.0 policy), 1 point = 1.00
  currency unit when redeemed via `loyalty_points`.

---

## Android application plan

The API was designed for a mobile client from day one:

- **Auth:** PIN login (`POST /auth/login {pin}`) suits a POS terminal; store the
  Sanctum token in EncryptedSharedPreferences / Keystore.
- **Bootstrap:** `GET /setup/status` → `GET /auth/me` → `GET /pos-profiles` →
  cache items (`GET /items?limit_page_length=500`) into Room for **offline selling**.
- **Offline-first sales:** generate a UUID per cart, send as `offline_id` when
  back online; `GET /invoices/offline-check` tells the app what still needs
  pushing. The server deduplicates.
- **Recommended stack:** Kotlin + Retrofit + Room + WorkManager
  (periodic sync job), or Compose + Ktor if preferred.
- Shift open/close and cash movements map 1:1 to the API, so the cashier
  reconciliation screen is a direct rendering of `GET /shifts/{id}/summary`.
- Mirror the register rules: read `allow_rate_change`, `allow_discount_change` and
  `max_discount_percentage` from the profile payload, and let the server reject
  anything the cashier is not allowed to change. Line and whole-sale discounts both
  honour the cap.

Suggested milestones: (1) login + profile + shift screens, (2) item grid +
cart + checkout against the API, (3) offline queue with offline_id flush,
(4) ERPNext push trigger + reporting screen.

---

## Testing

```bash
php artisan test          # PHPUnit feature tests (in-memory SQLite)
vendor/bin/pint --dirty   # code style
```

The feature suite (**59 tests / 286 assertions**) covers login (password + PIN),
first-run setup and password enforcement, profile visibility, profile-scoped
catalog sync and delta cursors, shift open/close reconciliation, invoice math,
rate-change and discount permissions (percent, amount, line and whole-sale, item and
profile caps), the return workflow (remaining quantities, paid-price refunds, stock
restore), the daily report (line detail, inventory movement, Excel export), offline
idempotency, insufficient stock, cashier permission limits, the ERPNext push payload
and the pull summary.

## Project layout

```
app/
  Console/Commands/          pos:sync-masters, pos:push-invoices, forkpos:sync-catalog,
                             forkpos:sync-profiles, erpnext:setup
  Http/Controllers/Api/V1/   REST controllers (9)
  Http/Controllers/Web/      Blade console controllers (11)
  Http/Middleware/           profile selection + setup-complete guards
  Jobs/                      queued profile catalog sync
  Models/                    30 Eloquent models (POS domain)
  Services/                  InvoiceService, ShiftService, StockService, CouponService,
                             ErpNextClient, SyncService, PosProfileSync, ProfileCatalogSync,
                             ReportService (reports + workbook)
  Support/                   PosProfileConfig (ERPNext → local field mapping), XlsxWriter
database/migrations/         36 migrations (SQLite-first, MySQL-compatible)
database/seeders/            DatabaseSeeder (bootstrap admin) + DemoSeeder (opt-in demo data)
routes/api.php               /api/v1 route map
routes/web.php               Blade console route map
routes/console.php           scheduler (queue drain, catalog/profile sync, invoice push)
```

## Roadmap

- [ ] Gift cards (original has pos_gift_card doctypes)
- [ ] Customer credit / store payments
- [ ] Direct ESC/POS or QZ Tray printing (browser print view already exists)
- [ ] Multi-currency payments
- [ ] Batch / serial number tracking with validation
- [ ] Item variants matrix
- [ ] Android client (see plan above)

## Versioning

Work in versions so upstream iterations stay reviewable:

- **v0.1.0** — initial Laravel port: core POS domain, full REST API,
  shift lifecycle, offline idempotency, ERPNext pull/push connector, tests.
- **v0.2.0** — first-run setup flow, Blade web console, profile-scoped queued
  catalog sync with delta cursors, ERPNext-owned settings and profile sync,
  register price-change/discount permissions (%, amount, caps), corrected
  invoice push payload and pull reporting, new test coverage.
- **v0.3.0** — invoice-level (total) discount with profile cap, returns priced from
  the original sale with remaining-quantity tracking and refunds on the current
  shift, Returns navigation, daily report tabs (invoices, line items, inventory
  movement) and a manager-only multi-sheet Excel export.
- **v0.4.0** — invoice prefix comes from the ERPNext document naming series (Sales
  Invoice, falling back to POS Invoice) instead of manual entry, and money/quantity
  displays stop forcing trailing decimals (ERPNext precision is still used for
  rounding).

See `CHANGELOG.md`.

---

*Original project: [POS Awesome](https://github.com/yrestom/POS-Awesome) by
Y Restom (and the [POS-Awesome-V15 fork](https://github.com/defendicon/POS-Awesome-V15)
by defendicon). This repo is a GPL-3.0 Laravel rewrite, not a fork of the Frappe app.*
