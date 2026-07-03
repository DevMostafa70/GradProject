<?php

use App\Jobs\CheckPendingEvaluationsJob;
use App\Console\Commands\CleanupAudioFiles;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;

// ✅ الكود الموجود
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ✅ جدولة التحقق من الأسئلة المعلقة
Schedule::job(new CheckPendingEvaluationsJob())
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('CheckPendingEvaluationsJob failed to run');
    });

// ✅ جدولة تنظيف الملفات الصوتية (يومياً)
Schedule::command('audio:cleanup')
    ->daily()
    ->at('02:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Audio cleanup command failed to run');
    });

// ============================================================
// 🔹 NEW: جدولة Backup التلقائي (يومياً)
// ============================================================
Schedule::command('backup:create --type=scheduled')
    ->daily()
    ->at('03:00') // 3 AM
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Scheduled backup failed to run');
    });

// ✅ تنظيف الـ queues القديمة (اختياري)
Schedule::command('queue:prune-batches --hours=48')
    ->daily()
    ->withoutOverlapping();

Schedule::command('queue:prune-failed')
    ->daily()
    ->withoutOverlapping();

    // ============================================================
// ✅ جدولة تنظيف الإشعارات القديمة
// ============================================================
Schedule::command('notifications:clean --days=30')->daily();
