<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->text('transcription')->nullable();
            $table->string('audio_file_path')->nullable();
            $table->integer('duration_seconds');
            $table->enum('status', ['pending', 'processing', 'evaluated', 'failed'])->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('processing_metadata')->nullable();
            $table->timestamps();

            $table->index(['interview_id', 'status']);
            $table->index(['question_id', 'status']);
            $table->unique('question_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answers');
    }
};  
