<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('candidate_identity_verifications')) {
            Schema::create('candidate_identity_verifications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_job_id')
                    ->constrained('company_jobs')
                    ->cascadeOnDelete();
                $table->foreignId('candidate_id')
                    ->constrained('candidates')
                    ->cascadeOnDelete();
                $table->foreignId('company_job_candidate_id')
                    ->nullable()
                    ->constrained('company_job_candidates')
                    ->nullOnDelete();
                $table->foreignId('interview_id')
                    ->nullable()
                    ->constrained('interviews')
                    ->nullOnDelete();
                $table->string('status', 30)->default('pending');
                $table->string('document_type', 40)->nullable();
                $table->string('liveness_status', 30)->default('pending');
                $table->decimal('liveness_score', 5, 2)->nullable();
                $table->string('reviewer_type', 30)->nullable();
                $table->unsignedBigInteger('reviewer_id')->nullable();
                $table->text('review_notes')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('evidence_deleted_at')->nullable();
                $table->timestamp('resubmission_requested_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(
                    ['company_job_id', 'candidate_id'],
                    'identity_job_candidate_unique'
                );
                $table->index(
                    ['status', 'reviewed_at'],
                    'identity_status_reviewed_idx'
                );
            });
        }

        if (! Schema::hasTable('candidate_identity_evidences')) {
            Schema::create('candidate_identity_evidences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('verification_id')
                    ->constrained('candidate_identity_verifications')
                    ->cascadeOnDelete();
                $table->foreignId('interview_id')
                    ->nullable()
                    ->constrained('interviews')
                    ->nullOnDelete();
                $table->foreignId('question_id')
                    ->nullable()
                    ->constrained('questions')
                    ->nullOnDelete();
                $table->string('type', 40);
                $table->string('disk', 40)->default('local');
                $table->string('path', 500);
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->char('sha256', 64)->nullable();
                $table->timestamp('captured_at')->nullable();
                $table->json('metadata')->nullable();
                $table->softDeletes();
                $table->timestamps();

                $table->index(
                    ['verification_id', 'type'],
                    'identity_evidence_type_idx'
                );
                $table->index(
                    ['interview_id', 'type'],
                    'interview_evidence_type_idx'
                );
            });
        }

        if (! Schema::hasTable('candidate_liveness_challenges')) {
            Schema::create('candidate_liveness_challenges', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('verification_id')
                    ->constrained('candidate_identity_verifications')
                    ->cascadeOnDelete();
                $table->unsignedTinyInteger('sequence');
                $table->string('challenge_type', 40);
                $table->json('challenge_payload')->nullable();
                $table->string('status', 20)->default('pending');
                $table->decimal('confidence_score', 5, 2)->nullable();
                $table->foreignId('evidence_id')
                    ->nullable()
                    ->constrained('candidate_identity_evidences')
                    ->nullOnDelete();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(
                    ['verification_id', 'sequence'],
                    'liveness_verification_sequence_unique'
                );
            });
        }

        if (! Schema::hasTable('candidate_interview_session_events')) {
            Schema::create('candidate_interview_session_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('interview_id')
                    ->constrained('interviews')
                    ->cascadeOnDelete();
                $table->string('session_instance_id', 100)->nullable();
                $table->string('event_type', 40);
                $table->char('device_fingerprint_hash', 64)->nullable();
                $table->ipAddress('ip_address')->nullable();
                $table->text('user_agent')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();

                $table->index(
                    ['interview_id', 'occurred_at'],
                    'session_events_interview_time_idx'
                );
                $table->index(
                    ['interview_id', 'event_type'],
                    'session_events_interview_type_idx'
                );
            });
        }

        if (! Schema::hasTable('candidate_interview_snapshot_requests')) {
            Schema::create('candidate_interview_snapshot_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('interview_id')
                    ->constrained('interviews')
                    ->cascadeOnDelete();
                $table->foreignId('question_id')
                    ->nullable()
                    ->constrained('questions')
                    ->nullOnDelete();
                $table->char('request_token_hash', 64)
                    ->nullable()
                    ->unique();
                $table->string('status', 20)->default('pending');
                $table->timestamp('due_at');
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('captured_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(
                    ['interview_id', 'status', 'due_at'],
                    'snapshot_interview_status_due_idx'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_interview_snapshot_requests');
        Schema::dropIfExists('candidate_interview_session_events');
        Schema::dropIfExists('candidate_liveness_challenges');
        Schema::dropIfExists('candidate_identity_evidences');
        Schema::dropIfExists('candidate_identity_verifications');
    }
};