<?php
// database/migrations/2026_07_06_000005_update_company_permission_templates_created_by_foreign_key.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_permission_templates', function (Blueprint $table) {
            // ✅ 1. حذف المفتاح الأجنبي القديم (إن وجد)
            $table->dropForeign(['created_by']);
        });

        // ✅ 2. تعديل العمود ليشير إلى companies بدلاً من users
        Schema::table('company_permission_templates', function (Blueprint $table) {
            // ✅ تغيير نوع العمود إلى unsignedBigInteger (إذا لزم الأمر)
            $table->unsignedBigInteger('created_by')->nullable()->change();

            // ✅ إضافة المفتاح الأجنبي الجديد指向 companies
            $table->foreign('created_by')
                ->references('id')
                ->on('companies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // ✅ التراجع: إعادة المفتاح الأجنبي إلى users
        Schema::table('company_permission_templates', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }
};
