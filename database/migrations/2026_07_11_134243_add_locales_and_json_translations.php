<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'locale')) {
                $table->string('locale', 5)->default('en')->after('email');
            }
        });

        Schema::table('interviews', function (Blueprint $table) {
            if (! Schema::hasColumn('interviews', 'locale')) {
                $table->string('locale', 5)->default('en')->after('difficulty');
            }
        });

        Schema::table('job_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('job_categories', 'name')) {
                $table->json('name')->nullable()->after('id');
            }
        });

        DB::table('job_categories')
            ->whereNull('name')
            ->orderBy('id')
            ->chunkById(100, function ($categories) {
                foreach ($categories as $category) {
                    DB::table('job_categories')->where('id', $category->id)->update([
                        'name' => json_encode([
                            'en' => $category->name_en ?: $category->name_ar,
                            'ar' => $category->name_ar ?: $category->name_en,
                        ], JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });

        Schema::table('skills', function (Blueprint $table) {
            try {
                $table->dropUnique(['name']);
            } catch (\Throwable $e) {
            }

            try {
                $table->dropIndex(['category']);
            } catch (\Throwable $e) {
            }
        });

        $this->convertColumnToJson('questions', 'question_text', 'en');
        $this->convertColumnToJson('company_jobs', 'title', 'en');
        $this->convertColumnToJson('company_jobs', 'description', 'en');
        $this->convertColumnToJson('skills', 'name', 'en');
        $this->convertColumnToJson('skills', 'category', 'en', true);
    }

    public function down(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            if (Schema::hasColumn('interviews', 'locale')) {
                $table->dropColumn('locale');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'locale')) {
                $table->dropColumn('locale');
            }
        });

        Schema::table('job_categories', function (Blueprint $table) {
            if (Schema::hasColumn('job_categories', 'name')) {
                $table->dropColumn('name');
            }
        });
    }

    private function convertColumnToJson(string $table, string $column, string $sourceLocale = 'en', bool $nullable = false): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table, $column, $sourceLocale) {
            foreach ($rows as $row) {
                $value = $row->{$column};

                if ($value === null) {
                    continue;
                }

                $decoded = is_string($value) ? json_decode($value, true) : null;

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([
                    $column => json_encode([
                        $sourceLocale => $value,
                        $sourceLocale === 'en' ? 'ar' : 'en' => null,
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }
        });

        $nullSql = $nullable ? 'NULL' : 'NOT NULL';

        DB::statement("ALTER TABLE {$table} MODIFY {$column} JSON {$nullSql}");
    }
};