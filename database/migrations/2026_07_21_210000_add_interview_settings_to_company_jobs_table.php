<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_jobs', function (Blueprint $table): void {
            $table->string('interview_locale', 5)->default('en')->after('difficulty');
            $table->string('question_order', 20)->default('random')->after('interview_locale');
            $table->unsignedSmallInteger('invitation_valid_hours')->default(72)->after('expires_at');
            $table->unsignedTinyInteger('max_resume_count')->default(3)->after('invitation_valid_hours');
            $table->unsignedSmallInteger('interview_duration_minutes')->default(120)->after('max_resume_count');
            $table->unsignedTinyInteger('random_snapshot_count')->default(3)->after('interview_duration_minutes');
            $table->unsignedTinyInteger('liveness_challenge_count')->default(3)->after('random_snapshot_count');
            $table->boolean('identity_verification_required')->default(true)->after('liveness_challenge_count');
            $table->boolean('identity_document_required')->default(true)->after('identity_verification_required');
            $table->boolean('liveness_required')->default(true)->after('identity_document_required');
            $table->boolean('delete_identity_evidence_after_review')->default(true)->after('liveness_required');
            $table->json('interview_instructions')->nullable()->after('delete_identity_evidence_after_review');

            $table->index(['status', 'interview_locale'], 'company_jobs_status_locale_index');
        });
    }

    public function down(): void
    {
        Schema::table('company_jobs', function (Blueprint $table): void {
            $table->dropIndex('company_jobs_status_locale_index');
            $table->dropColumn([
                'interview_locale',
                'question_order',
                'invitation_valid_hours',
                'max_resume_count',
                'interview_duration_minutes',
                'random_snapshot_count',
                'liveness_challenge_count',
                'identity_verification_required',
                'identity_document_required',
                'liveness_required',
                'delete_identity_evidence_after_review',
                'interview_instructions',
            ]);
        });
    }
};
