<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // A string column makes future violation types possible without a new
        // database enum migration for every browser-security event.
        DB::statement(
            'ALTER TABLE anti_cheat_logs MODIFY violation_type VARCHAR(64) NOT NULL'
        );

        Schema::table('anti_cheat_logs', function (Blueprint $table) {
            $table->string('event_key', 100)->nullable()->after('interview_id');
            $table->foreignId('question_id')
                ->nullable()
                ->after('event_key')
                ->constrained('questions')
                ->nullOnDelete();
            $table->foreignId('answer_id')
                ->nullable()
                ->after('question_id')
                ->constrained('answers')
                ->nullOnDelete();
            $table->string('source', 40)
                ->default('browser_security')
                ->after('severity_weight');

            $table->index(['interview_id', 'question_id'], 'anti_cheat_interview_question_idx');
            $table->index(['interview_id', 'answer_id'], 'anti_cheat_interview_answer_idx');
        });

        DB::table('anti_cheat_logs')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('anti_cheat_logs')
                        ->where('id', $row->id)
                        ->whereNull('event_key')
                        ->update(['event_key' => (string) Str::uuid()]);
                }
            });

        Schema::table('anti_cheat_logs', function (Blueprint $table) {
            $table->string('event_key', 100)->nullable(false)->change();
            $table->unique('event_key', 'anti_cheat_event_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('anti_cheat_logs', function (Blueprint $table) {
            $table->dropUnique('anti_cheat_event_key_unique');
            $table->dropIndex('anti_cheat_interview_question_idx');
            $table->dropIndex('anti_cheat_interview_answer_idx');
            $table->dropConstrainedForeignId('question_id');
            $table->dropConstrainedForeignId('answer_id');
            $table->dropColumn(['event_key', 'source']);
        });

        $legacyTypes = [
            'multiple_faces',
            'looking_away',
            'tab_switch',
            'window_blur',
            'suspicious_movement',
            'audio_anomaly',
            'device_change',
            'browser_console',
            'copy_paste_attempt',
            'screen_capture',
        ];

        DB::table('anti_cheat_logs')->whereNotIn('violation_type', $legacyTypes)->delete();

        DB::statement(
            "ALTER TABLE anti_cheat_logs MODIFY violation_type ENUM('multiple_faces','looking_away','tab_switch','window_blur','suspicious_movement','audio_anomaly','device_change','browser_console','copy_paste_attempt','screen_capture') NOT NULL"
        );
    }
};
