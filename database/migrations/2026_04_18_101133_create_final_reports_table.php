<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained()->cascadeOnDelete();
            $table->decimal('overall_score', 5, 2);
            $table->decimal('adjusted_score', 5, 2)->comment('Score after cheating penalty');
            $table->decimal('cheating_severity_score', 5, 2)->default(0);
            $table->integer('total_violations')->default(0);
            $table->json('violation_summary')->nullable();
            $table->json('skill_breakdown')->comment('Scores per skill');
            $table->json('question_evaluations')->comment('Summary of all questions');
            $table->text('executive_summary');
            $table->text('strengths_analysis');
            $table->text('improvement_areas');
            $table->text('hiring_recommendation');
            $table->decimal('technical_score', 5, 2)->nullable();
            $table->decimal('communication_score', 5, 2)->nullable();
            $table->decimal('problem_solving_score', 5, 2)->nullable();
            $table->json('ai_raw_response')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['interview_id', 'overall_score']);
            $table->unique('interview_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_reports');
    }
};
