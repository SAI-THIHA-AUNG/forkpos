# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows rough semantic versioning.

## [0.2.0] — 2026-11-17

First-run setup, the Blade web console, and ERPNext as the single source of truth
for store identity and register permissions.

### Added
- **First-run setup flow** (password → connect ERPNext → choose profiles) with
  verified credentials, encrypted secrets, and a "wait for your manager" screen
  for cashiers. `GET /api/v1/setup/status` exposes the same state to clients.
- **Web console** (Blade): profile picker, register, shifts, invoices (view /
  return / cancel), customers, ERPNext sync, settings and users, plus receipt
  printing and shared navigation.
- **Profile-scoped catalog sync** (`forkpos:sync-catalog`, queued): pulls only the
  profile's warehouse, price list, item groups, customer groups and tax template,
  with per-entity `modified` cursors and a `/pos/preparing` progress screen.
- **POS Profile sync** (`forkpos:sync-profiles`): maps ERPNext
  `allow_rate_change` / `allow_discount_change` (and legacy
  `allow_user_to_edit_*`), `max_discount_percentage` and `allow_negative_stock`,
  and mirrors company, currency (+symbol/precision from the `Currency` document)
  and selling price list onto settings.
- **Register pricing controls**: per-line price change and discount as percentage
  or line amount, gated by the profile and item flags, with a live max-discount cap.
- **Users page** for roles and PIN assignment; scheduler entries for queue drain,
  catalog sync, profile sync and invoice push.

### Changed
- Settings page now shows a read-only "Store defaults" card sourced from ERPNext;
  only invoice prefix and receipt text are local. Manual company / currency /
  price-list inputs were removed.
- Invoice engine treats the synced list price as authoritative: client-sent
  `price_list_rate` is ignored, rate overrides and discounts are re-validated
  server-side (including amount discounts as an effective percentage).
- ERPNext push payload uses the invoice's POS profile for company, `pos_profile`,
  price list and warehouse, sends the final `rate` plus `price_list_rate`, and no
  discount fields (no double application); tax-inclusive rates are not divided.
- Master pulls accept comma-separated entity lists and can be scoped to a POS
  profile (web selection or `pos_profile_id` on the API).

### Fixed
- `pullMasters()` reported a non-existent `summary` key and returned an empty
  message; it now reports real pulled/upserted counts and per-entity errors.
- Invoice push sent the stale global company and a non-existent `pos_total_paid`
  field.

## [0.1.0] — 2026-11-15

Initial Laravel port of POS-Awesome-V15 (Frappe/ERPNext → Laravel 13 + SQLite).

### Added
- **Auth**: Sanctum token auth, email/password + PIN login, roles
  (admin / manager / cashier), first-run bootstrap registration.
- **POS domain** (29 models / 30 migrations): items (barcodes, price lists,
  UOM conversions), item groups, warehouses, customers (groups, territories,
  loyalty), POS profiles (payments, permissions), taxes, coupons, shifts
  (opening balances, cash movements, closing reconciliation), invoices
  (items, payments, taxes), stock balances + ledger.
- **REST API v1** under `/api/v1` with `{data: ...}` envelope and
  Frappe-style pagination (`limit_start`, `limit_page_length`).
- **Invoice engine** (`InvoiceService`): ERPNext-compatible totals
  (tax-inclusive/exclusive), line discounts (percent + amount), coupons,
  loyalty earn/redeem, returns, cancellation with stock restore,
  offline idempotency via `offline_id`, invoice numbering with retry.
- **Shift engine** (`ShiftService`): open/close lifecycle, cash in/out,
  live summary, expected-vs-counted reconciliation per payment mode.
- **ERPNext connector** (`ErpNextClient` + `SyncService`): pull masters
  (uom, item groups, warehouses, customer groups, territories, taxes,
  items, item prices, customers, stock) and push invoices as ERPNext
  POS Invoice documents. Artisan commands `pos:sync-masters` and
  `pos:push-invoices`.
- **Reports**: dashboard (sales, payment breakdown, top items, hourly)
  and daily summary.
- **Seeders**: demo store with 32 items, 11 customers, 2 users, 1 POS
  profile, 2 taxes, 2 coupons.
- **Tests**: PHPUnit feature suite covering the full POS flow.
