<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_jobs', function (Blueprint $table) {
            // تغيير title من json إلى varchar
            $table->string('title', 255)->change();

            // تغيير description من json إلى text
            $table->text('description')->change();
        });
    }

    public function down(): void
    {
        Schema::table('company_jobs', function (Blueprint $table) {
            // العودة إلى الوضع السابق (إذا لزم الأمر)
            $table->json('title')->change();
            $table->json('description')->change();
        });
    }
};
