<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class CleanOldNotifications extends Command
{
    protected $signature = 'notifications:clean {--days=30 : عدد الأيام للاحتفاظ بالإشعارات}';
    protected $description = 'حذف الإشعارات الأقدم من عدد محدد من الأيام';

    public function handle()
    {
        $days = (int) $this->option('days');
        $cutoffDate = now()->subDays($days);

        $deleted = DatabaseNotification::where('created_at', '<', $cutoffDate)->delete();

        $this->info("✅ تم حذف {$deleted} إشعار أقدم من {$days} يوم");

        // تسجيل في Log النظام
        \Illuminate\Support\Facades\Log::info('CleanOldNotifications', [
            'deleted' => $deleted,
            'days' => $days,
            'cutoff_date' => $cutoffDate->toDateTimeString(),
        ]);
    }
}
