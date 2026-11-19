<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users.erpnext_login_at — last time ERPNext confirmed this person's password.
 * ERPNext-linked users may sign in with their PIN only while this is recent
 * (config forkpos.erpnext_reverify_days, default 30).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('erpnext_login_at')->nullable()->after('erpnext_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('erpnext_login_at'));
    }
};
