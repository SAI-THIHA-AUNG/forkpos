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

];
