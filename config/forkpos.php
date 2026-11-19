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

    /*
    |--------------------------------------------------------------------------
    | Sign-in
    |--------------------------------------------------------------------------
    | Users pulled from ERPNext sign in once with their ERPNext password, then
    | choose a PIN. PIN sign-in keeps working (offline too) for this many days
    | after the last ERPNext password check; 0 = never ask again.
    */
    'erpnext_reverify_days' => (int) env('FORKPOS_ERPNEXT_REVERIFY_DAYS', 30),

    // Show a "Who are you?" list on the PIN tab (names only). false = type the email instead.
    'login_user_picker' => (bool) env('FORKPOS_LOGIN_USER_PICKER', true),

    // Wrong PINs allowed per user before that user is locked out for 15 minutes.
    'pin_max_attempts' => (int) env('FORKPOS_PIN_MAX_ATTEMPTS', 5),

];
