<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            if (!Schema::hasColumn('interviews', 'report_generation_started_at')) {
                $table->timestamp('report_generation_started_at')->nullable()->after('completed_at')
                    ->comment('When report generation started');
            }

            if (!Schema::hasColumn('interviews', 'report_generation_completed_at')) {
                $table->timestamp('report_generation_completed_at')->nullable()->after('report_generation_started_at')
                    ->comment('When report generation completed');
            }

            if (!Schema::hasColumn('interviews', 'report_generation_attempts')) {
                $table->unsignedInteger('report_generation_attempts')->default(0)->after('report_generation_completed_at')
                    ->comment('Number of report generation attempts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            $table->dropColumn([
                'report_generation_started_at',
                'report_generation_completed_at',
                'report_generation_attempts',
            ]);
        });
    }
};
