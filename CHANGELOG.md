# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows rough semantic versioning.

## [0.7.0] — 2026-11-18

Credit collection across vans, and van-aware document numbering.

### Added
- **Credit collect**: `credit_invoices` (unpaid Sales Invoices from *any* van,
  filled by ERPNext pull and this van's own credit sales) and `collections`
  (money taken against them), plus `CreditService`, `CreditController`,
  `/credit` pages and a register credit-alert chip + slide-over (list → detail →
  collect).
- **Van-aware numbering**: `van_code` on POS profiles and invoices + the
  `DocNaming` helper. Invoice numbers are `{ERPNext series}{VAN}-[CR-]{YYYY}-#####`
  (e.g. `ACC-SINV-TVP-2026-00001`) and collections are `COL-{VAN}-{YYYY}-#####`,
  so two vans can never collide. The van ID is editable in Settings.
- **ERPNext sync**: `naming_series` and `credit_invoice` pull entities, and
  collections push back as submitted Payment Entries (idempotent; ERPNext rejects
  a double collection → marked failed → can be discarded).

### Changed
- A short payment now becomes a credit sale automatically (register requires a
  named customer and confirms the owed amount); the balance is tracked in
  `credit_invoices` and respects the customer's `credit_limit`.
- Cancelling a sale with collections recorded against it is refused.

## [0.6.0] — 2026-11-17

Saved register drafts and a guarded, audited sale-cancellation workflow.

### Added
- **Drafts**: a register cart can be saved as a draft (`pos_drafts` table +
  `PosDraft` model + `DraftController` + `/pos/drafts` routes and a nav entry).
  A draft moves no stock, takes no payment and has no invoice number; it stores
  the cart lines, customer, coupon, whole-sale discount and credit terms, and is
  shared by every cashier on the POS profile. Resuming re-prices lines to today's
  list (hand-set prices only survive when the profile allows price changes) and
  charging a resumed draft removes it.
- **Sale cancellation audit**: `cancelled_at`, `cancelled_by` and `cancel_reason`
  on invoices, with a `canceller()` relation. A cancelled sale is never deleted.
- `InvoiceService::cancellationBlocker()` / `refundDue()` — a single guard shared
  by the API, the web controller and the invoice page.

### Changed
- `InvoiceService::cancel()` now requires a reason and a user, locks the row,
  restores stock, voids payments (they leave shift/daily totals), reverses coupon
  `used_count` and loyalty points, zeroes the balance, and records who/when/why.
- Cancelling a sale from an already-closed shift books the refund as a cash-out
  (`PosCashMovement`) in the manager's current open shift.
- Cancel is refused when the sale already has (non-cancelled) returns, is already
  cancelled, is a return itself, or has been synced to ERPNext.
- Invoice page shows a cancellation card (with the refund amount) or the reason
  cancel is unavailable; receipts show credit status.

### Fixed
- `pos/invoices/show.blade.php`: a `@if` glued to the preceding word (`totals@if`)
  that Blade did not compile — now spaced correctly.

## [0.5.0] — 2026-11-17

Credit sales: a customer takes goods now and pays later.

### Added
- `is_credit`, `due_date` and `credit_days` on invoices (migration adds the three
  columns); `Invoice::CREDIT_TERM_DAYS = [7, 14, 30]` and `Invoice::isOverdue()`.
- Credit flow on the register: a toggle that swaps the tender into a down-payment
  editor, 7 / 14 / 30-day pills plus a "Pick date" option, a live due-date line and
  a "Charge on credit" button. Any non-cash payment row gets an optional reference
  field (KBZPay, Wave, bank transfer, card…).
- Backend rules: a walk-in customer cannot owe money, terms must be one of
  `[7, 14, 30]` or a future due date, and credit is refused when the payments
  already cover the total. Zero paid up front → `unpaid`, a down payment →
  `partly_paid`; the rest stays in `outstanding_amount`.
- Invoice list gains a **Due** column, overdue highlighting and a payment-type
  filter (All / Credit / Overdue). Receipts and invoice pages show the credit
  status, balance due and payment-due date.

### Changed
- The ERPNext push now sends a regular Sales Invoice (`is_pos = 0`, no POS profile,
  with `due_date`) when a sale has no payment rows (i.e. is fully on credit); paid
  sales still push as POS invoices (`is_pos = 1`).
- `RateDiscountPushTest` sale helper accepts a payments argument so the POS-push
  test keeps a payment row under the new logic.

## [0.4.0] — 2026-11-17

Invoice numbering is owned by ERPNext, and money/quantity displays stop forcing
trailing decimals.

### Added
- `NamingSeries` service: resolves the ERPNext document naming series for the
  **Sales Invoice** DocType (falling back to **POS Invoice**), preferring the
  DocType `naming_series` field and falling back to the newest document's series.
  It derives the literal prefix (`ACC-SINV-.YYYY.-` → `ACC-SINV-`).
- `Money` support class (`format`, `quantity`, `plain`) used across all Blade
  money/quantity output.

### Changed
- `PosProfileSync` now mirrors the ERPNext naming series onto settings
  (`invoice_series` + derived `invoice_prefix`) once per sync; the prefix is no
  longer editable on the Settings page and is shown read-only with the next
  invoice number.
- `InvoiceService` builds local numbers from the synced prefix (`prefix-YYYY-#####`)
  and drops the old `CR-` return infix so numbers mirror ERPNext's shape.
- Money and quantity displays trim trailing zeros (ERPNext precision is still used
  for rounding): `1,200` instead of `1,200.00`, `1.25` unchanged. The register,
  receipt, invoices, dashboard, shifts, customers and settings screens all use the
  new formatter.
- `settings.invoice_prefix` widened to 40 chars (default preserved) and new
  `settings.invoice_series` column.

## [0.3.0] — 2026-11-17

Register total discount, a proper returns workflow and a report/export screen.

### Added
- **Invoice-level (total) discount** in the register and API:
  `additional_discount_percentage` / `additional_discount_amount`, applied on the
  tax-inclusive total and spread over net and tax. Gated by
  `allow_discount_change` and clamped by `max_discount_percentage`.
- **Returns against a sale**: line-by-line return form showing sold / returned /
  remaining, refunds priced from what the customer actually paid (line + total
  discounts), refund method and amount, stock restore, and a `returned` status only
  once everything is back. Return invoices are listed under a new **Returns** nav
  entry and shown on the sale's page.
- **Daily report**: `from`/`to` range, summary cards (sales, returns, line/total
  discounts, tax, net), payment breakdown, and tabs for invoices, line items and
  inventory movement (opening / in / out / closing rebuilt from the stock ledger).
- **Excel export** (`ReportService` + `XlsxWriter`, manager only): four-sheet
  workbook (Summary, Invoices, Line Items, Inventory Movement) with real numeric
  cells, no third-party spreadsheet package.
- `ReportService`, `XlsxWriter` and `DiscountReturnReportTest`.

### Changed
- Returns are recorded on the cashier's **current open shift** instead of the
  (possibly closed) shift of the original sale; the sale stays open on partial
  returns.
- The return form accepts only quantities that are still returnable and no longer
  takes per-payment refund rows.
- ERPNext push sends `apply_discount_on: Grand Total` and
  `additional_discount_amount` when a total discount was used.
- `DemoSeeder` dates opening stock a month back so reports show it as an opening
  balance rather than stock received today.

### Fixed
- Returning an item no longer refunds today's price list rate; the original paid
  rate (including discounts) is used.

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
