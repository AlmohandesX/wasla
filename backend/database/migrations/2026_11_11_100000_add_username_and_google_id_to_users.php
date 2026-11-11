<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admins sign in to /admin with a username and have no email; owners who sign in with Google may have no password.
 * Roles stay in the existing string column: super_admin, admin, owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 32)->nullable()->unique()->after('name');
            $table->string('google_id', 64)->nullable()->unique()->after('email');
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropUnique(['google_id']);
            $table->dropColumn(['username', 'google_id']);
        });
    }
};
