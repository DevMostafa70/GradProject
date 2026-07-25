<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'company_permission_template_id')) {
            Schema::table('users', function (Blueprint $table): void {
                // company_permission_templates can be MyISAM in legacy dumps,
                // therefore keep this as an indexed logical relation instead
                // of adding a cross-engine foreign key.
                $table->unsignedBigInteger('company_permission_template_id')
                    ->nullable()
                    ->after('company_id');
                $table->index(
                    'company_permission_template_id',
                    'users_company_permission_template_id_index'
                );
            });
        }

        // Guard/data repair is intentionally handled by the later
        // 2026_07_25_160000 migration. Company employees are User models and
        // therefore their roles/permissions must use the `user` guard.
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'company_permission_template_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex('users_company_permission_template_id_index');
                $table->dropColumn('company_permission_template_id');
            });
        }
    }
};
