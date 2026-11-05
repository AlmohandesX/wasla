<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // Financial records outlive the tag's car and the user: deleting a user only unlinks them.
            $table->foreignId('tag_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);
            $table->string('reference', 100)->unique();
            $table->unsignedInteger('amount_kurus');
            $table->char('currency', 3)->default('TRY');
            $table->string('status', 20)->default('pending'); // pending|paid|failed|refunded
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            // Encrypted by the model cast (ciphertext is long), so these are text and never indexed.
            $table->text('billing_name');
            $table->text('billing_address');
            $table->text('billing_city');
            $table->text('billing_national_id');
            $table->dateTime('legal_accepted_at');
            $table->string('legal_text_version', 20);
            $table->timestamps();
            $table->index(['tag_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
