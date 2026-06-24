<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // تعطيل فحص المفاتيح الأجنبية مؤقتاً
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // حذف المفتاح الأجنبي القديم
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        // إعادة إنشاء المفتاح الأجنبي مع cascade
        Schema::table('companies', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });

        // إعادة تفعيل فحص المفاتيح الأجنبية
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')
                ->on('users');
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
