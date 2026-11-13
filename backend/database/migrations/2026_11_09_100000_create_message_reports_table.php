<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports move from a timestamp on the message to their own table, so the admin queue knows who reported a
 * message and can record what was done about it. Only the other side of a chat can report a message, so a
 * message has at most one report. Messages also gain hidden_at: a moderator can remove a message for both sides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_reports', function (Blueprint $table) {
            $table->id();
            // Reports follow their message out of the database (90-day purge, account erasure).
            $table->foreignId('message_id')->unique()->constrained('chat_messages')->cascadeOnDelete();
            $table->string('reporter_type', 8); // owner|visitor
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reporter_visitor_session_id')->nullable()->constrained('visitor_sessions')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution', 16)->nullable(); // dismissed|hidden|visitor_blocked|owner_suspended
            $table->timestamps();

            $table->index(['reviewed_at', 'created_at']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('read_at');
        });

        // Existing reports: a visitor message was reported by the car owner, an owner message by the visitor.
        DB::table('chat_messages')
            ->join('chat_threads', 'chat_threads.id', '=', 'chat_messages.thread_id')
            ->join('cars', 'cars.id', '=', 'chat_threads.car_id')
            ->whereNotNull('chat_messages.reported_at')
            ->orderBy('chat_messages.id')
            ->select('chat_messages.id', 'chat_messages.sender', 'chat_messages.reported_at', 'cars.user_id', 'chat_threads.visitor_session_id')
            ->chunk(500, function ($rows) {
                DB::table('message_reports')->insert($rows->map(fn ($row) => [
                    'message_id' => $row->id,
                    'reporter_type' => $row->sender === 'visitor' ? 'owner' : 'visitor',
                    'reporter_user_id' => $row->sender === 'visitor' ? $row->user_id : null,
                    'reporter_visitor_session_id' => $row->sender === 'visitor' ? null : $row->visitor_session_id,
                    'created_at' => $row->reported_at,
                    'updated_at' => $row->reported_at,
                ])->all());
            });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('reported_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->timestamp('reported_at')->nullable()->after('read_at');
        });

        DB::table('message_reports')->orderBy('id')->each(function ($report) {
            DB::table('chat_messages')->where('id', $report->message_id)->update(['reported_at' => $report->created_at]);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('hidden_at');
        });
        Schema::dropIfExists('message_reports');
    }
};
