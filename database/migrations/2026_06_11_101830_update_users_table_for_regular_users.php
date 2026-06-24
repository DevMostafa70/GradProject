<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. أولاً: تحديث أي بيانات قديمة إلى 'user' مؤقتاً
        DB::table('users')->where('role', 'candidate')->update(['role' => 'user']);
        DB::table('users')->where('role', 'company')->update(['role' => 'user']);
        DB::table('users')->where('role', 'admin')->update(['role' => 'user']);

        // 2. ثانياً: تغيير نوع العمود
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('user') DEFAULT 'user' NOT NULL");
    }

    public function down(): void
    {
        // الرجوع إلى الوضع السابق
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('user', 'admin', 'candidate', 'company') DEFAULT 'user' NOT NULL");
    }
};
