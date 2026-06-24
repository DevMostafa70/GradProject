<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('company_jobs', 'question_bank_id')) {
                $table->foreignId('question_bank_id')->nullable()->after('custom_questions')
                    ->constrained('company_question_banks')->nullOnDelete();
            }
            if (!Schema::hasColumn('company_jobs', 'ai_questions_count')) {
                $table->integer('ai_questions_count')->default(3)->after('question_bank_id')
                    ->comment('Number of AI-generated questions per candidate');
            }
            if (!Schema::hasColumn('company_jobs', 'company_questions_count')) {
                $table->integer('company_questions_count')->default(5)->after('ai_questions_count')
                    ->comment('Number of company questions per candidate from question bank');
            }
            if (!Schema::hasColumn('company_jobs', 'difficulty_distribution')) {
                $table->json('difficulty_distribution')->nullable()->after('company_questions_count')
                    ->comment('Distribution of difficulties: {"easy":2, "medium":2, "hard":1}');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_jobs', function (Blueprint $table) {
            $table->dropForeign(['question_bank_id']);
            $table->dropColumn(['question_bank_id', 'ai_questions_count', 'company_questions_count', 'difficulty_distribution']);
        });
    }
};
