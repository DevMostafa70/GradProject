<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_usage_counters')) {
            return;
        }

        Schema::create('company_usage_counters', function (Blueprint $table): void {
                        $table->engine('InnoDB');

            $table->id();

            $table
                ->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            $table->unsignedInteger('jobs_created')->default(0);
            $table->unsignedInteger('active_jobs_count')->default(0);
            $table->unsignedInteger('candidates_imported')->default(0);
            $table->unsignedInteger('interviews_started')->default(0);
            $table->unsignedInteger('interviews_completed')->default(0);
            $table->unsignedInteger('final_reports_generated')->default(0);
            $table->unsignedInteger('cv_reviews_used')->default(0);
            $table->unsignedInteger('emails_sent')->default(0);

            $table->timestamps();

            $table->unique(
                ['company_id', 'period_start', 'period_end'],
                'company_usage_period_unique'
            );

            $table->index(['company_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_usage_counters');
    }
};
