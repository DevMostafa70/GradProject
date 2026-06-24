<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained()->cascadeOnDelete();
            $table->text('question_text');
            $table->enum('type', ['technical', 'behavioral', 'situational', 'general']);
            $table->json('expected_skills')->nullable()->comment('Skills this question evaluates');
            $table->json('evaluation_criteria')->nullable()->comment('Key points to evaluate');
            $table->integer('order');
            $table->enum('status', ['pending', 'answered', 'processing', 'evaluated'])->default('pending');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->index(['interview_id', 'order']);
            $table->index(['interview_id', 'status']);
            $table->unique(['interview_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
