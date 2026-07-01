<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'monthly_amount')) {
                $table->integer('monthly_amount')->nullable()->after('yearly_price');
            }
            if (!Schema::hasColumn('plans', 'yearly_amount')) {
                $table->integer('yearly_amount')->nullable()->after('monthly_amount');
            }
            if (!Schema::hasColumn('plans', 'currency')) {
                $table->string('currency')->default('usd')->after('yearly_amount');
            }
            if (!Schema::hasColumn('plans', 'features')) {
                $table->json('features')->nullable()->after('candidates_limit');
            }
            if (!Schema::hasColumn('plans', 'limits')) {
                $table->json('limits')->nullable()->after('features');
            }
            if (!Schema::hasColumn('plans', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('is_default');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'monthly_amount',
                'yearly_amount',
                'currency',
                'features',
                'limits',
                'sort_order',
            ]);
        });
    }
};
