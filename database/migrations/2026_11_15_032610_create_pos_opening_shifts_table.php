<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_opening_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('shift_no', 24)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('pos_profile_id')->constrained('pos_profiles')->restrictOnDelete();
            $table->string('status', 16)->default('open')->index(); // open | closed
            $table->text('opening_note')->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['pos_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_opening_shifts');
    }
};
