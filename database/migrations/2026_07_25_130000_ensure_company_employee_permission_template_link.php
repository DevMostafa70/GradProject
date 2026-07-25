<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent repair for installations where the earlier migration was
        // skipped or copied after its migration record had already been added.
        if (! Schema::hasColumn('users', 'company_permission_template_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedBigInteger('company_permission_template_id')
                    ->nullable()
                    ->after('company_id');
                $table->index(
                    'company_permission_template_id',
                    'users_company_permission_template_id_index'
                );
            });
        }

        // Do not move permissions between guards here. The dedicated
        // normalization migration creates the complete user-guard catalogue
        // first, then safely maps legacy assignments by permission name.
    }

    public function down(): void
    {
        // The column may belong to the older migration and is required by the
        // application, so this repair migration intentionally leaves it intact.
    }
};
