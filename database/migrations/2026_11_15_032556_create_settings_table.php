<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('currency_symbol', 8)->nullable();
            $table->unsignedTinyInteger('currency_precision')->default(2);
            $table->boolean('tax_inclusive_prices')->default(true);
            $table->boolean('allow_negative_stock')->default(false);
            $table->string('invoice_prefix', 16)->default('POS-');
            $table->unsignedBigInteger('next_invoice_number')->default(1);
            $table->text('receipt_footer')->nullable();
            $table->text('receipt_header')->nullable();
            // ERPNext connector settings (local AI coder / real ERPNext API)
            $table->string('erpnext_url')->nullable();
            $table->string('erpnext_api_key')->nullable();
            $table->text('erpnext_api_secret')->nullable();
            $table->string('erpnext_company')->nullable();
            $table->string('erpnext_price_list')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
