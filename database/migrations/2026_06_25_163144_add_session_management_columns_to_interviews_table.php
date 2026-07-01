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
            // Session management columns
            if (!Schema::hasColumn('interviews', 'session_token')) {
                // ✅ استخدام unique() فقط، وليس index() منفصل
                $table->string('session_token', 64)->nullable()->unique()->after('metadata');
            }

            if (!Schema::hasColumn('interviews', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('session_token');
            }

            if (!Schema::hasColumn('interviews', 'last_activity_at')) {
                $table->timestamp('last_activity_at')->nullable()->after('expires_at');
            }

            if (!Schema::hasColumn('interviews', 'current_question_id')) {
                $table->foreignId('current_question_id')->nullable()->after('last_activity_at')
                    ->constrained('questions')->nullOnDelete();
            }

            if (!Schema::hasColumn('interviews', 'answered_questions_count')) {
                $table->unsignedInteger('answered_questions_count')->default(0)->after('current_question_id');
            }
        });

        // ✅ إضافة الفهارس مع التحقق من وجودها مسبقاً
        $this->addIndexesIfNotExist();
    }

    public function down(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            $table->dropForeign(['current_question_id']);

            // حذف الفهارس أولاً قبل حذف الأعمدة
            if ($this->indexExists('interviews', 'interviews_expires_at_index')) {
                $table->dropIndex('interviews_expires_at_index');
            }

            if ($this->indexExists('interviews', 'interviews_status_expires_at_index')) {
                $table->dropIndex('interviews_status_expires_at_index');
            }

            // حذف الأعمدة
            $table->dropColumn([
                'session_token',
                'expires_at',
                'last_activity_at',
                'current_question_id',
                'answered_questions_count',
            ]);
        });
    }

    /**
     * ✅ إضافة الفهارس فقط إذا لم تكن موجودة
     */
    private function addIndexesIfNotExist(): void
    {
        $table = 'interviews';

        // ✅ session_token له unique بالفعل، لا نحتاج index منفصل

        // ✅ expires_at index
        if (!$this->indexExists($table, 'interviews_expires_at_index')) {
            Schema::table($table, function (Blueprint $table) {
                $table->index('expires_at');
            });
        }

        // ✅ status + expires_at index
        if (!$this->indexExists($table, 'interviews_status_expires_at_index')) {
            Schema::table($table, function (Blueprint $table) {
                $table->index(['status', 'expires_at']);
            });
        }
    }

    /**
     * ✅ التحقق من وجود فهرس في قاعدة البيانات
     */
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
