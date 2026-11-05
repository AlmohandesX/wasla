<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // oil_change|license_renewal
            $table->date('due_on'); // a calendar date in Europe/Istanbul
            $table->timestamps();
            $table->index('due_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
