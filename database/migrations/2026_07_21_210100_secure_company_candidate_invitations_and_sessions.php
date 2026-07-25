<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_invitations', function (Blueprint $table): void {
            $table->foreignId('candidate_id')->nullable()->after('company_job_id')
                ->constrained('candidates')->nullOnDelete();
            $table->foreignId('company_job_candidate_id')->nullable()->after('candidate_id')
                ->constrained('company_job_candidates')->nullOnDelete();
            $table->char('token_hash', 64)->nullable()->unique()->after('company_job_candidate_id');
            $table->text('token_ciphertext')->nullable()->after('token_hash');
            $table->string('lifecycle_status', 30)->default('created')->after('status');
            $table->unsignedSmallInteger('send_attempts')->default(0)->after('lifecycle_status');
            $table->text('failure_reason')->nullable()->after('send_attempts');
            $table->timestamp('expires_at')->nullable()->after('sent_at');
            $table->timestamp('last_sent_at')->nullable()->after('expires_at');
            $table->timestamp('opened_at')->nullable()->after('last_sent_at');
            $table->timestamp('claimed_at')->nullable()->after('opened_at');
            $table->timestamp('completed_at')->nullable()->after('claimed_at');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->json('metadata')->nullable()->after('cancelled_at');

            $table->index(['company_job_id', 'lifecycle_status'], 'email_inv_job_lifecycle_idx');
            $table->index(['expires_at', 'lifecycle_status'], 'email_inv_expiry_lifecycle_idx');
        });

        Schema::table('candidates', function (Blueprint $table): void {
            $table->foreignId('email_invitation_id')->nullable()->after('company_job_id')
                ->constrained('email_invitations')->nullOnDelete();
            $table->unsignedTinyInteger('max_resume_count')->default(3)->after('status');
            $table->json('import_metadata')->nullable()->after('answers');

            $table->index(['company_job_id', 'email'], 'candidates_job_email_index');
        });

        Schema::table('company_job_candidates', function (Blueprint $table): void {
            $table->foreignId('email_invitation_id')->nullable()->after('interview_id')
                ->constrained('email_invitations')->nullOnDelete();
            $table->string('identity_status', 30)->default('pending')->after('status');

            $table->index(['company_job_id', 'identity_status'], 'job_candidates_identity_idx');
        });

        Schema::table('interviews', function (Blueprint $table): void {
            $table->foreignId('company_job_id')->nullable()->after('candidate_id')
                ->constrained('company_jobs')->nullOnDelete();
            $table->foreignId('company_job_candidate_id')->nullable()->after('company_job_id')
                ->constrained('company_job_candidates')->nullOnDelete();
            $table->foreignId('email_invitation_id')->nullable()->after('company_job_candidate_id')
                ->constrained('email_invitations')->nullOnDelete();
            $table->string('interview_type', 30)->default('practice')->after('email_invitation_id');
            $table->char('public_session_token_hash', 64)->nullable()->unique()->after('session_token');
            $table->char('browser_secret_hash', 64)->nullable()->after('public_session_token_hash');
            $table->string('session_instance_id', 100)->nullable()->after('active_session_id');
            $table->unsignedTinyInteger('resume_count')->default(0)->after('session_instance_id');
            $table->unsignedTinyInteger('max_resume_count')->default(3)->after('resume_count');
            $table->timestamp('last_heartbeat_at')->nullable()->after('last_activity_at');
            $table->timestamp('last_resume_at')->nullable()->after('last_heartbeat_at');
            $table->timestamp('resume_locked_at')->nullable()->after('last_resume_at');
            $table->string('resume_lock_reason')->nullable()->after('resume_locked_at');
            $table->timestamp('consent_accepted_at')->nullable()->after('resume_lock_reason');
            $table->unsignedTinyInteger('captured_snapshot_count')->default(0)->after('consent_accepted_at');

            $table->index(['interview_type', 'status'], 'interviews_type_status_idx');
            $table->index(['company_job_id', 'candidate_id'], 'interviews_job_candidate_idx');
            $table->index('last_heartbeat_at', 'interviews_last_heartbeat_idx');
        });
    }

    public function down(): void
    {
        Schema::table('interviews', function (Blueprint $table): void {
            $table->dropIndex('interviews_type_status_idx');
            $table->dropIndex('interviews_job_candidate_idx');
            $table->dropIndex('interviews_last_heartbeat_idx');
            $table->dropUnique(['public_session_token_hash']);
            $table->dropConstrainedForeignId('email_invitation_id');
            $table->dropConstrainedForeignId('company_job_candidate_id');
            $table->dropConstrainedForeignId('company_job_id');
            $table->dropColumn([
                'interview_type',
                'public_session_token_hash',
                'browser_secret_hash',
                'session_instance_id',
                'resume_count',
                'max_resume_count',
                'last_heartbeat_at',
                'last_resume_at',
                'resume_locked_at',
                'resume_lock_reason',
                'consent_accepted_at',
                'captured_snapshot_count',
            ]);
        });

        Schema::table('company_job_candidates', function (Blueprint $table): void {
            $table->dropIndex('job_candidates_identity_idx');
            $table->dropConstrainedForeignId('email_invitation_id');
            $table->dropColumn('identity_status');
        });

        Schema::table('candidates', function (Blueprint $table): void {
            $table->dropIndex('candidates_job_email_index');
            $table->dropConstrainedForeignId('email_invitation_id');
            $table->dropColumn(['max_resume_count', 'import_metadata']);
        });

        Schema::table('email_invitations', function (Blueprint $table): void {
            $table->dropIndex('email_inv_job_lifecycle_idx');
            $table->dropIndex('email_inv_expiry_lifecycle_idx');
            $table->dropUnique(['token_hash']);
            $table->dropConstrainedForeignId('company_job_candidate_id');
            $table->dropConstrainedForeignId('candidate_id');
            $table->dropColumn([
                'token_hash',
                'token_ciphertext',
                'lifecycle_status',
                'send_attempts',
                'failure_reason',
                'expires_at',
                'last_sent_at',
                'opened_at',
                'claimed_at',
                'completed_at',
                'cancelled_at',
                'metadata',
            ]);
        });
    }
};
