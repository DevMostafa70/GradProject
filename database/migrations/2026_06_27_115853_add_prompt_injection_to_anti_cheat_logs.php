<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL: تعديل ENUM لإضافة القيمة الجديدة
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE anti_cheat_logs
                MODIFY COLUMN violation_type ENUM(
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
                    'prompt_injection_attempt'
                ) NOT NULL
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE anti_cheat_logs
                MODIFY COLUMN violation_type ENUM(
                    'multiple_faces',
                    'looking_away',
                    'tab_switch',
                    'window_blur',
                    'suspicious_movement',
                    'audio_anomaly',
                    'device_change',
                    'browser_console',
                    'copy_paste_attempt',
                    'screen_capture'
                ) NOT NULL
            ");
        }
    }
};
