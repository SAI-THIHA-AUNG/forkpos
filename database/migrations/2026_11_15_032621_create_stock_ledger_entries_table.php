<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('qty_change', 18, 6);   // negative for outgoing
            $table->decimal('qty_after', 18, 6);
            $table->string('ref_type', 24)->nullable();  // invoice | return | opening | adjustment | sync
            $table->string('ref_id', 32)->nullable();
            $table->date('posting_date')->index();
            $table->timestamps();
            $table->index(['item_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledger_entries');
    }
};
