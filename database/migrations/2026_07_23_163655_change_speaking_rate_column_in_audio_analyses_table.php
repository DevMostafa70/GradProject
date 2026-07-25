<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audio_analysis', function (Blueprint $table) {
            $table->decimal('speaking_rate', 8, 2)
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('audio_analyses', function (Blueprint $table) {
            $table->decimal('speaking_rate', 5, 2)
                ->nullable()
                ->change();
        });
    }
};