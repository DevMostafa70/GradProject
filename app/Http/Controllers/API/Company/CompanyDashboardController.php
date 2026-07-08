<?php

namespace App\Http\Controllers\API\Company;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // ✅ إذا كان المستخدم Company Owner
        if ($user instanceof Company) {
            $company = $user;
        }
        // ✅ إذا كان المستخدم موظف
        elseif ($user instanceof User && $user->isCompanyEmployee()) {
            $company = $user->company;
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        // ✅ إحصائيات الشركة
        $stats = [
            'company' => [
                'id' => $company->id,
                'name' => $company->company_name,
                'plan' => $company->selectedPlan?->name,
                'status' => $company->status,
            ],
            'jobs' => [
                'total' => $company->jobs()->count(),
                'active' => $company->jobs()->where('status', 'active')->count(),
                'closed' => $company->jobs()->where('status', 'closed')->count(),
            ],
            'candidates' => [
                'total' => $company->jobs()->withCount('candidates')->get()->sum('candidates_count'),
                'pending' => \App\Models\CompanyJobCandidate::whereHas('job', function ($q) use ($company) {
                    $q->where('company_id', $company->id);
                })->where('status', 'pending')->count(),
                'completed' => \App\Models\CompanyJobCandidate::whereHas('job', function ($q) use ($company) {
                    $q->where('company_id', $company->id);
                })->where('status', 'completed')->count(),
            ],
            'interviews' => [
                'total' => \App\Models\Interview::whereHas('jobCandidate', function ($q) use ($company) {
                    $q->whereHas('job', function ($j) use ($company) {
                        $j->where('company_id', $company->id);
                    });
                })->count(),
            ],
            'employees' => [
                'total' => $company->employees()->count(),
                'max' => $company->getMaxEmployees(),
                'remaining' => $company->getRemainingEmployeeSlots(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
