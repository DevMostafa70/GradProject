<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $tables = config('permission.table_names');
        $permissions = $tables['permissions'] ?? 'permissions';
        $roles = $tables['roles'] ?? 'roles';
        $rolePermissions = $tables['role_has_permissions'] ?? 'role_has_permissions';
        $modelPermissions = $tables['model_has_permissions'] ?? 'model_has_permissions';
        $modelRoles = $tables['model_has_roles'] ?? 'model_has_roles';

        foreach ([$permissions, $roles, $rolePermissions, $modelPermissions, $modelRoles, 'company_permission_templates'] as $table) {
            if (Schema::hasTable($table)) {
                DB::statement(sprintf('ALTER TABLE `%s` ENGINE=InnoDB', str_replace('`', '``', $table)));
            }
        }

        // Remove orphan pivot rows before restoring referential integrity.
        if (Schema::hasTable($rolePermissions)) {
            DB::statement("DELETE rp FROM `{$rolePermissions}` rp LEFT JOIN `{$permissions}` p ON p.id = rp.permission_id WHERE p.id IS NULL");
            DB::statement("DELETE rp FROM `{$rolePermissions}` rp LEFT JOIN `{$roles}` r ON r.id = rp.role_id WHERE r.id IS NULL");
        }
        if (Schema::hasTable($modelPermissions)) {
            DB::statement("DELETE mp FROM `{$modelPermissions}` mp LEFT JOIN `{$permissions}` p ON p.id = mp.permission_id WHERE p.id IS NULL");
        }
        if (Schema::hasTable($modelRoles)) {
            DB::statement("DELETE mr FROM `{$modelRoles}` mr LEFT JOIN `{$roles}` r ON r.id = mr.role_id WHERE r.id IS NULL");
        }

        if (Schema::hasTable('company_permission_templates')) {
            // Unlink invalid template references before adding the FK.
            if (Schema::hasTable('users') && Schema::hasColumn('users', 'company_permission_template_id')) {
                DB::statement(<<<'SQL'
                    UPDATE `users` u
                    LEFT JOIN `company_permission_templates` t
                      ON t.id = u.company_permission_template_id
                     AND t.company_id = u.company_id
                    SET u.company_permission_template_id = NULL
                    WHERE u.company_permission_template_id IS NOT NULL
                      AND t.id IS NULL
                SQL);
            }

            DB::statement(<<<'SQL'
                UPDATE `company_permission_templates` t
                LEFT JOIN `companies` c ON c.id = t.created_by
                SET t.created_by = NULL
                WHERE t.created_by IS NOT NULL AND c.id IS NULL
            SQL);

            // A template without its company is unusable. Unlink it first,
            // then remove it rather than keeping cross-tenant orphan data.
            DB::statement(<<<'SQL'
                UPDATE `users` u
                JOIN `company_permission_templates` t ON t.id = u.company_permission_template_id
                LEFT JOIN `companies` c ON c.id = t.company_id
                SET u.company_permission_template_id = NULL
                WHERE c.id IS NULL
            SQL);
            DB::statement(<<<'SQL'
                DELETE t FROM `company_permission_templates` t
                LEFT JOIN `companies` c ON c.id = t.company_id
                WHERE c.id IS NULL
            SQL);
        }

        $this->addForeignIfMissing($rolePermissions, 'permission_id', $permissions, 'id', 'cascade', 'permission_role_permission_id_foreign');
        $this->addForeignIfMissing($rolePermissions, 'role_id', $roles, 'id', 'cascade', 'permission_role_role_id_foreign');
        $this->addForeignIfMissing($modelPermissions, 'permission_id', $permissions, 'id', 'cascade', 'model_has_permissions_permission_id_foreign');
        $this->addForeignIfMissing($modelRoles, 'role_id', $roles, 'id', 'cascade', 'model_has_roles_role_id_foreign');
        $this->addForeignIfMissing('company_permission_templates', 'company_id', 'companies', 'id', 'cascade', 'company_permission_templates_company_id_foreign');
        $this->addForeignIfMissing('company_permission_templates', 'created_by', 'companies', 'id', 'set null', 'company_permission_templates_created_by_foreign');

        if (Schema::hasColumn('users', 'company_permission_template_id')) {
            $this->addForeignIfMissing('users', 'company_permission_template_id', 'company_permission_templates', 'id', 'set null', 'users_company_permission_template_id_foreign');
        }
    }

    public function down(): void
    {
        // Storage-engine and integrity normalization is intentionally retained.
    }

    private function addForeignIfMissing(
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $onDelete,
        string $constraint
    ): void {
        if (! Schema::hasTable($table)
            || ! Schema::hasTable($referencedTable)
            || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $exists = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->where('REFERENCED_TABLE_NAME', $referencedTable)
            ->exists();

        if ($exists) {
            return;
        }

        $tableSql = str_replace('`', '``', $table);
        $columnSql = str_replace('`', '``', $column);
        $referenceTableSql = str_replace('`', '``', $referencedTable);
        $referenceColumnSql = str_replace('`', '``', $referencedColumn);
        $constraintSql = str_replace('`', '``', $constraint);
        $deleteSql = strtoupper($onDelete);

        DB::statement(
            "ALTER TABLE `{$tableSql}` ADD CONSTRAINT `{$constraintSql}` " .
            "FOREIGN KEY (`{$columnSql}`) REFERENCES `{$referenceTableSql}` (`{$referenceColumnSql}`) " .
            "ON DELETE {$deleteSql}"
        );
    }
};
