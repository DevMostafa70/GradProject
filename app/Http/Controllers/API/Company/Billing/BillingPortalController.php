<?php

namespace App\Http\Controllers\API\Company\Billing;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BillingPortalController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->user();

        if (! $company->stripe_id) {
            return response()->json([
                'message' => 'Stripe customer does not exist for this company yet.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $returnUrl = rtrim((string) config('interview_ai.frontend_url'), '/') . '/company/billing';

        return response()->json([
            'message' => 'Billing portal session created successfully.',
            'data' => [
                'portal_url' => $company->billingPortalUrl($returnUrl),
            ],
        ]);
    }
}
