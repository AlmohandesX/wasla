<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $table->string('sender', 8); // visitor|owner
            $table->string('body', 500);
            $table->string('moderation_status', 8)->default('passed'); // passed|flagged
            $table->timestamp('read_at')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'id']);
            $table->index('created_at'); // 90-day retention sweep
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
