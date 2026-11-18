# Instructions for Z.ai (GLM) — ForkPOS state-by-state test and fix

Branch: `feat/shift-required-toggle`. Work only on this branch. Do not merge to `main`; the owner will do that later.

Read `AGENTS.md` first and follow it (project rules, tests, Pint, CHANGELOG). This file only adds the task.

## Ground rules

1. **One state at a time.** Work through the states below in order. Do not start state N+1 until state N passes. After each state, write a short report (what you ran, what you saw, what you changed).
2. **Diagnose before you change code.** Prove the cause with a command or a log line first. Say "not proven" if you could not.
3. **Smallest fix.** No refactors, no new dependencies, no new base folders.
4. **Never print or commit secrets** (ERPNext key/secret, password hashes, `.env`). When checking a password, print only the first 4 characters of the hash.
5. **Ask the owner before any destructive command**: `migrate:fresh`, `db:wipe`, deleting `database/database.sqlite`, `queue:clear`, `cache:clear`, `pos:submit-drafts --apply`. The local DB holds data pulled from ERPNext.
6. **Do not run ERPNext writes** against a live site without the owner's approval.
7. Every fix gets a feature test when a test gives real regression coverage (see `testing-best-practices` skill), then run the narrowest test, then the full suite (`php artisan test --compact`), then `vendor/bin/pint --dirty --format agent`.
8. Commit trailers and CHANGELOG/README updates: follow `AGENTS.md`.

## Environment the owner uses

