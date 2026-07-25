<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spatie's HasPermissions trait defines a permissions() relationship and
     * reads it through $model->permissions. A physical admins.permissions
     * attribute shadows that relationship and turns it into an array/string.
     * Preserve the old JSON snapshot under a non-conflicting name.
     */
    public function up(): void
    {
        if (! Schema::hasTable('admins')) {
            return;
        }

        if (! Schema::hasColumn('admins', 'legacy_permissions')) {
            Schema::table('admins', function (Blueprint $table): void {
                $table->json('legacy_permissions')->nullable()->after('role');
            });
        }

        if (Schema::hasColumn('admins', 'permissions')) {
            DB::table('admins')
                ->whereNull('legacy_permissions')
                ->whereNotNull('permissions')
                ->update(['legacy_permissions' => DB::raw('permissions')]);

            Schema::table('admins', function (Blueprint $table): void {
                $table->dropColumn('permissions');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('admins')) {
            return;
        }

        if (! Schema::hasColumn('admins', 'permissions')) {
            Schema::table('admins', function (Blueprint $table): void {
                $table->json('permissions')->nullable()->after('role');
            });
        }

        if (Schema::hasColumn('admins', 'legacy_permissions')) {
            DB::table('admins')
                ->whereNull('permissions')
                ->whereNotNull('legacy_permissions')
                ->update(['permissions' => DB::raw('legacy_permissions')]);

            Schema::table('admins', function (Blueprint $table): void {
                $table->dropColumn('legacy_permissions');
            });
        }
    }
};
