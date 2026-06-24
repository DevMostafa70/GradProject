<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('company_jobs', 'questions_source')) {
                $table->enum('questions_source', ['ai_only', 'mixed', 'company_only'])
                    ->default('mixed')
                    ->after('custom_questions')
                    ->comment('ai_only: all questions from AI, mixed: AI + company questions, company_only: only company questions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_jobs', function (Blueprint $table) {
            $table->dropColumn('questions_source');
        });
    }
};
