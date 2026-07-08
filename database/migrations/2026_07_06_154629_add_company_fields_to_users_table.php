<?php
// database/migrations/2026_07_06_000004_add_company_fields_to_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ✅ إضافة company_id لربط الموظف بشركته
            if (!Schema::hasColumn('users', 'company_id')) {
                $table->foreignId('company_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('companies')
                    ->nullOnDelete();
            }

            // ✅ إضافة is_company_employee لتمييز الموظف عن المستخدم العادي
            if (!Schema::hasColumn('users', 'is_company_employee')) {
                $table->boolean('is_company_employee')->default(false)->after('company_id')
                    ->comment('Is this user an employee of a company');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn(['company_id', 'is_company_employee']);
        });
    }
};
