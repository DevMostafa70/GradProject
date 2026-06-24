<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('file_path')->comment('Original file path');
            $table->string('file_name')->comment('Original file name');
            $table->string('file_type', 50)->comment('pdf, docx, txt');
            $table->longText('extracted_text')->nullable()->comment('Text extracted from CV');
            $table->json('analysis_result')->nullable()->comment('AI analysis results');
            $table->json('improved_content')->nullable()->comment('AI suggested improved content');
            $table->string('target_position')->nullable()->comment('Position user is targeting');
            $table->json('target_skills')->nullable()->comment('Skills user wants to highlight');
            $table->integer('ats_score')->nullable()->comment('ATS compatibility score 0-100');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resumes');
    }
};
