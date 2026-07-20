<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('anti_cheat_logs', 'event_key')) {
            return;
        }

        /*
         * بعض المسارات القديمة مثل إرسال الإجابة تحفظ مخالفات
         * MediaPipe بدون event_key.
         *
         * نجعل الحقل nullable لضمان التوافق إلى أن يتم توحيد
         * جميع عمليات الحفظ من خلال AntiCheatController.
         */
        DB::statement(
            'ALTER TABLE `anti_cheat_logs`
             MODIFY `event_key` VARCHAR(100) NULL'
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('anti_cheat_logs', 'event_key')) {
            return;
        }

        /*
         * لا يمكن إعادة الحقل إلى NOT NULL قبل منح السجلات القديمة
         * event_key فريدًا.
         */
        DB::table('anti_cheat_logs')
            ->whereNull('event_key')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('anti_cheat_logs')
                        ->where('id', $row->id)
                        ->update([
                            'event_key' => (string) Str::uuid(),
                        ]);
                }
            });

        DB::statement(
            'ALTER TABLE `anti_cheat_logs`
             MODIFY `event_key` VARCHAR(100) NOT NULL'
        );
    }
};
