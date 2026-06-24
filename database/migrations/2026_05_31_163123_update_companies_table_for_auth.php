<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // إضافة أعمدة المصادقة
            if (!Schema::hasColumn('companies', 'email')) {
                $table->string('email')->unique()->after('company_name');
            }
            if (!Schema::hasColumn('companies', 'password')) {
                $table->string('password')->after('email');
            }
            if (!Schema::hasColumn('companies', 'status')) {
                $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->after('is_verified');
            }
            if (!Schema::hasColumn('companies', 'admin_notes')) {
                $table->text('admin_notes')->nullable()->after('status');
            }
            if (!Schema::hasColumn('companies', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('admin_notes');
            }
            if (!Schema::hasColumn('companies', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('approved_at');
            }
            if (!Schema::hasColumn('companies', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('approved_by');
            }
            if (!Schema::hasColumn('companies', 'remember_token')) {
                $table->rememberToken()->after('email_verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'email',
                'password',
                'status',
                'admin_notes',
                'approved_at',
                'approved_by',
                'email_verified_at',
                'remember_token'
            ]);
        });
    }
};
