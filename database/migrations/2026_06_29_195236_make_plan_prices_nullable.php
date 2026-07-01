<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('monthly_price', 10, 2)->nullable()->change();
            $table->decimal('yearly_price', 10, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('monthly_price', 10, 2)->nullable(false)->change();
            $table->decimal('yearly_price', 10, 2)->nullable(false)->change();
        });
    }
};
