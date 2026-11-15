<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_profile_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_profile_id')->constrained('pos_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['pos_profile_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_profile_users');
    }
};
