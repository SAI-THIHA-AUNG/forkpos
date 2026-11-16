<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CustomerController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\InvoiceController;
use App\Http\Controllers\Web\PosController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\SettingController;
use App\Http\Controllers\Web\ShiftController;
use App\Http\Controllers\Web\SyncController;
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
Route::post('login', [AuthController::class, 'login']);
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

// ----- Authenticated (session) ---------------------------------------------
Route::middleware('auth')->group(function () {
    // Profile selection — landing page after login, before the register.
    Route::get('/', [ProfileController::class, 'index'])->name('home');
    Route::post('/profile/select', [ProfileController::class, 'select'])->name('profile.select');

    // Everything below requires a POS profile chosen in the session.
    Route::middleware('profile.selected')->group(function () {
        // Register (the POS screen)
        Route::get('/pos', [PosController::class, 'register'])->name('pos.register');
        Route::post('/pos/invoice', [PosController::class, 'checkout'])->name('pos.checkout');
        Route::get('/pos/receipt/{invoice}', [PosController::class, 'receipt'])->name('pos.receipt');
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

        // Invoices / sales history / returns / cancel
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('/invoices/{invoice}/return', [InvoiceController::class, 'returnForm'])->name('invoices.return.form');
        Route::post('/invoices/{invoice}/return', [InvoiceController::class, 'processReturn'])->name('invoices.return');
        Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');

        // Dashboard (reports)
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/daily', [DashboardController::class, 'daily'])->name('dashboard.daily');

        // Sync dashboard (manager-only)
        Route::middleware('role:admin,manager')->group(function () {
            Route::get('/sync', [SyncController::class, 'index'])->name('sync.index');
            Route::post('/sync/masters', [SyncController::class, 'pullMasters'])->name('sync.masters');
            Route::post('/sync/push-invoices', [SyncController::class, 'pushInvoices'])->name('sync.push');

            // Settings (manager-only)
            Route::get('/settings', [SettingController::class, 'edit'])->name('settings.edit');
            Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
            Route::post('/settings/sync-pos-profile', [SettingController::class, 'syncPosProfile'])
                ->name('settings.sync-pos-profile');
        });
    });
});
