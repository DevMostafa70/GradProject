<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_job_candidates', function (Blueprint $table) {
            // حذف المفتاح الأجنبي القديم
            $table->dropForeign(['candidate_id']);

            // إضافة مفتاح أجنبي جديد candidates.id
            $table->foreign('candidate_id')->references('id')->on('candidates')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('company_job_candidates', function (Blueprint $table) {
            $table->dropForeign(['candidate_id']);
            $table->foreign('candidate_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
