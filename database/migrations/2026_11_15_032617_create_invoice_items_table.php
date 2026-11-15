<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('item_code', 64);
            $table->string('item_name');
            $table->string('uom', 64)->default('Unit');
            $table->decimal('conversion_factor', 18, 6)->default(1);
            $table->decimal('qty', 18, 6)->default(0);
            $table->decimal('price_list_rate', 18, 6)->default(0);
            $table->decimal('rate', 18, 6)->default(0);          // per-unit price after discount, tax-exclusive
            $table->decimal('discount_percentage', 9, 6)->default(0);
            $table->decimal('discount_amount', 18, 6)->default(0);
            $table->decimal('amount', 18, 6)->default(0);        // qty * rate - discount_amount
            $table->foreignId('tax_id')->nullable()->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_rate', 9, 6)->default(0);
            $table->decimal('tax_amount', 18, 6)->default(0);
            $table->boolean('tax_included')->default(true);
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('batch_no', 64)->nullable();
            $table->text('serial_no')->nullable();
            $table->string('note', 191)->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
