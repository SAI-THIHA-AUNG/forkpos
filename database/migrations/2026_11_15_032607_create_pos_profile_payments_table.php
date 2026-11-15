<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_profile_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_profile_id')->constrained('pos_profiles')->cascadeOnDelete();
            $table->string('mode_of_payment', 64);
            $table->boolean('is_default')->default(false);
            $table->boolean('allow_edit_amount')->default(true);
            $table->timestamps();
            $table->unique(['pos_profile_id', 'mode_of_payment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_profile_payments');
    }
};
