<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each blacklist term records its language (ar, en or tr) so admins can sort and filter the list. Matching
 * stays language-blind: a message's language is not known, so every active term is checked against it.
 * match_mode also gains "prefix" (a word starting with the term), which needs no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blacklist_terms', function (Blueprint $table) {
            // Existing rows were the Turkish-first starter list; BlacklistSeeder corrects the English ones.
            $table->string('language', 2)->default('tr')->after('normalized');
            $table->index('language');
        });
    }

    public function down(): void
    {
        Schema::table('blacklist_terms', function (Blueprint $table) {
            $table->dropIndex(['language']);
            $table->dropColumn('language');
        });
    }
};
