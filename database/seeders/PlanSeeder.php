<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlanCode;
use App\Models\Plan;
use Illuminate\Database\Seeder;

final class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => PlanCode::Starter->value,
                'name' => 'Starter',
                'description' => 'For small companies starting with AI interviews.',
                'monthly_amount' => 2900,
                'yearly_amount' => 29000,
                'currency' => 'usd',
                'features' => [
                    'bulk_upload' => true,
                    'ai_questions' => true,
                    'company_question_bank' => false,
                    'basic_anti_cheat' => true,
                    'advanced_anti_cheat' => false,
                    'basic_reports' => true,
                    'full_reports' => false,
                    'cv_evaluation' => true,
                    'export_results' => false,
                ],
                'limits' => [
                    'active_jobs' => 1,
                    'candidates_per_month' => 50,
                    'interviews_per_month' => 50,
                    'cv_reviews_per_month' => 20,
                    'team_members' => 1,
                ],
                'sort_order' => 1,
            ],
            [
                'slug' => PlanCode::Growth->value,
                'name' => 'Growth',
                'description' => 'For growing companies that run regular hiring campaigns.',
                'monthly_amount' => 9900,
                'yearly_amount' => 99000,
                'currency' => 'usd',
                'features' => [
                    'bulk_upload' => true,
                    'ai_questions' => true,
                    'company_question_bank' => true,
                    'basic_anti_cheat' => true,
                    'advanced_anti_cheat' => true,
                    'basic_reports' => true,
                    'full_reports' => true,
                    'cv_evaluation' => true,
                    'export_results' => true,
                ],
                'limits' => [
                    'active_jobs' => 5,
                    'candidates_per_month' => 300,
                    'interviews_per_month' => 300,
                    'cv_reviews_per_month' => 200,
                    'team_members' => 5,
                ],
                'sort_order' => 2,
            ],
            [
                'slug' => PlanCode::Business->value,
                'name' => 'Business',
                'description' => 'For companies with larger recruitment volume.',
                'monthly_amount' => 29900,
                'yearly_amount' => 299000,
                'currency' => 'usd',
                'features' => [
                    'bulk_upload' => true,
                    'ai_questions' => true,
                    'company_question_bank' => true,
                    'basic_anti_cheat' => true,
                    'advanced_anti_cheat' => true,
                    'basic_reports' => true,
                    'full_reports' => true,
                    'cv_evaluation' => true,
                    'export_results' => true,
                ],
                'limits' => [
                    'active_jobs' => 25,
                    'candidates_per_month' => 2000,
                    'interviews_per_month' => 2000,
                    'cv_reviews_per_month' => 1000,
                    'team_members' => 20,
                ],
                'sort_order' => 3,
            ],
            [
                'slug' => PlanCode::Enterprise->value,
                'name' => 'Enterprise',
                'description' => 'Custom limits and custom contract for enterprise companies.',
                'monthly_amount' => null,
                'yearly_amount' => null,
                'currency' => 'usd',
                'features' => [
                    'bulk_upload' => true,
                    'ai_questions' => true,
                    'company_question_bank' => true,
                    'basic_anti_cheat' => true,
                    'advanced_anti_cheat' => true,
                    'basic_reports' => true,
                    'full_reports' => true,
                    'cv_evaluation' => true,
                    'export_results' => true,
                    'api_access' => true,
                ],
                'limits' => [
                    'active_jobs' => null,
                    'candidates_per_month' => null,
                    'interviews_per_month' => null,
                    'cv_reviews_per_month' => null,
                    'team_members' => null,
                ],
                'sort_order' => 4,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                $plan + ['is_active' => true]
            );
        }
    }
}
