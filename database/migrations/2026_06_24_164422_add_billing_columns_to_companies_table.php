<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `companies` ENGINE = InnoDB');

            if (Schema::hasTable('plans')) {
                DB::statement('ALTER TABLE `plans` ENGINE = InnoDB');
            }
        }

        Schema::table('companies', function (Blueprint $table): void {
            if (! Schema::hasColumn('companies', 'selected_plan_id')) {
                $table
                    ->unsignedBigInteger('selected_plan_id')
                    ->nullable()
                    ->after('approved_by');
            }

            if (! Schema::hasColumn('companies', 'billing_status')) {
                $table
                    ->string('billing_status')
                    ->default('none')
                    ->after('selected_plan_id')
                    ->index();
            }

            if (! Schema::hasColumn('companies', 'billing_grace_ends_at')) {
                $table
                    ->timestamp('billing_grace_ends_at')
                    ->nullable()
                    ->after('billing_status');
            }

            if (! Schema::hasColumn('companies', 'billing_locked_at')) {
                $table
                    ->timestamp('billing_locked_at')
                    ->nullable()
                    ->after('billing_grace_ends_at');
            }

            if (! Schema::hasColumn('companies', 'stripe_id')) {
                $table
                    ->string('stripe_id')
                    ->nullable()
                    ->after('billing_locked_at')
                    ->index();
            }

            if (! Schema::hasColumn('companies', 'pm_type')) {
                $table
                    ->string('pm_type')
                    ->nullable()
                    ->after('stripe_id');
            }

            if (! Schema::hasColumn('companies', 'pm_last_four')) {
                $table
                    ->string('pm_last_four', 4)
                    ->nullable()
                    ->after('pm_type');
            }

            if (! Schema::hasColumn('companies', 'trial_ends_at')) {
                $table
                    ->timestamp('trial_ends_at')
                    ->nullable()
                    ->after('pm_last_four');
            }
        });

        $this->addSelectedPlanForeignKeyIfMissing();
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        $this->dropSelectedPlanForeignKeyIfExists();

        Schema::table('companies', function (Blueprint $table): void {
            $columns = [
                'selected_plan_id',
                'billing_status',
                'billing_grace_ends_at',
                'billing_locked_at',
                'stripe_id',
                'pm_type',
                'pm_last_four',
                'trial_ends_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('companies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addSelectedPlanForeignKeyIfMissing(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }

        if (! Schema::hasColumn('companies', 'selected_plan_id')) {
            return;
        }

        if ($this->selectedPlanForeignKeyExists()) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table
                ->foreign('selected_plan_id', 'companies_selected_plan_id_foreign')
                ->references('id')
                ->on('plans')
                ->nullOnDelete();
        });
    }

    private function dropSelectedPlanForeignKeyIfExists(): void
    {
        if (! Schema::hasColumn('companies', 'selected_plan_id')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropForeign(['selected_plan_id']);
            });

            return;
        }

        $foreignKey = DB::selectOne("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'companies'
            AND COLUMN_NAME = 'selected_plan_id'
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        if ($foreignKey !== null) {
            DB::statement("ALTER TABLE `companies` DROP FOREIGN KEY `{$foreignKey->CONSTRAINT_NAME}`");
        }
    }

    private function selectedPlanForeignKeyExists(): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        $foreignKey = DB::selectOne("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'companies'
            AND COLUMN_NAME = 'selected_plan_id'
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        return $foreignKey !== null;
    }
};
