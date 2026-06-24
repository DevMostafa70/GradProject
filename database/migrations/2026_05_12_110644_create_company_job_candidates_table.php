<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_job_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('interview_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', [
                'pending',
                'in_progress',
                'completed',
                'shortlisted',
                'rejected',
                'hired'
            ])->default('pending');
            $table->decimal('final_score', 5, 2)->nullable()->comment('Score out of 100');
            $table->string('source')->nullable()->comment('Where candidate came from');
            $table->text('company_notes')->nullable()->comment('Private notes from company');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('company_job_id');
            $table->index('candidate_id');
            $table->index('status');
            $table->unique(['company_job_id', 'candidate_id'], 'job_candidate_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_job_candidates');
    }
};
