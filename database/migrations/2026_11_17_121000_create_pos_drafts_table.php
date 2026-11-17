<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_profile_id')->constrained('pos_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // who saved it
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('label', 120);
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('total', 18, 6)->default(0); // for the drafts list only; recalculated on resume
            $table->json('payload'); // the cart: lines, discount, coupon, credit terms
            $table->timestamps();
            $table->index(['pos_profile_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_drafts');
    }
};
