<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('answers', function (Blueprint $table) {
            if (!Schema::hasColumn('answers', 'audio_deleted_at')) {
                $table->timestamp('audio_deleted_at')->nullable()->after('processed_at')
                    ->comment('When the audio file was deleted for privacy');
            }
        });
    }

    public function down(): void
    {
        Schema::table('answers', function (Blueprint $table) {
            $table->dropColumn('audio_deleted_at');
        });
    }
};
