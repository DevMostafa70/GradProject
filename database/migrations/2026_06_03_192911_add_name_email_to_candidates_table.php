<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            if (!Schema::hasColumn('candidates', 'name')) {
                $table->string('name')->after('id');
            }
            if (!Schema::hasColumn('candidates', 'email')) {
                $table->string('email')->unique()->after('name');
            }
            if (!Schema::hasColumn('candidates', 'password')) {
                $table->string('password')->after('email');
            }
            if (!Schema::hasColumn('candidates', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('password');
            }
            if (!Schema::hasColumn('candidates', 'remember_token')) {
                $table->rememberToken()->after('email_verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn(['name', 'email', 'password', 'email_verified_at', 'remember_token']);
        });
    }
};
