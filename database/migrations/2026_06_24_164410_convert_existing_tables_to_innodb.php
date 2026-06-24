<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $tables = [
            'admins',
            'admin_logs',
            'answers',
            'anti_cheat_logs',
            'audio_analysis',
            'broadcast_notifications',
            'cache',
            'cache_locks',
            'candidates',
            'candidate_question_history',
            'companies',
            'company_jobs',
            'company_job_candidates',
            'company_question_banks',
            'email_invitations',
            'evaluations',
            'failed_jobs',
            'final_reports',
            'interviews',
            'jobs',
            'job_batches',
            'job_categories',
            'notifications',
            'password_reset_tokens',
            'personal_access_tokens',
            'questions',
            'resumes',
            'sessions',
            'skills',
            'users',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE `%s` ENGINE = InnoDB',
                str_replace('`', '``', $table)
            ));
        }
    }

    public function down(): void
    {
        // Do not convert production tables back to MyISAM.
        // MyISAM does not provide the same transactional safety needed for billing.
    }
};
