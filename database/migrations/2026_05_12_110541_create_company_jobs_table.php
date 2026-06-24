<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->json('required_skills');
            $table->json('custom_questions')->nullable()->comment('Custom questions from company');
            $table->integer('number_of_questions')->default(5);
            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('medium');
            $table->integer('max_candidates')->nullable()->comment('Maximum number of candidates allowed');
            $table->timestamp('expires_at')->nullable()->comment('Job expiry date');
            $table->boolean('hide_score_from_candidate')->default(true);
            $table->string('unique_token')->unique()->comment('Unique link for candidates');
            $table->enum('status', ['active', 'closed', 'expired'])->default('active');
            $table->timestamps();

            $table->index('company_id');
            $table->index('unique_token');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_jobs');
    }
};
