<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anti_cheat_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained()->cascadeOnDelete();
            $table->enum('violation_type', [
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
            ]);
            $table->timestamp('violation_timestamp');
            $table->decimal('duration_seconds', 8, 2)->default(0);
            $table->decimal('confidence_score', 3, 2);
            $table->json('metadata');
            $table->decimal('severity_weight', 3, 2)->default(1.0);
            $table->timestamps();

            $table->index(['interview_id', 'violation_type']);
            $table->index(['interview_id', 'violation_timestamp']);
            $table->index('severity_weight');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anti_cheat_logs');
    }
};
