<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Van id: part of every document number this POS profile creates, so two
        // vans can never mint the same invoice / collection number.
        Schema::table('pos_profiles', function (Blueprint $table) {
            $table->string('van_code', 12)->nullable()->unique()->after('name');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('van_code', 12)->nullable()->index()->after('invoice_no');
        });

        // ERPNext's Sales Invoice naming series (pulled) — the base the van id is added to.
        Schema::table('settings', function (Blueprint $table) {
            $table->string('erpnext_naming_series', 64)->nullable();
            $table->json('erpnext_series_options')->nullable();
        });

        // Receivables: every unpaid credit Sales Invoice, whichever van created it.
        // Rows come from two places: ERPNext (pull) and this van's own credit sales.
        Schema::create('credit_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('erpnext_id', 140)->nullable()->unique();      // Sales Invoice name
            $table->foreignId('invoice_id')->nullable()->unique()->constrained('invoices')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('invoice_no', 140)->index();                    // number shown to the salesman
            $table->string('van_code', 12)->nullable();                    // van that made the sale (if known)
            $table->string('origin', 8)->default('erpnext');               // local | erpnext
            $table->date('posting_date')->index();
            $table->date('due_date')->nullable();
            $table->string('currency', 8)->nullable();
            $table->decimal('grand_total', 18, 6)->default(0);
            $table->decimal('paid_amount', 18, 6)->default(0);
            $table->decimal('erp_outstanding', 18, 6)->default(0);         // outstanding as ERPNext last told us
            $table->string('status', 12)->default('open')->index();        // open | settled | cancelled
            $table->json('items')->nullable();                             // line detail for offline viewing
            $table->string('erp_modified', 32)->nullable();                // ERPNext `modified` — delta detection
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
        });

        // Money collected against a credit invoice. Pushed to ERPNext as a Payment Entry.
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('collection_no', 40)->unique();
            $table->foreignId('credit_invoice_id')->constrained('credit_invoices')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('pos_profile_id')->nullable()->constrained('pos_profiles')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('pos_opening_shifts')->nullOnDelete();
            $table->string('van_code', 12)->nullable();
            $table->string('mode_of_payment', 64);
            $table->decimal('amount', 18, 6);
            $table->string('reference_no', 64)->nullable();
            $table->date('reference_date')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('posting_datetime')->index();
            $table->date('posting_date')->index();
            $table->string('offline_id', 64)->nullable()->unique();        // client UUID — idempotent retries
            $table->string('erpnext_id', 140)->nullable();                 // Payment Entry name
            $table->string('sync_status', 16)->default('pending')->index(); // pending | synced | failed
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
        Schema::dropIfExists('credit_invoices');
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn(['erpnext_naming_series', 'erpnext_series_options']));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('van_code'));
        Schema::table('pos_profiles', fn (Blueprint $t) => $t->dropColumn('van_code'));
    }
};
