<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>

=== forkpos project rules ===

# ForkPOS project rules

These rules are specific to this repository. They sit OUTSIDE the `<laravel-boost-guidelines>` block on purpose: `php artisan boost:update` regenerates that block and would overwrite anything written inside it. Where a project rule below conflicts with a Boost rule above, the project rule wins.

## What this project is

ForkPOS is a Laravel 13 / SQLite point-of-sale app (Blade + Sanctum API) that syncs with ERPNext. Vans (POS profiles) sell, take cash and credit, and push to ERPNext. ERPNext is the source of truth for items, prices, customers, stock, naming series and unpaid invoices.

- PHP: `composer.json` allows `^8.3`. The Boost block above says 8.4; trust `composer.json`.
- Business logic lives in `app/Services` (`InvoiceService`, `CreditService`, `SyncService`, `ShiftService`, `ReportService`, `ErpNextClient`, `NamingSeries`, `ProfileCatalogSync`, `PosProfileSync`). Web and API controllers stay thin and call the same services. Do not duplicate logic in controllers or Blade.
- Pure helpers with no framework code live in `app/Support` (`DocNaming`, `Money`, `XlsxWriter`).

## Secrets and demo data

- ERPNext URL, API key and secret live only in the `settings` table (setup page). Never put them in code, tests, seeders, `.env.example`, docs, commits or patches. Tests use fake hosts and `Http::fake()`.
- `DemoSeeder` is opt-in demo data (`php artisan db:seed --class=DemoSeeder`), never part of a normal install.

## Documentation files

The Boost rule "only create documentation files if asked" applies to NEW files. This project keeps `README.md` and `CHANGELOG.md` current: when behaviour, commands or the test count change, update them in the same commit.

## Money, quantities and decimals

- Show amounts without forced decimals: `1000`, not `1000.00`; `1.25` stays `1.25`.
- Blade: `\App\Support\Money::format()`, `quantity()`, `plain()` (for number inputs). Never `number_format($x, 2)` in a view.
- JavaScript: the register's `fmtAmount()` and the collect dialog's `plain()`. Never `.toFixed(PRECISION)` for display.
- Rounding for storage and ERPNext still uses the currency precision; trimming is display only.

## Document numbering (invoices, returns, collections)

- One source of truth: `settings.invoice_series` / `settings.invoice_prefix`, kept current by `NamingSeries` and `PosProfileSync` from ERPNext. Do not add a competing settings column for the same purpose.
- Build local numbers only through `DocNaming::number()`: `{prefix}{VAN}-[CR-]{YYYY}-#####` (example `ACC-SINV-TVP-2026-00001`). Collections are `COL-{VAN}-{YYYY}-#####`. The result must fit the 32-character `invoice_no` column.
- The van id is `pos_profiles.van_code` (unique, 1-8 letters/digits, `PosProfile::ensureVanCode()`, editable by a manager in Settings). Two vans must never share one.
- On push, the van series (`DocNaming::vanSeries()`) is sent as `naming_series` so ERPNext names carry the van id too. `FORKPOS_REGISTER_VAN_SERIES=false` stops the app from adding missing series to ERPNext.

## ERPNext push rules (important)

- A REST create always leaves a document in Draft; Frappe ignores `docstatus` in the payload. Submit with `SyncService::submitDoc()`. It retries only the "modified after you have opened it" error and re-checks `docstatus` first so a lost response cannot submit twice.
- Persist the ERPNext document name right after the create and before the submit. A retry must finish that document, never create a second one.
- Every push must be safe to repeat:
  - Sales Invoice: reuse the remembered `erpnext_id` (submit if still a draft, recreate only if gone or cancelled).
  - Payment Entry: look up by `reference_no = collection_no` first; a draft found there must be submitted, not counted as pushed.
- Invoices go before collections: a Payment Entry needs its invoice in ERPNext.
- Return clear messages for expected races (another van collected first). Use `ErpNextClient::serverMessage()` text, not raw HTTP codes.
- `pos:submit-drafts` repairs old drafts (dry run by default, oldest first). Do not run it with `--apply` against a live site without the owner's approval; it posts stock and GL entries.

## Credit sales and collections

- A credit sale is a normal invoice with an unpaid balance. Its due date is the invoice's own `due_date` (terms chosen at the register); `config('forkpos.credit_days')` is only the fallback.
- `credit_invoices` holds one row per unpaid invoice from ANY van (local credit sales plus the ERPNext pull). `outstanding = erp_outstanding - pending local collections`. When a collection syncs, `CreditService::markPushed()` lowers `erp_outstanding` by the same amount, so it is never subtracted twice.
- The credit pull is always a full pull of ERPNext's unpaid list. An open row ERPNext no longer lists was paid or cancelled elsewhere and is closed locally.
- The pull mirrors the balance onto the linked local invoice but never overwrites a cancelled or returned sale.
- Walk-in credit is blocked in the register UI only; the server enforces the customer's credit limit. A sale with collections cannot be cancelled (`InvoiceService::cancellationBlocker()`).
- ERPNext is the referee for double collection: it rejects the second Payment Entry, the collection is marked failed with the reason, and Discard frees the balance.

## Register and Blade/JS

- The credit alert must never block or slow a sale: fire-and-forget fetches, ignore failures, drop stale responses.
- Do not pass inline PHP arrays with commas to `@json(...)`; build them first with `@php($collect = [...])`.
- A function name or `const` must exist once per page script (the register already defines `esc` and `fmtAmount`).
- After editing Blade or inline JS, run `php artisan view:cache` (then `view:clear`) and `node --check` on the extracted script.

## Tests

- Run the narrowest tests first, then the full suite before finishing: `php artisan test --compact`.
- `Http::fake()` appends stubs and the first match wins. Use ONE closure per test that handles every URL; do not call `fakeErp()` and then `Http::fake()` again.
- Frappe list endpoints return arrays (`data: []`); single-document endpoints return an object. Fixtures must match.
- Frappe validation errors arrive as 4xx with `_server_messages`; fake them like that.
- Test failure modes that matter in the field: retry after a failed submit, a stale submit, another van collecting first, a returned or cancelled sale during a pull.
- Never weaken an assertion to make a test pass; fix the fixture or the code.

## Definition of done

1. Code follows sibling files and the rules above.
2. Tests added or updated, full suite green.
3. `vendor/bin/pint --dirty --format agent` run.
4. Blade views compile (`php artisan view:cache`) and inline JS passes `node --check`.
5. README test count and CHANGELOG updated.
6. Anything that talks to ERPNext is exercised against a real ERPNext (trial site) at least once, or the hand-off says plainly that it was not.
7. Commit messages end with the attribution trailers the session requires. Never commit secrets.
