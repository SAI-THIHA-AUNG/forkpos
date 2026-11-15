<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('description', 128)->nullable();
            $table->decimal('rate', 9, 6)->default(0); // percent
            $table->boolean('included_in_price')->default(true);
            $table->string('account_name', 128)->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('erpnext_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
