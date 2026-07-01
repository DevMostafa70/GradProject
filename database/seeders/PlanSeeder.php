<?php

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
                'is_active' => true,
                'is_default' => false,
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Custom limits and custom contract for enterprise companies.',
                'monthly_price' => 0.00,  // ✅ بدلاً من null
                'yearly_price' => 0.00,   // ✅ بدلاً من null
                'interviews_limit' => 999999,
                'jobs_limit' => 999999,
                'candidates_limit' => 999999,
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

        $this->command->info('✅ Plans seeded successfully!');
    }
}
