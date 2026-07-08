<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class EnsureCompanyHasPaidAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        // ✅ إذا كان المستخدم Company Owner
        if ($user instanceof Company) {
            if (!$user->hasFullAccess()) {
                $message = 'Your subscription is not active.';

                if ($user->billing_status && !$user->hasPaidAccess()) {
                    $message = 'Your subscription has expired or is inactive. Please update your payment method.';
                }

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'billing_status' => $user->billing_status?->value ?? 'none',
                ], 403);
            }

            return $next($request);
        }

        // ✅ إذا كان المستخدم موظف (User model مع is_company_employee = true)
        if ($user instanceof User && $user->isCompanyEmployee()) {
            // ✅ الموظف يعتبر جزء من الشركة، نتحقق من حالة الشركة
            $company = $user->company;

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found for this employee.',
                ], 403);
            }

            // ✅ إذا كانت الشركة غير نشطة أو ليس لديها اشتراك
            if (!$company->hasFullAccess()) {
                $message = 'Your company subscription is not active.';

                if ($company->billing_status && !$company->hasPaidAccess()) {
                    $message = 'Your company subscription has expired or is inactive. Please contact your company administrator.';
                }

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'billing_status' => $company->billing_status?->value ?? 'none',
                ], 403);
            }

            return $next($request);
        }

        // ❌ المستخدم غير معروف
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized.',
        ], 403);
    }
}
