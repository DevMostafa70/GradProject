<?php

namespace App\Http\Controllers\API\Company;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyJobCandidate;
use App\Models\Interview;
use App\Models\User;
use App\Services\CompanyEmployeeAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        if ($actor instanceof Company) {
            $company = $actor;
            $isOwner = true;
        } elseif ($actor instanceof User && $actor->isCompanyEmployee() && $actor->company) {
            $company = $actor->company;
            $isOwner = false;
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $employeePermissions = $isOwner
            ? []
            : app(CompanyEmployeeAccessService::class)->permissionNames($actor);

        $can = static function (string $permission) use ($isOwner, $employeePermissions): bool {
            return $isOwner || in_array($permission, $employeePermissions, true);
        };

        $stats = [
            'company' => [
                'id' => $company->id,
                'name' => $company->company_name,
                'plan' => $company->selectedPlan?->name,
                'status' => $company->status,
            ],
            'visible_sections' => [],
        ];

        if ($can('company.jobs.view')) {
            $stats['jobs'] = [
                'total' => $company->jobs()->count(),
                'active' => $company->jobs()->where('status', 'active')->count(),
                'closed' => $company->jobs()->where('status', 'closed')->count(),
            ];
            $stats['visible_sections'][] = 'jobs';
        }

        if ($can('company.candidates.view')) {
            $candidateQuery = CompanyJobCandidate::whereHas('job', function ($query) use ($company): void {
                $query->where('company_id', $company->id);
            });

            $stats['candidates'] = [
                'total' => (clone $candidateQuery)->count(),
                'pending' => (clone $candidateQuery)->where('status', 'pending')->count(),
                'completed' => (clone $candidateQuery)->where('status', 'completed')->count(),
            ];
            $stats['visible_sections'][] = 'candidates';
        }

        if ($can('company.interviews.view') || $can('company.results.view')) {
            $stats['interviews'] = [
                'total' => Interview::whereHas('jobCandidate', function ($query) use ($company): void {
                    $query->whereHas('job', function ($jobQuery) use ($company): void {
                        $jobQuery->where('company_id', $company->id);
                    });
                })->count(),
            ];
            $stats['visible_sections'][] = 'interviews';
        }

        // Employee counts are owner-only by product decision.
        if ($isOwner) {
            $stats['employees'] = [
                'total' => $company->employees()->count(),
                'max' => $company->getMaxEmployees(),
                'remaining' => $company->getRemainingEmployeeSlots(),
            ];
            $stats['visible_sections'][] = 'employees';
        }

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
