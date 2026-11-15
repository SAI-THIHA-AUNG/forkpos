# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows rough semantic versioning.

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
