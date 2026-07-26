<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'password_reset_tokens_users',
            'password_reset_tokens_companies',
            'password_reset_tokens_admins',
        ] as $tableName) {
            if (Schema::hasTable($tableName)) {
                continue;
            }

            Schema::create($tableName, function (Blueprint $table): void {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens_admins');
        Schema::dropIfExists('password_reset_tokens_companies');
        Schema::dropIfExists('password_reset_tokens_users');
    }
};
