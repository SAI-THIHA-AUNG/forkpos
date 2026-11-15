<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 32)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('pos_profile_id')->constrained('pos_profiles')->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('pos_opening_shifts')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // cashier
            $table->string('status', 16)->default('draft')->index(); // draft|paid|partly_paid|unpaid|returned|cancelled
            $table->timestamp('posting_datetime')->index();
            $table->date('posting_date')->index();
            $table->boolean('is_return')->default(false)->index();
            $table->foreignId('return_against_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->decimal('total_qty', 18, 6)->default(0);
            $table->decimal('net_total', 18, 6)->default(0);      // items total after discount, before tax
            $table->decimal('tax_total', 18, 6)->default(0);
            $table->decimal('discount_total', 18, 6)->default(0); // sum of item + invoice-level discounts
            $table->decimal('grand_total', 18, 6)->default(0);    // net + tax
            $table->decimal('rounded_total', 18, 6)->default(0);
            $table->decimal('paid_amount', 18, 6)->default(0);
            $table->decimal('change_amount', 18, 6)->default(0);
            $table->decimal('outstanding_amount', 18, 6)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('price_list', 64)->default('Standard Selling');
            $table->string('coupon_code', 32)->nullable();
            $table->decimal('coupon_discount', 18, 6)->default(0);
            $table->integer('loyalty_points_earned')->default(0);
            $table->integer('loyalty_points_spent')->default(0);
            $table->string('offline_id', 64)->nullable()->unique(); // client UUID for offline idempotency
            $table->text('remarks')->nullable();
            $table->string('erpnext_id')->nullable();
            $table->string('sync_status', 16)->default('pending')->index(); // pending|synced|failed|skipped
            $table->timestamps();
            $table->index(['posting_date', 'status']);
            $table->index(['shift_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
