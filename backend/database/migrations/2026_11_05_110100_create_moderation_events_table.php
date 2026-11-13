<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_events', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16); // blocked|flagged
            $table->string('surface', 32); // chat|status|default_message|contact_label
            $table->text('text');
            $table->string('source', 16); // blacklist|provider|fallback
            $table->string('reason')->nullable();
            // No foreign key on thread_id: the chat tables arrive in a later task.
            $table->unsignedBigInteger('thread_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_events');
    }
};
