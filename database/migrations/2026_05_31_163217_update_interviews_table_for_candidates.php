<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            // إضافة عمود candidate_id
            if (!Schema::hasColumn('interviews', 'candidate_id')) {
                $table->foreignId('candidate_id')->nullable()->after('id')->constrained('candidates')->nullOnDelete();
            }

            // يمكن الاحتفاظ بـ user_id مؤقتاً للتوافق
        });
    }

    public function down(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            $table->dropForeign(['candidate_id']);
            $table->dropColumn('candidate_id');
        });
    }
};
