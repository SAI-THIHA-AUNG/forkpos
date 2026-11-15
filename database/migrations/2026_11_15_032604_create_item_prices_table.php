<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->string('price_list', 64)->default('Standard Selling')->index();
            $table->string('currency', 8)->default('USD');
            $table->decimal('price_list_rate', 18, 6)->default(0);
            $table->foreignId('uom_id')->nullable()->constrained('uoms')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
            $table->index(['price_list', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_prices');
    }
};
