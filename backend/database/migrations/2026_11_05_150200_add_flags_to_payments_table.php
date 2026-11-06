<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Set when a verified provider payment disagrees with what we asked for; admins review these.
            $table->timestamp('flagged_at')->nullable()->after('refunded_at');
            $table->string('flag_reason', 40)->nullable()->after('flagged_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['flagged_at', 'flag_reason']);
        });
    }
};