Windows, `php artisan serve` on `http://127.0.0.1:8000`, PHP 8.4.23, Laravel 13.42, SQLite, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`. Project path: `C:/KS2026MTR/ERPNext_POS/forkpos`. Two POS profiles exist (one works, one does not; the failing one is "Van 9 POS", `pos_profiles.id = 4`).

## Already done on this branch (do not redo, but do verify)

- `ShiftController::posProfileData()`: missing `PosProfile` import, wrong columns (`selling_price` → `standard_rate`, `customer_group` → `customer_group_id`), stack trace removed from the 500 body. Test: `tests/Feature/ShiftProfileDataTest.php`. **These tests were never run** (the previous machine had PHP 8.3 and no packages). Run them first.
- `Web\SyncController::pullMasters()`: added `set_time_limit(0)` / `ignore_user_abort(true)` because `POST /sync/masters` died with "Maximum execution time of 30 seconds exceeded". No test; not yet verified on the owner's machine.
- Nothing has been verified against a real ERPNext.

## State 0 — Environment

Goal: the app boots and tests can run.

- `php -v` is 8.4+, `composer install`, `.env` exists, `php artisan key:generate` done, `php artisan migrate --force` clean.
- Run `php artisan test --compact` once and record the baseline (README says 124 tests). Fix nothing yet; just record failures.
- Make sure a queue worker is part of how the app is run: `composer run dev` should start server + queue worker + Vite. Confirm in `composer.json` / `php artisan dev`. If it does not start a worker, report it.

Pass when: server boots, baseline test result is recorded.

## State 1 — Login (CURRENT BLOCKER)

Symptom: `POST /login` → `RuntimeException: This password does not use the Bcrypt algorithm.` at `app/Http/Controllers/Web/AuthController.php:58` (the `Hash::check($credentials['password'], $user->password)` call), for user `admin@forkpos.local`. This is a 500, not "Invalid email or password".

What this means: the `users.password` value for that row is not a bcrypt hash (plain text, a different algorithm, or a hash written by another tool), and Laravel's hasher is in verify mode, so it throws instead of returning false.

Diagnose (do these, in order, and report each result):

1. First 4 characters of the stored hash. Expect `$2y$`:
   `php artisan tinker --execute 'echo substr((string) \App\Models\User::where("email","admin@forkpos.local")->value("password"), 0, 4);'`
2. `.env` / config: is `HASH_DRIVER` (or any `HASH_*`) set to `argon`/`argon2id`? Is `BCRYPT_ROUNDS` odd? Check with `php artisan config:show hashing`.
3. How did this row get created? Look at `database/seeders/DatabaseSeeder.php` (uses `firstOrCreate`, so an existing row is **never updated**), `SetupController` (password step), `User::casts()` (`'password' => 'hashed'`), and any `database/database.sqlite` copied from another machine or created with a raw `insert`. Note: `firstOrCreate` will not repair a bad existing row, so re-running the seeder will not fix it.
4. Do other users have good hashes? Same prefix check for every row (`pluck('password')` and print only `substr(…, 0, 4)` plus the email).

Fix (only after the cause is proven):

- If the row has a non-bcrypt value: reset it **through the model** so the `hashed` cast hashes it, e.g. set the bootstrap password from `config('forkpos.bootstrap_admin.password')` and keep `must_change_password = true`. Do not hardcode a password in code, tests, docs or commits. Do not insert hashes with raw SQL.
- If `HASH_DRIVER` is the cause: fix `.env` on the owner's machine (tell the owner; do not commit `.env`).
- If code can produce non-bcrypt rows (for example any path that writes `password` without the cast), fix that path and add a test.
- Consider whether login should show "Invalid email or password." instead of a 500 when a stored hash is unusable. Propose it to the owner; do not add it silently.

Pass when: admin can log in from the browser, the session cookie is set, and `must_change_password` sends the user to the setup password step.

## State 2 — First-run setup

- Setup step 1: change the bootstrap password (`SetupController`). Verify the new password is bcrypt afterwards (first 4 characters).
- Setup step 2: connect ERPNext (URL, API key, secret are stored only in the `settings` table; never in code or logs). Verify the ping/auth check works and the error messages are readable if it fails.

Pass when: setup completes and the user reaches Home.

## State 3 — POS profiles (dual-profile problem)

Symptom: one profile works, the other (Van 9 POS, id 4) does not.

- Compare the two `pos_profiles` rows and their ERPNext POS Profile documents: `erpnext_id`, `synced_at`, `warehouse_id`, `selling_price_list`, `currency`, item groups, customer groups, tax template, `van_code` (must be unique, 1–8 letters/digits), `disabled`, `device_enabled`, shift-required setting.
- Check the profile's warehouse: `pullStock` needs a **non-group** warehouse that exists locally (`warehouses.erpnext_id`), otherwise the `stock` step fails.
- Check that the ERPNext API user can read Bin, Item Price, Warehouse, Customer, Item, POS Profile.
- Use `database-query` (Laravel Boost) for read-only DB checks. Do not edit rows by hand unless the owner agrees.

Pass when: you can state, with evidence, why the two profiles differ.

## State 4 — Shift open

- With shift required ON and OFF (the new per-profile toggle), check the register redirect rules and that `ShiftService::open` behaves. After opening a shift, `ShiftService` queues `SyncProfileCatalog` (line ~101).
- `GET /api/v1/shifts/pos-profile-data/{id}`: returns 200 with `items`, `customers`, `warehouses`, `payment_methods` (run `ShiftProfileDataTest`).

Pass when: a shift opens for each profile and the next screen is the right one.

## State 5 — Profile sync (the "Preparing register" screen)

Symptom: all 8 steps (`warehouse, tax, item, item_price, stock, customer, naming_series, credit_invoice`) stay `pending`; sync "succeeds" but the register does not work.

Facts from the code (verify, do not assume):

- The sync is a queued job, `App\Jobs\SyncProfileCatalog` (timeout 900 s, tries 1, `ShouldBeUnique`, `uniqueFor` 900 s, unique per profile id). With `QUEUE_CONNECTION=database` it only runs if a worker is running. All steps `pending` means the job never started.
- The unique lock lives in the cache (`CACHE_STORE=database`). A crashed/killed job can leave the lock for up to 15 minutes, and then "Retry" on the preparing screen silently does nothing.
- `SyncProfileCatalog::handle()` does nothing if `! $profile->isErpnextSynced()` (needs `erpnext_id` and `synced_at`).
- `ProfileCatalogSync::run()` records per-step status in `sync_states`; a failed step stores its message in `sync_states.error` and the loop **continues**. `isReady()` needs only `item`, `item_price`, `stock` = done.
- `ErpNextClient::request()` uses a hard 30 s HTTP timeout (`->timeout(30)`, `->connectTimeout(10)`) per call, and `listAll` pulls pages of 500 rows including heavy fields (`description`, `image`). A slow ERPNext can fail a page with cURL error 28.
- A step can finish "done" with 0 rows (profile filters, price list, warehouse). `cursor` then keeps the last `modified` value, so later delta runs may skip rows; use the `--full` option of `SyncCatalogCommand` to re-pull.
- Pulls upsert row by row without a local transaction, which is slow on SQLite. A transaction around the **local write loop only** (never around the HTTP calls) would speed it up; propose it, do not do it without the owner's OK.

Test in this order:

1. Start a worker (`php artisan queue:work`), open the preparing screen for the failing profile, and watch `sync_states` (`pos_profile_id`, `entity`, `status`, `rows`, `error`) and `jobs`/`failed_jobs`.
2. Report the exact `sync_states.error` text for any failed step and the last lines of `storage/logs/laravel.log`.
3. Run the same profile from the command line for a clean log: use `php artisan list` to find the sync command (`SyncCatalogCommand`), run it for that profile (try `--full` only if the owner agrees).
4. Only then decide the fix: worker not running (docs/dev script), stuck unique lock, HTTP timeout/page size, empty result because of profile filters, or an ERPNext permission error.

Pass when: both profiles reach `ready = true` and `/pos/register` opens with items, prices and stock for each.

## State 6 — Manual master sync (`POST /sync/masters`)

- Re-test the 30 s fix on the owner's machine with the full default entity list. If it still fails, report the new error (likely HTTP timeout or ERPNext).
- Confirm the pulled counts are shown in the flash message and that errors from individual entities are shown without hiding the successes.

## State 7 — Register, sale, credit, push

Only after states 0–6 pass: run the existing flows (sale, credit sale, collection, push invoices) per `AGENTS.md`. Do not push to a live ERPNext without owner approval.

## What to send back to the owner

For each state: **PASS / FAIL / BLOCKED**, the evidence (command + short output, no secrets), the root cause if found, the commit hash of any fix, and what you could not verify. Push commits to `feat/shift-required-toggle`.
