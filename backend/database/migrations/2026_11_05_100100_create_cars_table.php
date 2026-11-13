<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('model', 120);
            $table->string('plate', 32);
            $table->string('photo_path')->nullable();
            $table->string('photo_disk', 32)->nullable();
            $table->string('default_message', 280)->nullable();
            $table->boolean('stealth_enabled')->default(false);
            $table->boolean('chats_paused')->default(false);
            $table->string('status_text', 140)->nullable();
            $table->timestamp('status_expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cars');
    }
};
