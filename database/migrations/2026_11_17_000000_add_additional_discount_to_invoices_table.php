<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Invoice-level ("total") discount, applied on top of any line discounts.
            $table->decimal('additional_discount_percentage', 9, 6)->default(0)->after('discount_total');
            $table->decimal('additional_discount_amount', 18, 6)->default(0)->after('additional_discount_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['additional_discount_percentage', 'additional_discount_amount']);
        });
    }
};
