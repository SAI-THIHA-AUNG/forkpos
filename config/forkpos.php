<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bootstrap administrator
    |--------------------------------------------------------------------------
    | Created by `php artisan db:seed` on a fresh install. This is the ONLY
    | seeded account; it must change its password on first login. Override
    | per deployment via .env so no two installs share default credentials.
    */
    'bootstrap_admin' => [
        'name' => env('FORKPOS_ADMIN_NAME', 'Administrator'),
        'email' => env('FORKPOS_ADMIN_EMAIL', 'admin@forkpos.local'),
        'password' => env('FORKPOS_ADMIN_PASSWORD', 'admin12345'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credit sales
    |--------------------------------------------------------------------------
    | Days from the sale date until a credit invoice falls due. Sent to ERPNext
    | as the invoice's due date and used to flag overdue invoices in the app.
    */
    'credit_days' => (int) env('FORKPOS_CREDIT_DAYS', 30),

    /*
    | When a van's ERPNext series (e.g. ACC-SINV-TVP-.YYYY.-) is missing from Sales
    | Invoice's naming_series options, add it automatically on first push. Turn off
    | to manage the series in ERPNext yourself; invoices then use ERPNext's default.
    */
    'register_van_series' => (bool) env('FORKPOS_REGISTER_VAN_SERIES', true),

];
