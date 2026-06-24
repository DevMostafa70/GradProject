<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('answer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('interview_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 5, 2)->comment('Score out of 10');
            $table->json('criteria_scores')->comment('Scores for each evaluation criteria');
            $table->text('strengths')->nullable();
            $table->text('weaknesses')->nullable();
            $table->text('detailed_feedback')->nullable();
            $table->decimal('clarity_score', 3, 2)->nullable();
            $table->decimal('relevance_score', 3, 2)->nullable();
            $table->decimal('depth_score', 3, 2)->nullable();
            $table->decimal('confidence_score', 3, 2)->nullable();
            $table->decimal('cheating_penalty', 5, 2)->default(0);
            $table->json('ai_raw_response')->nullable();
            $table->timestamps();

            $table->index(['interview_id', 'score']);
            $table->index(['answer_id']);
            $table->unique('answer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');
    }
};
