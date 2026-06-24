<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            // إضافة candidate_id للمقابلات الحقيقية
            if (!Schema::hasColumn('interviews', 'candidate_id')) {
                $table->foreignId('candidate_id')->nullable()->constrained('candidates')->onDelete('cascade');
            }

            // جعل user_id اختياري (للمستخدمين العاديين فقط)
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            $table->dropForeign(['candidate_id']);
            $table->dropColumn('candidate_id');
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
