<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            // 🔹 Tab/Window lock columns
            if (!Schema::hasColumn('interviews', 'active_session_id')) {
                $table->string('active_session_id', 64)->nullable()->after('session_token');
            }

            if (!Schema::hasColumn('interviews', 'session_initialized_at')) {
                $table->timestamp('session_initialized_at')->nullable()->after('active_session_id');
            }

            if (!Schema::hasColumn('interviews', 'device_fingerprint')) {
                $table->string('device_fingerprint', 255)->nullable()->after('session_initialized_at');
            }
        });

        // ✅ إضافة الفهارس بأمان
        $this->addIndexesIfNotExist();
    }

    public function down(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            $table->dropIndex(['active_session_id']);
            $table->dropColumn([
                'active_session_id',
                'session_initialized_at',
                'device_fingerprint',
            ]);
        });
    }

    private function addIndexesIfNotExist(): void
    {
        $table = 'interviews';

        if (!$this->indexExists($table, 'interviews_active_session_id_index')) {
            Schema::table($table, function (Blueprint $table) {
                $table->index('active_session_id');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $result = DB::select("
                SELECT COUNT(*) as count
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                AND table_name = ?
                AND index_name = ?
            ", [$table, $indexName]);

            return (int) $result[0]->count > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
};
