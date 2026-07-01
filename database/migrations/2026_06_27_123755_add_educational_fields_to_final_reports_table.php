<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('final_reports', 'educational_summary')) {
                $table->text('educational_summary')->nullable()->after('hiring_recommendation')
                    ->comment('Educational summary of performance');
            }

            if (!Schema::hasColumn('final_reports', 'key_strengths')) {
                $table->json('key_strengths')->nullable()->after('educational_summary')
                    ->comment('Key strengths with examples');
            }

            if (!Schema::hasColumn('final_reports', 'key_weaknesses')) {
                $table->json('key_weaknesses')->nullable()->after('key_strengths')
                    ->comment('Key weaknesses with examples');
            }

            if (!Schema::hasColumn('final_reports', 'improvement_plan')) {
                $table->json('improvement_plan')->nullable()->after('key_weaknesses')
                    ->comment('Step-by-step improvement plan');
            }

            if (!Schema::hasColumn('final_reports', 'learning_resources')) {
                $table->json('learning_resources')->nullable()->after('improvement_plan')
                    ->comment('Suggested learning resources');
            }

            if (!Schema::hasColumn('final_reports', 'key_takeaways')) {
                $table->json('key_takeaways')->nullable()->after('learning_resources')
                    ->comment('Key lessons learned');
            }

            if (!Schema::hasColumn('final_reports', 'next_steps')) {
                $table->json('next_steps')->nullable()->after('key_takeaways')
                    ->comment('Recommended next steps');
            }
        });
    }

    public function down(): void
    {
        Schema::table('final_reports', function (Blueprint $table) {
            $table->dropColumn([
                'educational_summary',
                'key_strengths',
                'key_weaknesses',
                'improvement_plan',
                'learning_resources',
                'key_takeaways',
                'next_steps',
            ]);
        });
    }
};
