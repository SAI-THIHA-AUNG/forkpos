<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Full ERPNext naming series, e.g. "ACC-SINV-.YYYY.-".
            $table->string('invoice_series', 64)->nullable()->after('invoice_prefix');
            // The literal prefix derived from that series is stored in
            // invoice_prefix; widen it for longer custom series (keeping the
            // default so firstOrCreate() can still insert the singleton row).
            $table->string('invoice_prefix', 40)->default('POS-')->change();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('invoice_prefix', 16)->change();
            $table->dropColumn('invoice_series');
        });
    }
};
