<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_threads', function (Blueprint $table) {
            $table->id();
            // Threads hang off the car, not the tag, so they stay with the old owner on transfer.
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visitor_session_id')->constrained()->cascadeOnDelete();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();

            $table->unique(['car_id', 'visitor_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_threads');
    }
};
