<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wave_id')->constrained()->restrictOnDelete();
            $table->string('serial', 32)->unique();
            $table->char('token', 16)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('car_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('first_activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('replaced_by_tag_id')->nullable()->constrained('tags')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
