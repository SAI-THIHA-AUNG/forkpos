<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CreditController;
use App\Http\Controllers\Web\CustomerController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DraftController;
use App\Http\Controllers\Web\InvoiceController;
use App\Http\Controllers\Web\PosController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\SettingController;
use App\Http\Controllers\Web\SetupController;
use App\Http\Controllers\Web\ShiftController;
use App\Http\Controllers\Web\SyncController;
use App\Http\Controllers\Web\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web POS routes — session-based, browser-first
|--------------------------------------------------------------------------
| Per AI_TALK.md: the browser is the primary client. These routes render
| Blade views but call the SAME services the API uses (InvoiceService,
| ShiftService, SyncService, ...). No business logic is duplicated.
*/

// ----- Public ---------------------------------------------------------------
Route::get('login', [AuthController::class, 'showLogin'])->name('login');
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

// ----- Authenticated (session) ---------------------------------------------
Route::middleware('auth')->group(function () {
    // First-run setup — reachable BEFORE setup is complete.
    Route::get('setup/password', [SetupController::class, 'showPassword'])->name('setup.password');
    Route::post('setup/password', [SetupController::class, 'updatePassword'])->name('setup.password.update');
    Route::get('setup/waiting', [SetupController::class, 'waiting'])->name('setup.waiting');
    Route::middleware(['setup.complete:password', 'role:admin,manager'])->group(function () {
        Route::get('setup/connect', [SetupController::class, 'showConnect'])->name('setup.connect');
        Route::post('setup/connect', [SetupController::class, 'storeConnect'])->name('setup.connect.store');
        Route::get('setup/profiles', [SetupController::class, 'showProfiles'])->name('setup.profiles');
        Route::post('setup/profiles', [SetupController::class, 'storeProfiles'])->name('setup.profiles.store');
    });

    // Everything below needs finished setup (password changed, ERPNext connected, profile enabled).
    Route::middleware('setup.complete')->group(function () {
        // Profile selection — landing page after login, before the register.
        Route::get('/', [ProfileController::class, 'index'])->name('home');
        Route::post('/profile/select', [ProfileController::class, 'select'])->name('profile.select');

        // Manager area — no profile has to be selected first.
        Route::middleware('role:admin,manager')->group(function () {
            Route::get('/sync', [SyncController::class, 'index'])->name('sync.index');
            Route::post('/sync/masters', [SyncController::class, 'pullMasters'])->name('sync.masters');
            Route::post('/sync/push-invoices', [SyncController::class, 'pushInvoices'])->name('sync.push');

            Route::get('/settings', [SettingController::class, 'edit'])->name('settings.edit');
            Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
            Route::put('/settings/pos-profile/{profile}/shift-required', [SettingController::class, 'updateShiftRequired'])
                ->name('settings.shift-required');
            Route::put('/settings/pos-profile/{profile}/van-code', [SettingController::class, 'updateVanCode'])
                ->name('settings.van-code');
            Route::post('/settings/sync-pos-profile', [SettingController::class, 'syncPosProfile'])
                ->name('settings.sync-pos-profile');

            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        });

        // Everything below requires a POS profile chosen in the session.
        Route::middleware('profile.selected')->group(function () {
            // Register (the POS screen) + catalog preparation screen
            Route::get('/pos', [PosController::class, 'register'])->name('pos.register');
            Route::get('/pos/preparing', [PosController::class, 'preparing'])->name('pos.preparing');
            Route::get('/pos/preparing/status', [PosController::class, 'preparingStatus'])->name('pos.preparing.status');
            Route::post('/pos/preparing/retry', [PosController::class, 'preparingRetry'])->name('pos.preparing.retry');
            Route::post('/pos/invoice', [PosController::class, 'checkout'])->name('pos.checkout');
            Route::get('/pos/receipt/{invoice}', [PosController::class, 'receipt'])->name('pos.receipt');
            Route::get('/pos/drafts', [DraftController::class, 'index'])->name('drafts.index');
            Route::post('/pos/drafts', [DraftController::class, 'store'])->name('drafts.store');
            Route::delete('/pos/drafts/{draft}', [DraftController::class, 'destroy'])->name('drafts.destroy');
            Route::get('/pos/items', [PosController::class, 'items']);
            Route::get('/pos/barcode/{code}', [PosController::class, 'itemByBarcode']);

            // Shifts
            Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index');
            Route::get('/shifts/open', [ShiftController::class, 'openForm'])->name('shifts.open.form');
            Route::post('/shifts/open', [ShiftController::class, 'open'])->name('shifts.open');
            Route::get('/shifts/current', [ShiftController::class, 'current'])->name('shifts.current');
            Route::get('/shifts/{shift}/summary', [ShiftController::class, 'summary'])->name('shifts.summary');
            Route::post('/shifts/{shift}/cash-movement', [ShiftController::class, 'cashMovement'])->name('shifts.cash-movement');
            Route::post('/shifts/{shift}/close', [ShiftController::class, 'close'])->name('shifts.close');
            Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->name('shifts.show');

            // Customers — note: loyalty_points is intentionally NOT writable from web
            Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
            Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
            Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');

            // Credit sales & collections (unpaid invoices of every van)
            Route::get('/credit', [CreditController::class, 'index'])->name('credit.index');
            Route::post('/credit/refresh', [CreditController::class, 'refresh'])->name('credit.refresh');
            Route::get('/credit/customer/{customer}', [CreditController::class, 'customer'])->name('credit.customer');
            Route::post('/credit/collections/{collection}/discard', [CreditController::class, 'discard'])->name('credit.discard');
            Route::get('/credit/{credit}/detail', [CreditController::class, 'detail'])->name('credit.detail');
            Route::post('/credit/{credit}/collect', [CreditController::class, 'collect'])->name('credit.collect');
            Route::get('/credit/{credit}', [CreditController::class, 'show'])->name('credit.show');

            // Invoices / sales history / returns / cancel
            Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
            Route::get('/invoices/{invoice}/return', [InvoiceController::class, 'returnForm'])->name('invoices.return.form');
            Route::post('/invoices/{invoice}/return', [InvoiceController::class, 'processReturn'])->name('invoices.return');
            Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');

            // Dashboard (reports)
            Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
            Route::get('/dashboard/daily', [DashboardController::class, 'daily'])->name('dashboard.daily');
            Route::get('/dashboard/daily/export', [DashboardController::class, 'export'])
                ->middleware('role:admin,manager')->name('dashboard.daily.export');
        });
    });
});
