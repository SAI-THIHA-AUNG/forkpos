<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_closing_shift_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('closing_shift_id')->constrained('pos_closing_shifts')->cascadeOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained('taxes')->nullOnDelete();
            $table->string('description', 128);
            $table->decimal('rate', 9, 6)->default(0);
            $table->decimal('amount', 18, 6)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_closing_shift_taxes');
    }
};
