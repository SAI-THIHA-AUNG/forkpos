# ForkPOS — POS Awesome V15, rewritten in Laravel

A **Laravel 13 REST API rewrite** of [POS-Awesome-V15](https://github.com/defendicon/POS-Awesome-V15)
(originally a Frappe/ERPNext app). No Frappe framework — plain Laravel with a local
**SQLite** database (MySQL 8 works too), designed **API-first** so an Android client
(or any other frontend) can be built on top of it.

```
Laravel 13.42 · PHP 8.4 · SQLite 3 (default) / MySQL 8 (optional) · Sanctum tokens
```

> Licensed under GPL-3.0, same as the original project. See `LICENSE.md`.

---

## What is implemented (v0.1.0)

| Domain | Feature |
| --- | --- |
| **Auth** | Email+password login, **PIN login** (4–8 digits, for POS/Android), Sanctum bearer tokens, roles (admin / manager / cashier) |
| **POS Profiles** | Multiple profiles, payment methods per profile, rate/discount permissions, tax-inclusive flag, warehouse, price list, shift requirement |
| **Shifts** | Open shift with per-payment opening balances, cash in/out movements, live summary, close with **expected vs counted reconciliation** |
| **Invoices** | One-call create+complete, **tax-inclusive and tax-exclusive** math, line discounts (%, amount), invoice coupons, loyalty points, returns (against invoice), cancel with stock restore, **offline idempotency** (`offline_id` UUID) |
| **Catalog** | Items with barcodes, price lists, UOM conversions, item groups, warehouses, live stock quantities |
| **Customers** | Search, quick-create from POS, walk-in customer, loyalty balance, customer-specific prices |
| **Stock** | Balance per item/warehouse, ledger entries, negative-stock policy |
| **Coupons** | Percent / fixed amount, validity window, usage limit, min order, customer-bound |
| **Reports** | Dashboard (today: sales, payment breakdown, top items, hourly), daily summary |
| **ERPNext Sync** | Pull masters (items, prices, customers, taxes, warehouses, stock…) **from a real ERPNext** via its REST API, and push invoices back as ERPNext **POS Invoice** documents |

## Quick start

```bash
git clone https://github.com/SAI-THIHA-AUNG/forkpos.git
cd forkpos
composer install
cp .env.example .env
php artisan key:generate

# SQLite is the default (database/database.sqlite is created automatically)
php artisan migrate --seed

php artisan serve
# API: http://127.0.0.1:8000/api/v1/health
```

### Seeded demo accounts

| User | Email | Password | PIN | Role |
| --- | --- | --- | --- | --- |
| POS Administrator | `admin@pos.test` | `password` | `1234` | admin |
| Aung Cashier | `cashier@pos.test` | `password` | `5678` | cashier |

The seed also creates a **Main POS** profile (Cash / Card / Mobile Wallet),
32 demo items with barcodes and 100 units of stock each, 11 customers,
2 tax templates (5% inclusive, 10% exclusive) and 2 coupons (`WELCOME10`, `FLAT5`).

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

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@pos.test","password":"password"}'
```

### POS workflow (the flow every client follows)

```
GET  /pos-profiles              → pick a profile (payments list included)
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
| GET/PUT | `/settings` | Company, currency, tax-inclusive default, receipt header/footer, ERPNext credentials (write: manager) |
| GET | `/items`, `/items/{id}`, `/items/barcode/{code}` | Catalog with `rate` (effective price) and `stock_qty` |
| GET | `/item-groups`, `/uoms`, `/warehouses`, `/price-lists`, `/taxes` | Lookups |
| GET/POST | `/customers`, GET/PUT `/customers/{id}` | Customer management, `?q=` search |
| GET/POST | `/pos-profiles`, GET `/pos-profiles/{id}` | Profiles + full POS-screen config |
| GET | `/shifts`, `/shifts/current`, `/shifts/{id}`, `/shifts/{id}/summary` | Shift info |
| POST | `/shifts/open`, `/shifts/{id}/cash-movement`, `/shifts/{id}/close` | Shift lifecycle |
| POST | `/invoices` | Create + complete a sale or return |
| GET | `/invoices`, `/invoices/{id}` | History & detail (filters: `status`, `date_from`, `date_to`, `shift_id`, `customer_id`, `q`) |
| GET | `/invoices/offline-check?ids=a,b,c` | Which offline UUIDs already exist (client-side dedupe) |
| POST | `/invoices/{id}/cancel` | Cancel + stock restore (cashier owner or manager) |
| GET | `/coupons/check?code=X&amount=Y` | Validate a coupon before applying |
| GET | `/reports/dashboard`, `/reports/daily-summary?date=` | Sales analytics |
| GET | `/sync/status` | ERPNext connector health + pending push count |
| POST | `/sync/masters` | Pull masters from ERPNext (manager) |
| POST | `/sync/push-invoices` | Push invoices to ERPNext (manager) |

### Create invoice — example payload

```json
POST /api/v1/invoices
{
  "pos_profile_id": 1,
  "customer_id": 2,
  "offline_id": "android-uuid-0001",
  "coupon_code": "WELCOME10",
  "items": [
    { "item_id": 1, "qty": 5, "tax_id": 1 },
    { "item_id": 17, "qty": 2, "tax_id": 1, "discount_percentage": 5 }
  ],
  "payments": [
    { "mode_of_payment": "Cash", "amount": 20.00 }
  ]
}
```

Rules the backend enforces (ERPNext-compatible math):

- `price_list_rate` comes from the item's price list; `rate` is after line discount.
- **Tax-inclusive** lines: `net = amount / (1 + rate%)`, tax = the remainder.
- `grand_total = net_total + tax_total − coupon_discount − loyalty_discount`.
- `paid_amount > grand_total` → `change_amount`; shortfall → `partly_paid`/`unpaid`.
- Returns (`is_return: true` + `return_against_id`) store negative quantities and
  put stock back automatically; cancelling an invoice restores stock too.
- `offline_id` is unique — re-sending the same payload returns the original
  invoice with `"duplicate": true` (safe retry / offline flush).
- Loyalty: 1 point per 100.00 spent (simple v0.1.0 policy), 1 point = 1.00
  currency unit when redeemed via `loyalty_points`.

---

## ERPNext integration

The connector talks to a **real ERPNext** REST API (`/api/resource/...`) using the
standard Frappe token header `Authorization: token <api_key>:<api_secret>`.

1. In ERPNext, create an API user and generate an **API key + secret**
   (user → Settings → API Access).
2. Configure the connector (either way):
   - **Runtime (recommended):** `PUT /api/v1/settings` with
     `erpnext_url`, `erpnext_api_key`, `erpnext_api_secret`, `erpnext_company`,
     `erpnext_price_list` (secret is stored encrypted in SQLite), or
   - **.env:** `ERPNEXT_URL=... ERPNEXT_API_KEY=... ERPNEXT_API_SECRET=...`
3. Pull masters:

```bash
php artisan pos:sync-masters                       # everything
php artisan pos:sync-masters --entities=item,item_price,customer --limit=1000
```

   or via API: `POST /api/v1/sync/masters {"entities": ["item", "customer"], "limit": 500}`

4. Push sales back to ERPNext as **POS Invoice** documents:

```bash
php artisan pos:push-invoices                      # all pending
php artisan pos:push-invoices --ids=12,15
```

Pull entities: `uom`, `item_group`, `warehouse`, `customer_group`, `territory`,
`tax`, `item`, `item_price`, `customer`, `stock` (stock syncs from ERPNext `Bin`
for the first synced warehouse). Rows are matched on `erpnext_id` so re-running
the sync updates in place.

---

## Android application plan

The API was designed for a mobile client from day one:

- **Auth:** PIN login (`POST /auth/login {pin}`) suits a POS terminal; store the
  Sanctum token in EncryptedSharedPreferences / Keystore.
- **Bootstrap:** `GET /auth/me` → `GET /pos-profiles` → cache items
  (`GET /items?limit_page_length=500`) into Room for **offline selling**.
- **Offline-first sales:** generate a UUID per cart, send as `offline_id` when
  back online; `GET /invoices/offline-check` tells the app what still needs
  pushing. The server deduplicates.
- **Recommended stack:** Kotlin + Retrofit + Room + WorkManager
  (periodic sync job), or Compose + Ktor if preferred.
- Shift open/close and cash movements map 1:1 to the API, so the cashier
  reconciliation screen is a direct rendering of `GET /shifts/{id}/summary`.

Suggested milestones: (1) login + profile + shift screens, (2) item grid +
cart + checkout against the API, (3) offline queue with offline_id flush,
(4) ERPNext push trigger + reporting screen.

---

## Testing

```bash
php artisan test          # PHPUnit feature tests (in-memory SQLite)
```

The feature tests cover the full flow: login (password + PIN), shift open,
invoice creation with tax/coupon math, offline idempotency, insufficient stock
rejection, cashier permission limits and shift close reconciliation.

## Project layout

```
app/
  Console/Commands/          pos:sync-masters, pos:push-invoices
  Http/Controllers/Api/V1/   All REST controllers
  Models/                    29 Eloquent models (POS domain)
  Services/                  InvoiceService, ShiftService, StockService,
                             CouponService, ErpNextClient, SyncService
database/migrations/         30 migrations (SQLite-first, MySQL-compatible)
database/seeders/            Demo dataset
routes/api.php               /api/v1 route map
```

## Roadmap

- [ ] Gift cards (original has pos_gift_card doctypes)
- [ ] Customer credit / store payments
- [ ] Printing (58mm receipt templates, QZ tray support)
- [ ] Multi-currency payments
- [ ] Batch / serial number tracking with validation
- [ ] Item variants matrix
- [ ] Android client (see plan above)

## Versioning

Work in versions so upstream iterations stay reviewable:

- **v0.1.0** — initial Laravel port: core POS domain, full REST API,
  shift lifecycle, offline idempotency, ERPNext pull/push connector, tests.

See `CHANGELOG.md`.

---

*Original project: [POS Awesome](https://github.com/yrestom/POS-Awesome) by
Y Restom (and the [POS-Awesome-V15 fork](https://github.com/defendicon/POS-Awesome-V15)
by defendicon). This repo is a GPL-3.0 Laravel rewrite, not a fork of the Frappe app.*
