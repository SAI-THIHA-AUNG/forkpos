<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_opening_shift_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opening_shift_id')->constrained('pos_opening_shifts')->cascadeOnDelete();
            $table->string('mode_of_payment', 64);
            $table->decimal('opening_amount', 18, 6)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_opening_shift_balances');
    }
};
