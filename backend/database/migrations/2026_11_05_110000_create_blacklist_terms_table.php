<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blacklist_terms', function (Blueprint $table) {
            $table->id();
            $table->string('term', 120);
            $table->string('normalized', 120);
            $table->string('match_mode', 16)->default('word'); // word|substring
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['normalized', 'match_mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blacklist_terms');
    }
};
