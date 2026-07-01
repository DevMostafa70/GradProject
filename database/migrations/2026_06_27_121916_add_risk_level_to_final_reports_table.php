<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('final_reports', 'cheating_risk_level')) {
                $table->string('cheating_risk_level', 20)->nullable()->after('cheating_severity_score')
                    ->comment('low, medium, high, critical');
            }

            if (!Schema::hasColumn('final_reports', 'cheating_risk_description')) {
                $table->text('cheating_risk_description')->nullable()->after('cheating_risk_level')
                    ->comment('Detailed description of the cheating risk level');
            }

            if (!Schema::hasColumn('final_reports', 'cheating_recommendation')) {
                $table->text('cheating_recommendation')->nullable()->after('cheating_risk_description')
                    ->comment('Recommendation based on cheating risk level');
            }

            if (!Schema::hasColumn('final_reports', 'violation_count_by_type')) {
                $table->json('violation_count_by_type')->nullable()->after('cheating_recommendation')
                    ->comment('Count of violations by type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('final_reports', function (Blueprint $table) {
            $table->dropColumn([
                'cheating_risk_level',
                'cheating_risk_description',
                'cheating_recommendation',
                'violation_count_by_type',
            ]);
        });
    }
};
