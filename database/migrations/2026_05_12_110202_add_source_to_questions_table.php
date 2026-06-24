<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            // لتحديد مصدر السؤال (من النظام تلقائياً أم أضافته الشركة)

            if (!Schema::hasColumn('questions', 'source')) {
                $table->enum('source', ['system', 'company'])->default('system')->after('evaluation_criteria');
            }

            //لربط السؤال بوظيفة محددة (مثلاً أسئلة مخصصة لمقابلة وظيفة معينة)
            if (!Schema::hasColumn('questions', 'job_id')) {
                $table->foreignId('job_id')->nullable()->after('interview_id')->constrained()->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn(['source', 'job_id']);
        });
    }
};
