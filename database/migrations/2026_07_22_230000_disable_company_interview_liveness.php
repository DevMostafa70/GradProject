<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('company_jobs')
            && Schema::hasColumn('company_jobs', 'liveness_required')
            && Schema::hasColumn('company_jobs', 'liveness_challenge_count')
        ) {
            DB::table('company_jobs')->update([
                'liveness_required' => false,
                'liveness_challenge_count' => 0,
                'updated_at' => now(),
            ]);
        }

        if (
            Schema::hasTable('candidate_identity_verifications')
            && Schema::hasColumn('candidate_identity_verifications', 'liveness_status')
        ) {
            DB::table('candidate_identity_verifications')
                ->whereIn('liveness_status', ['pending', 'in_progress', 'failed'])
                ->update([
                    'liveness_status' => 'passed',
                    'liveness_score' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        /*
         * This migration changes a product policy and intentionally does not
         * restore active liveness requirements during rollback.
         */
    }
};
