<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_closing_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('closing_no', 24)->unique();
            $table->foreignId('opening_shift_id')->unique()->constrained('pos_opening_shifts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('pos_profile_id')->constrained('pos_profiles')->restrictOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->decimal('grand_total', 18, 6)->default(0);
            $table->decimal('net_total', 18, 6)->default(0);
            $table->decimal('total_quantity', 18, 6)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_closing_shifts');
    }
};
