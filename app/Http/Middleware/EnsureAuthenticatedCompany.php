<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAuthenticatedCompany
{
    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        if (! $request->user() instanceof Company) {
            return response()->json([
                'message' => 'This endpoint is available for authenticated companies only.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
