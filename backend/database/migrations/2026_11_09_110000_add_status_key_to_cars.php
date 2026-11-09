<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quick status (one of the app's ready-made messages) is stored as a key and translated when it is shown, so a
 * visitor reads it in the scan page's language rather than the owner's. status_text keeps the owner's own words
 * for a status they typed, and the Turkish wording of a preset as a fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->string('status_key', 32)->nullable()->after('status_text');
        });
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->dropColumn('status_key');
        });
    }
};
