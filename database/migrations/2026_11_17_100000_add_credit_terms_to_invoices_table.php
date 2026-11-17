<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Credit sale: the customer pays later. `due_date` is when the balance is due,
            // `credit_days` remembers the chosen term (7 / 14 / 30) when one was used.
            $table->boolean('is_credit')->default(false)->after('outstanding_amount')->index();
            $table->date('due_date')->nullable()->after('is_credit')->index();
            $table->unsignedSmallInteger('credit_days')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['is_credit', 'due_date', 'credit_days']);
        });
    }
};
