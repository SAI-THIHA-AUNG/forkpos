<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_code', 32)->unique();
            $table->string('customer_name');
            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups')->nullOnDelete();
            $table->foreignId('territory_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('city', 64)->nullable();
            $table->string('country', 64)->nullable();
            $table->integer('loyalty_points')->default(0);
            $table->decimal('credit_limit', 18, 6)->default(0);
            $table->boolean('is_walk_in')->default(false)->index();
            $table->text('notes')->nullable();
            $table->string('erpnext_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index('customer_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
