<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_closing_shift_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('closing_shift_id')->constrained('pos_closing_shifts')->cascadeOnDelete();
            $table->string('mode_of_payment', 64);
            $table->decimal('opening_amount', 18, 6)->default(0);
            $table->decimal('collected_amount', 18, 6)->default(0); // paid by customers during shift
            $table->decimal('cash_in_amount', 18, 6)->default(0);   // cash movement in
            $table->decimal('cash_out_amount', 18, 6)->default(0);  // cash movement out
            $table->decimal('expected_amount', 18, 6)->default(0);  // opening + collected + in - out
            $table->decimal('counted_amount', 18, 6)->default(0);
            $table->decimal('difference', 18, 6)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_closing_shift_payments');
    }
};
