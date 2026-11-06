<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique(); // hash of the cookie token, never the token itself
            $table->char('ip_hash', 64); // HMAC of the IP with APP_KEY, never the raw IP
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('ip_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_sessions');
    }
};
