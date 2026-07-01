<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'stripe_product_id')) {
                $table->string('stripe_product_id')->nullable()->after('yearly_price');
            }
            if (!Schema::hasColumn('plans', 'stripe_price_monthly_id')) {
                $table->string('stripe_price_monthly_id')->nullable()->after('stripe_product_id');
            }
            if (!Schema::hasColumn('plans', 'stripe_price_yearly_id')) {
                $table->string('stripe_price_yearly_id')->nullable()->after('stripe_price_monthly_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['stripe_product_id', 'stripe_price_monthly_id', 'stripe_price_yearly_id']);
        });
    }
};
