<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('admins', 'permission_template_id')) {
            Schema::table('admins', function (Blueprint $table) {
                // permission_templates is MyISAM in some existing installations,
                // therefore this is a logical indexed relation without an FK.
                $table->unsignedBigInteger('permission_template_id')
                    ->nullable()
                    ->after('permissions');
                $table->index(
                    'permission_template_id',
                    'admins_permission_template_id_index'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('admins', 'permission_template_id')) {
            Schema::table('admins', function (Blueprint $table) {
                $table->dropIndex('admins_permission_template_id_index');
                $table->dropColumn('permission_template_id');
            });
        }
    }
};
