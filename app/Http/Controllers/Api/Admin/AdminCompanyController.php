<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\AdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Reverb\Loggers\Log;

class AdminCompanyController extends Controller
{
    protected AdminService $adminService;

    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
    }

    /**
     * Get pending company registration requests
     */
    public function pendingRequests(): JsonResponse
    {
        $requests = Company::where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests->map(function ($company) {
                return [
                    'id' => $company->id,
                    'company_name' => $company->company_name,
                    'email' => $company->email,
                    'industry' => $company->industry,
                    'phone' => $company->phone,
                    'website' => $company->website,
                    'status' => $company->status,
                    'created_at' => $company->created_at,
                ];
            }),
        ]);
    }

    /**
     * Approve a company registration
     */
    public function approve(Company $company, Request $request): JsonResponse
    {
        $request->validate([
            'notes' => 'nullable|string',
        ]);

        $this->adminService->approveCompany($company, $request->notes);

        return response()->json([
            'success' => true,
            'message' => 'Company approved successfully',
        ]);
    }

    /**
     * Reject a company registration
     */
    public function reject(Company $company, Request $request): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string',
        ]);

        $this->adminService->rejectCompany($company, $request->reason);

        return response()->json([
            'success' => true,
            'message' => 'Company rejected successfully',
        ]);
    }

    /**
     * Get all companies
     */
    public function index(Request $request): JsonResponse
    {
        $companies = Company::orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $companies,
        ]);
    }

    /**
     * Delete a company permanently
     */

   public function destroy(Company $company): JsonResponse
{
    try {
        $companyName = $company->company_name;
        $userId = $company->user_id;

        // 1. تسجيل النشاط قبل الحذف
        \App\Models\AdminLog::log('delete_company', 'company', $company->id, [
            'company_name' => $companyName,
            'company_email' => $company->email,
        ]);

        // 2. حذف الشركة من جدول companies
        $company->delete();

        // 3. 🔥 حذف المستخدم من جدول users
        if ($userId) {
            \App\Models\User::where('id', $userId)->delete();
        }

        return response()->json([
            'success' => true,
            'message' => "Company '{$companyName}' deleted successfully",
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to delete company: ' . $e->getMessage(),
        ], 500);
    }
}

    /**
     * Suspend a company account (تعليق حساب شركة)
     */
    public function suspend(Company $company, Request $request): JsonResponse
{
    \Illuminate\Support\Facades\Log::info('=== START SUSPEND ===', ['company_id' => $company->id]);

    $request->validate([
        'reason' => 'nullable|string|max:500',
    ]);

    try {
        $companyName = $company->company_name;

        $company->update([
            'status' => 'suspended',
            'admin_notes' => $request->reason ?? 'No reason provided',
        ]);

        if ($company->user_id) {
            \App\Models\User::where('id', $company->user_id)->update([
                'is_active' => false,
            ]);
        }

        \App\Models\AdminLog::log('suspend_company', 'company', $company->id, [
            'company_name' => $companyName,
            'reason' => $request->reason,
        ]);

        \Illuminate\Support\Facades\Log::info('=== END SUSPEND ===', ['company_id' => $company->id]);

        return response()->json([
            'success' => true,
            'message' => "Company '{$companyName}' has been suspended successfully",
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to suspend company: ' . $e->getMessage(),
        ], 500);
    }
}
    /**
     * Activate a suspended company account (إعادة تفعيل حساب شركة)
     */
    public function activate(Company $company, Request $request): JsonResponse
{
    try {
        $companyName = $company->company_name;

        // 1. تحديث حالة الشركة في جدول companies
        $company->update([
            'status' => 'approved',
            'admin_notes' => $request->notes ?? null,
        ]);

        // 2. 🔥 تحديث حالة المستخدم في جدول users
        if ($company->user_id) {
            \App\Models\User::where('id', $company->user_id)->update([
                'is_active' => true,
            ]);
        }

        // 3. تسجيل النشاط
        \App\Models\AdminLog::log('activate_company', 'company', $company->id, [
            'company_name' => $companyName,
            'notes' => $request->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Company '{$companyName}' has been activated successfully",
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to activate company: ' . $e->getMessage(),
        ], 500);
    }
}
}
