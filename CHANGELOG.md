# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows rough semantic versioning.

## [0.7.0] — 2026-11-18

Credit collection across vans, and van-aware document numbering.

### Fixed
- `GET /api/v1/shifts/pos-profile-data/{id}` crashed (`PosProfile` was not imported) and then
  queried non-existent `selling_price` / `customer_group` columns; it now returns `standard_rate` /
  `customer_group_id` and no longer leaks a stack trace in the 500 response.

### Added
- Settings: per-profile **Shift required / Not required** selector (a local setting; ERPNext POS
  Profiles have no such option). The Van ID tooltip now shows the real numbering prefix.
- `credit_invoices` (unpaid Sales Invoices from *any* van) and `collections`
  tables + `CreditInvoice` / `Collection` models + `CreditService` +
  `CreditController` + `/credit` pages and a register credit-alert chip + slide-over.
- `van_code` on POS profiles and invoices + the `DocNaming` helper.
- `naming_series` and `credit_invoice` pull entities; collections pushed as
  submitted Payment Entries.
- **Demo data**: `DemoSeeder` now ships the credit setup — a `Main POS` profile
  with van `MP`, ERPNext-style numbering settings (`ACC-SINV-`), two customers
  with a credit limit, and three open credit invoices from vans `V1` / `V2` / `MP`
  (one overdue, one part-paid) so the Credit page and the register alert can be
  tried without an ERPNext connection.

### Changed
- The credit screens (list, detail, collect dialog, invoice page) use the same
  trailing-zero trimming as the rest of the app: `15`, not `15.00`.

### Fixed
- **Pushed invoices are submitted.** Frappe ignores `docstatus` on a REST create,
  so every pushed sale stayed a *Draft*: no outstanding balance, and a collection
  against it could never be allocated (the credit sync then closed it as paid).
  The push now submits the invoice, and the Payment Entry path uses the same
  helper (with a reload retry when ERPNext reports a stale `modified`).
- A credit sale keeps the terms chosen at the register (7 / 14 / 30 days or a
  date) for both its credit row and the ERPNext push; 30 days is only the fallback.
- Local numbers and the series sent to ERPNext both come from
  `invoice_series` / `invoice_prefix` — one source, no override.
- The cancel guard explains itself in the UI: "Money has been collected against
  this credit sale… Use Return instead.", not only inside the cancel call.
- A second van collecting an invoice the first one already cleared now fails with
  **"ERPNext reports no balance left… it was probably collected by another van"**
  instead of an account-type error from a Payment Entry built the wrong way round.
  The collection is marked rejected and **Discard** frees the balance again.
- If another van collected *part* of an invoice, the message says so — "ERPNext
  shows only X left… another van probably collected part of it" — instead of
  ERPNext's raw over-allocation error.
- A failed invoice submit no longer creates a duplicate on retry: the draft's name
  is stored straight after the create, and the next push finishes that draft
  (recreating only when it is gone or cancelled). Only a stale-document error
  ("modified after you have opened it") is retried; stock or validation failures
  fail at once with one request instead of a second identical attempt.
- A draft Payment Entry carrying our collection number is submitted before the
  collection counts as pushed, so a failed submit can't silently strand the money.
- Only a sale actually posted to ERPNext blocks cancellation. A sale left as a
  draft by a failed submit can be cancelled locally (the draft then stays in
  ERPNext and has to be cancelled there).
- **`pos:submit-drafts`**: one-off repair for invoices pushed by older versions
  that left them as drafts. Dry run by default; `--apply` submits them oldest
  posting date first, because an update-stock invoice can depend on a later
  receipt being posted before it.
- Discarding the only collection (rejected or manual) returns the invoice to unpaid
  status instead of partly paid, so the status always reflects what has actually
  been paid.
- The unpaid-invoice pull now writes ERPNext's balance back onto the local invoice
  as well, so a payment taken on the desk or by another van (or a Payment Entry
  cancelled there) no longer leaves our sale showing the old status. Returned and
  cancelled sales keep their own status — both when the invoice is still in
  ERPNext's unpaid list and when it drops out of it — and a collection taken on
  such a sale no longer revives it either.
- `GET /reports/daily-summary` now takes `paid_amount` from the payment rows
  instead of the cached invoice field, so a desk payment cannot inflate the
  day's total.

### How it works

**1. Making a credit sale (Register).** Add items and choose a customer. If the
payment is less than the total, the balance becomes a credit sale. Credit needs a
named customer — walk-in must pay in full. The customer's credit limit is checked,
and a sale that would go over it is refused. The invoice number carries the van ID
(e.g. `ACC-SINV-TVP-2026-00001`; returns add `CR`). The sale pushes to ERPNext with
a due date: nothing paid → plain Sales Invoice, part-paid → POS invoice with payments.

**2. Seeing a customer's credit (Register).** Selecting a customer with unpaid
invoices shows an amber line under the customer box (invoice count, total, overdue
count) — it never blocks the sale. Click it to open a side panel of unpaid invoices;
click an invoice to see its item lines, due date, van and past collections, with a
**Quick collect** button per invoice and **Collect** on the detail. The cart is left
as it was.

**3. Collecting a payment.** Quick-collect icons in the Sales list, on the invoice
page and on the Credit page all open the same dialog. Enter an amount (partial or
full, never above the balance), a payment mode and an optional reference. The
collection gets a number like `COL-TVP-2026-00001`, is added to the open shift's
cash, and updates the invoice balance and status immediately. A double click or
retry cannot collect twice.

**4. Credit page.** Lists every unpaid credit invoice from all vans, with search, a
van filter and an overdue flag. **Refresh from ERPNext** pushes waiting collections
and pulls the latest unpaid invoices. Collections ERPNext rejected appear at the top
with the reason and can be discarded to free the balance.

**5. Syncing between vans.** Each sync pulls all unpaid Sales Invoices, whichever van
made them, so Van 1's invoice shows up and can be collected on Van 2. Collections
push as Payment Entries, after the invoices. An invoice ERPNext no longer lists
(another van collected it) is closed locally. If two vans collect the same invoice,
ERPNext rejects the second — that collection is marked rejected and can be discarded.
A sale that has collections against it cannot be cancelled.

**6. Naming and Van ID.** Each POS profile has a Van ID, editable by a manager in
Settings; two profiles cannot share one. Local numbers are
`{ERPNext series}{VAN}-{YYYY}-#####`, and the same van series is sent to ERPNext so
its names carry the van ID too.

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
