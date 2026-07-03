<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // تعديل الـ ENUM ليشمل 'users'
        DB::statement("ALTER TABLE broadcast_notifications MODIFY target_type ENUM('all', 'companies', 'candidates', 'users') DEFAULT 'all'");
    }

    public function down(): void
    {
        // الرجوع إلى الوضع السابق
        DB::statement("ALTER TABLE broadcast_notifications MODIFY target_type ENUM('all', 'companies', 'candidates') DEFAULT 'all'");
    }
};
