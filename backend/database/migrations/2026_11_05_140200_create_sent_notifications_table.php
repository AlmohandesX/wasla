<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 24); // reminder|expiry
            $table->unsignedBigInteger('subject_id');
            $table->string('marker', 64); // which step, for example d7, so a run is never repeated
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['kind', 'subject_id', 'marker']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_notifications');
    }
};
