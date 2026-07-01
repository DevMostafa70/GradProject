<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 🔹 1. إضافة عمود idempotency_key
        Schema::table('answers', function (Blueprint $table) {
            if (!Schema::hasColumn('answers', 'idempotency_key')) {
                $table->string('idempotency_key', 64)->nullable()->unique()->after('id');
            }
        });

        // 🔹 2. إضافة Unique Constraint على (interview_id, question_id)
        // التحقق من عدم وجود المفتاح مسبقاً
        if (!$this->constraintExists('answers', 'answers_interview_id_question_id_unique')) {
            Schema::table('answers', function (Blueprint $table) {
                $table->unique(['interview_id', 'question_id'], 'answers_interview_id_question_id_unique');
            });
        }

        // 🔹 3. إضافة فهرس على idempotency_key للبحث السريع
        if (!$this->indexExists('answers', 'answers_idempotency_key_index')) {
            Schema::table('answers', function (Blueprint $table) {
                $table->index('idempotency_key');
            });
        }
    }

    public function down(): void
    {
        Schema::table('answers', function (Blueprint $table) {
            // حذف الفهارس والمفاتيح
            $table->dropUnique('answers_interview_id_question_id_unique');
            $table->dropIndex(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }

    private function constraintExists(string $table, string $constraintName): bool
    {
        try {
            $result = DB::select("
                SELECT COUNT(*) as count
                FROM information_schema.table_constraints
                WHERE table_schema = DATABASE()
                AND table_name = ?
                AND constraint_name = ?
            ", [$table, $constraintName]);

            return (int) $result[0]->count > 0;
        } catch (\Exception $e) {
            return false;
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
