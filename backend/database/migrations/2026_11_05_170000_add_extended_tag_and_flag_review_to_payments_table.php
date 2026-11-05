<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // The tag settlement actually extended: the paid tag, its replacement, or none. A refund shortens only this one.
            $table->foreignId('extended_tag_id')->nullable()->after('tag_id')->constrained('tags')->nullOnDelete();
            // Reviewing a flag never clears it: flag_reason stays as first written.
            $table->timestamp('flag_reviewed_at')->nullable()->after('flag_reason');
            $table->foreignId('flag_reviewed_by')->nullable()->after('flag_reviewed_at')->constrained('users')->nullOnDelete();
        });

        // Payments settled before this column existed: the paid tag, unless a flag says it was not the one extended.
        DB::table('payments')
            ->where('status', 'paid')
            ->whereNull('extended_tag_id')
            ->where(fn ($q) => $q->whereNull('flag_reason')->orWhereNotIn('flag_reason', ['settled_on_replacement', 'paid_on_revoked_tag']))
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('payments')->where('id', $row->id)->update(['extended_tag_id' => $row->tag_id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('extended_tag_id');
            $table->dropConstrainedForeignId('flag_reviewed_by');
            $table->dropColumn('flag_reviewed_at');
        });
    }
};
