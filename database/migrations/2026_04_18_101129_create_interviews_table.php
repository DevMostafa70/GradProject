<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('position');
            $table->enum('experience_level', ['junior', 'mid', 'senior', 'lead', 'executive']);
            $table->enum('difficulty', ['easy', 'medium', 'hard']);
            $table->json('skills')->comment('Array of required skills');
            $table->integer('number_of_questions')->default(5);
            $table->enum('status', [
                'pending',
                'in_progress',
                'completed',
                'processing_final',
                'completed_with_report',
                'failed'
            ])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interviews');
    }
};
