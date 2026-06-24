<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('candidates'); // حذف القديم

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->foreignId('company_job_id')->constrained('company_jobs')->onDelete('cascade');
            $table->string('invitation_token')->unique();
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->json('answers')->nullable(); // تخزين الإجابات كـ JSON
            $table->timestamps();

            $table->index('email');
            $table->index('invitation_token');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
