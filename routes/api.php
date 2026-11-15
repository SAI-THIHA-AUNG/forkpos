<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\PosProfileController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| forkpos API v1 — POS-Awesome-V15 rewritten as a Laravel REST backend
|--------------------------------------------------------------------------
| All POS endpoints live under /api/v1 and speak JSON with a { "data": ... }
| envelope, so an Android client (Retrofit / Ktor) or the ERPNext-syncing
| AI coder can consume them uniformly.
*/

Route::prefix('v1')->group(function () {
    // ----- Public -----------------------------------------------------------
    Route::get('health', function () {
        return response()->json([
            'data' => [
                'status' => 'ok',
                'app' => config('app.name'),
                'version' => 'v0.1.0',
                'time' => now()->toDateTimeString(),
            ],
        ]);
    });

    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/register', [AuthController::class, 'register']); // open only while zero users exist

    // ----- Authenticated (Sanctum bearer token) ------------------------------
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // Settings (read for everyone, write for managers)
        Route::get('settings', [SettingController::class, 'show']);
        Route::put('settings', [SettingController::class, 'update']);
        Route::get('taxes', [SettingController::class, 'taxes']);
        Route::get('coupons/check', [SettingController::class, 'checkCoupon']);

        // Catalog
        Route::get('items/barcode/{code}', [CatalogController::class, 'itemByBarcode']);
        Route::get('items', [CatalogController::class, 'items']);
        Route::get('items/{id}', [CatalogController::class, 'item'])->whereNumber('id');
        Route::get('item-groups', [CatalogController::class, 'itemGroups']);
        Route::get('uoms', [CatalogController::class, 'uoms']);
        Route::get('warehouses', [CatalogController::class, 'warehouses']);
        Route::get('price-lists', [CatalogController::class, 'priceLists']);

        // Customers
        Route::get('customers', [CustomerController::class, 'index']);
        Route::post('customers', [CustomerController::class, 'store']);
        Route::get('customers/{id}', [CustomerController::class, 'show'])->whereNumber('id');
        Route::put('customers/{id}', [CustomerController::class, 'update'])->whereNumber('id');

        // POS profiles
        Route::get('pos-profiles', [PosProfileController::class, 'index']);
        Route::post('pos-profiles', [PosProfileController::class, 'store']);
        Route::get('pos-profiles/{id}', [PosProfileController::class, 'show'])->whereNumber('id');

        // Shifts — literal routes must come before the {id} wildcard
        Route::get('shifts/opening-data', [ShiftController::class, 'openingData']);
        Route::post('shifts/open', [ShiftController::class, 'open']);
        Route::get('shifts/current', [ShiftController::class, 'current']);
        Route::get('shifts', [ShiftController::class, 'index']);
        Route::post('shifts/{id}/cash-movement', [ShiftController::class, 'cashMovement'])->whereNumber('id');
        Route::get('shifts/{id}/summary', [ShiftController::class, 'summary'])->whereNumber('id');
        Route::post('shifts/{id}/close', [ShiftController::class, 'close'])->whereNumber('id');
        Route::get('shifts/{id}', [ShiftController::class, 'show'])->whereNumber('id');

        // Invoices — offline-check is a literal, keep it above {id}
        Route::get('invoices/offline-check', [InvoiceController::class, 'offlineCheck']);
        Route::post('invoices', [InvoiceController::class, 'store']);
        Route::get('invoices', [InvoiceController::class, 'index']);
        Route::get('invoices/{id}', [InvoiceController::class, 'show'])->whereNumber('id');
        Route::post('invoices/{id}/cancel', [InvoiceController::class, 'cancel'])->whereNumber('id');

        // Reports
        Route::get('reports/dashboard', [ReportController::class, 'dashboard']);
        Route::get('reports/daily-summary', [ReportController::class, 'dailySummary']);

        // ERPNext sync
        Route::get('sync/status', [SyncController::class, 'status']);
        Route::post('sync/masters', [SyncController::class, 'pullMasters']);
        Route::post('sync/push-invoices', [SyncController::class, 'pushInvoices']);
    });
});
