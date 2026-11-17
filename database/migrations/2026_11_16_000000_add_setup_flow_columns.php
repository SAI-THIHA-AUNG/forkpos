<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setup-flow support:
 *  - users.must_change_password   forces the bootstrap admin to pick a real password
 *  - settings.erpnext_verified_at set only after the API key/secret were proven valid
 *  - pos_profiles.device_enabled  which ERPNext profiles this terminal serves
 *  - sync_states                  per-profile, per-entity sync progress + delta cursor
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('is_active');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->timestamp('erpnext_verified_at')->nullable()->after('erpnext_price_list');
        });

        Schema::table('pos_profiles', function (Blueprint $table) {
            $table->boolean('device_enabled')->default(true)->after('disabled');
        });

        Schema::create('sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_profile_id')->constrained('pos_profiles')->cascadeOnDelete();
            $table->string('entity', 32);
            $table->string('status', 16)->default('pending'); // pending | running | done | failed
            $table->string('cursor', 32)->nullable();          // ERPNext `modified` high-water mark
            $table->unsignedInteger('rows')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['pos_profile_id', 'entity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_states');
        Schema::table('pos_profiles', fn (Blueprint $t) => $t->dropColumn('device_enabled'));
        Schema::table('settings', fn (Blueprint $t) => $t->dropColumn('erpnext_verified_at'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('must_change_password'));
    }
};
