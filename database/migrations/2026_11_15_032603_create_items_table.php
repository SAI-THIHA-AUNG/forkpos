<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('item_code', 64)->unique();
            $table->string('item_name');
            $table->foreignId('item_group_id')->nullable()->constrained('item_groups')->nullOnDelete();
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->foreignId('stock_uom_id')->nullable()->constrained('uoms')->nullOnDelete();
            $table->foreignId('sales_uom_id')->nullable()->constrained('uoms')->nullOnDelete();
            $table->boolean('is_stock_item')->default(true);
            $table->boolean('has_variants')->default(false);
            $table->foreignId('variant_of_id')->nullable()->constrained('items')->nullOnDelete();
            $table->decimal('standard_rate', 18, 6)->default(0);
            $table->boolean('allow_discount')->default(true);
            $table->decimal('min_order_qty', 18, 6)->default(0);
            $table->decimal('max_order_qty', 18, 6)->default(0);
            $table->string('brand', 64)->nullable();
            $table->decimal('weight', 18, 6)->nullable();
            $table->boolean('disabled')->default(false)->index();
            $table->string('erpnext_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index('item_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
