<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_question_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_job_id')->constrained()->cascadeOnDelete();
            $table->json('questions')->comment('Array of questions with type and difficulty');
            $table->integer('total_questions')->default(0);
            $table->timestamps();

            $table->index('company_job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_question_banks');
    }
};
