<?php
// database/seeders/PlanSeeder.php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

final class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'description' => 'For small companies starting with AI interviews.',
                'monthly_price' => 29.00,
                'yearly_price' => 290.00,
                'interviews_limit' => 10,
                'jobs_limit' => 3,
                'candidates_limit' => 50,
                'max_employees' => 1,
                'stripe_product_id' => 'prod_UiQTnaY8TvCZMu',
                'stripe_price_monthly_id' => 'price_1TizcAQ1fJwm8tI5ijTSLhqN',
                'stripe_price_yearly_id' => 'price_1TlvGEQ1fJwm8tI5Guk6b9Hi',
                'is_active' => true,
                'is_default' => true,
            ],
            [
                'slug' => 'growth',
                'name' => 'Growth',
                'description' => 'For growing companies that run regular hiring campaigns.',
                'monthly_price' => 79.00,
                'yearly_price' => 790.00,
                'interviews_limit' => 50,
                'jobs_limit' => 10,
                'candidates_limit' => 200,
                'max_employees' => 5,
                'stripe_product_id' => 'prod_UlSQhmSEMDRuYt',
                'stripe_price_monthly_id' => 'price_1TlvVxQ1fJwm8tI508cfmfrB',
                'stripe_price_yearly_id' => 'price_1TlvWYQ1fJwm8tI53a9Xl8Gp',
                'is_active' => true,
                'is_default' => false,
            ],
            [
                'slug' => 'business',
                'name' => 'Business',
                'description' => 'For companies with larger recruitment volume.',
                'monthly_price' => 199.00,
                'yearly_price' => 1990.00,
                'interviews_limit' => 999999,
                'jobs_limit' => 999999,
                'candidates_limit' => 999999,
                'max_employees' => 20,
                'stripe_product_id' => 'prod_UlSTAQhWNejAqh',
                'stripe_price_monthly_id' => 'price_1TlvYDQ1fJwm8tI5Dlek6euR',
                'stripe_price_yearly_id' => 'price_1TlvYxQ1fJwm8tI58T09Wm4V',
                'is_active' => true,
                'is_default' => false,
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Custom limits and custom contract for enterprise companies.',
                'monthly_price' => 0.00,
                'yearly_price' => 0.00,
                'interviews_limit' => 999999,
                'jobs_limit' => 999999,
                'candidates_limit' => 999999,
                'max_employees' => 999999,
                'stripe_product_id' => null,
                'stripe_price_monthly_id' => null,
                'stripe_price_yearly_id' => null,
                'is_active' => true,
                'is_default' => false,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                $plan
            );
        }

        $this->command->info('✅ Plans seeded successfully with Stripe Price IDs!');
        $this->command->info('📋 Starter: price_1TizcAQ1fJwm8tI5ijTSLhqN');
        $this->command->info('📋 Growth: price_1TlvVxQ1fJwm8tI508cfmfrB');
        $this->command->info('📋 Business: price_1TlvYDQ1fJwm8tI5Dlek6euR');
    }
}
