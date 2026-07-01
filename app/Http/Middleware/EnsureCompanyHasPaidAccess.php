<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;

class EnsureCompanyHasPaidAccess
{
    public function handle(Request $request, Closure $next)
    {
        $company = $request->user();

        if (!$company instanceof Company) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        if (!$company->hasFullAccess()) {
            $message = 'Your subscription is not active.';

            if ($company->billing_status && !$company->hasPaidAccess()) {
                $message = 'Your subscription has expired or is inactive. Please update your payment method.';
            }

            return response()->json([
                'success' => false,
                'message' => $message,
                'billing_status' => $company->billing_status?->value ?? 'none',
            ], 403);
        }

        return $next($request);
    }
}
