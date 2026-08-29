<?php

namespace App\Http\Controllers\Api\Company\Billing;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingPortalController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->user();

        if (!$company->stripe_id) {
            return response()->json([
                'success' => false,
                'message' => 'Stripe customer does not exist for this company yet.',
            ], 422);
        }

        $frontendUrl = rtrim(config('interview_ai.frontend_url', 'http://localhost:5173'), '/');
        $returnUrl = $frontendUrl . '/company/billing';

        $portalSession = $company->newBillingPortalSession($returnUrl);

        return response()->json([
            'success' => true,
            'message' => 'Billing portal session created successfully.',
            'data' => [
                'portal_url' => $portalSession->url,
            ],
        ]);
    }
}
