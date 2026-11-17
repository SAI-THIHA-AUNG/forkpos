<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('company', 128)->nullable();
            $table->string('currency', 8)->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('selling_price_list', 64)->nullable();
            $table->boolean('allow_rate_change')->default(true);
            $table->boolean('allow_discount_change')->default(true);
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('tax_inclusive')->default(true);
            $table->decimal('write_off_limit', 18, 6)->default(0);
            $table->decimal('max_discount_percentage', 9, 6)->default(0); // 0 = unlimited
            $table->boolean('ask_for_customer')->default(false);
            $table->boolean('require_shift')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('disabled')->default(false);
            $table->string('erpnext_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_profiles');
    }
};
