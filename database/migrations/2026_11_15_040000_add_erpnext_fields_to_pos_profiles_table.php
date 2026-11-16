<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the bare-minimum ERPNext POS Profile fields to `pos_profiles`.
 *
 * Design principle: ERPNext is the source of truth. We store ONLY the fields
 * the checkout flow actually reads as real columns, plus the full ERPNext
 * doc snapshot in `erpnext_data` (JSON) so anything else is one accessor
 * away without a migration. Per-field columns for every ERPNext flag are
 * explicitly avoided — that is what caused the looping earlier.
 *
 * Columns already present on the table (from 2026_11_15_032606) and reused:
 *   - erpnext_id        (the ERPNext POS Profile `name`, e.g. "Test Van POS")
 *   - company           (ERPNext company string, e.g. "My Company")
 *   - currency          (ISO 4217, e.g. "MMK")
 *   - warehouse_id      (FK to local warehouses table — populated by pullWarehouse)
 *   - selling_price_list
 *   - write_off_limit   (decimal)
 *
 * New columns this migration adds:
 *   - country            (ERPNext country string; needed when ERPNext doesn't
 *                         return currency and we have to infer it from country)
 *   - write_off_account  (account head string for write-offs)
 *   - cost_center        (cost center string)
 *   - erpnext_data       (JSON — full ERPNext doc, the source of truth)
 *   - synced_at          (timestamp of last successful sync)
 *
 * Idempotent: every column is guarded with Schema::hasColumn so it is safe
 * to run on a database that already had a partial version of this migration
 * applied (e.g. if the online AI's earlier patch was partially applied).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_profiles', 'country')) {
                $table->string('country', 64)->nullable()->after('company');
            }
            if (! Schema::hasColumn('pos_profiles', 'write_off_account')) {
                $table->string('write_off_account', 140)->nullable()->after('write_off_limit');
            }
            if (! Schema::hasColumn('pos_profiles', 'cost_center')) {
                $table->string('cost_center', 140)->nullable()->after('write_off_account');
            }
            if (! Schema::hasColumn('pos_profiles', 'erpnext_data')) {
                $table->json('erpnext_data')->nullable()->after('cost_center');
            }
            if (! Schema::hasColumn('pos_profiles', 'synced_at')) {
                $table->timestamp('synced_at')->nullable()->after('erpnext_data');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_profiles', function (Blueprint $table) {
            $table->dropColumn(['country', 'write_off_account', 'cost_center', 'erpnext_data', 'synced_at']);
        });
    }
};
