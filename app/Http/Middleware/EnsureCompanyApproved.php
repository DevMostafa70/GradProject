<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCompanyApproved
{
    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        $company = $request->user();

        if (! $company instanceof Company) {
            return response()->json([
                'message' => 'This endpoint is available for authenticated companies only.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $company->isApproved()) {
            return response()->json([
                'message' => 'Your company account must be approved before selecting a billing plan.',
                'company_status' => $company->status?->value ?? $company->status,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
