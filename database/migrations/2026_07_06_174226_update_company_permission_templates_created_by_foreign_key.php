<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_permission_templates')
            || ! Schema::hasColumn('company_permission_templates', 'created_by')) {
            return;
        }

        // New installations already point created_by to companies in the
        // create-table migration. Existing MySQL installations are normalized
        // here without assuming that the old foreign key exists.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `company_permission_templates` ENGINE=InnoDB');

        $foreign = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'company_permission_templates')
            ->where('COLUMN_NAME', 'created_by')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->first(['CONSTRAINT_NAME', 'REFERENCED_TABLE_NAME']);

        if ($foreign && $foreign->REFERENCED_TABLE_NAME === 'companies') {
            return;
        }

        if ($foreign) {
            $constraint = str_replace('`', '``', (string) $foreign->CONSTRAINT_NAME);
            DB::statement("ALTER TABLE `company_permission_templates` DROP FOREIGN KEY `{$constraint}`");
        }

        DB::statement(<<<'SQL'
            UPDATE `company_permission_templates` t
            LEFT JOIN `companies` c ON c.id = t.created_by
            SET t.created_by = NULL
            WHERE t.created_by IS NOT NULL AND c.id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `company_permission_templates`
            ADD CONSTRAINT `company_permission_templates_created_by_foreign`
            FOREIGN KEY (`created_by`) REFERENCES `companies` (`id`)
            ON DELETE SET NULL
        SQL);
    }

    public function down(): void
    {
        // The corrected relationship is intentionally retained.
    }
};
