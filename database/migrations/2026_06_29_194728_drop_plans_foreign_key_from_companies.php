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

        Schema::table('companies', function (Blueprint $table) {
            // حذف المفتاح الأجنبي إذا كان موجوداً
            $table->dropForeign(['selected_plan_id']);
        });

        // إعادة تفعيل فحص المفاتيح الأجنبية
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        Schema::table('companies', function (Blueprint $table) {
            $table->foreign('selected_plan_id')
                ->references('id')
                ->on('plans')
                ->nullOnDelete();
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
