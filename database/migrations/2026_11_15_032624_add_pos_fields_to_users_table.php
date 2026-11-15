<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('cashier')->index(); // admin | manager | cashier
            $table->string('pos_pin', 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('erpnext_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'pos_pin', 'is_active', 'erpnext_id']);
        });
    }
};
