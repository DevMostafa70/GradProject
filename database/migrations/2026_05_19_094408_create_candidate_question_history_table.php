<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_question_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('question_bank_id')->nullable()->comment('Reference to question in bank');
            $table->text('question_text');
            $table->string('question_type', 50)->nullable();
            $table->string('question_difficulty', 20)->default('medium');
            $table->decimal('score', 5, 2)->nullable();
            $table->timestamp('asked_at')->nullable();
            $table->boolean('was_answered')->default(true);
            $table->boolean('was_skipped')->default(false);
            $table->integer('time_to_answer')->nullable()->comment('Seconds spent on this question');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['candidate_id', 'company_job_id']);
            $table->index('question_bank_id');
            $table->unique(['candidate_id', 'company_job_id', 'question_bank_id'], 'unique_candidate_question');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_question_history');
    }
};
