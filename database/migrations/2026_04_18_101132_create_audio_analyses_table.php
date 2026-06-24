<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_analysis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('answer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('interview_id')->constrained()->cascadeOnDelete();
            $table->decimal('speaking_rate', 5, 2)->comment('Words per minute');
            $table->integer('filler_word_count')->default(0);
            $table->json('filler_words_found')->nullable();
            $table->decimal('voice_stability', 3, 2)->nullable();
            $table->decimal('pauses_percentage', 5, 2)->nullable();
            $table->json('sentiment_scores')->nullable();
            $table->decimal('confidence_level', 3, 2)->nullable();
            $table->decimal('hesitation_score', 3, 2)->nullable();
            $table->json('full_analysis_data')->nullable();
            $table->timestamps();

            $table->index(['answer_id']);
            $table->unique('answer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_analysis');
    }
};
