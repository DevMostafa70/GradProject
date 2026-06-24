<?php

namespace App\Http\Controllers\API\Company\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\Billing\StartCheckoutRequest;
use App\Models\Company;
use App\Services\Billing\CheckoutSessionService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutSessionService $checkoutSessionService,
    ) {
    }

    public function store(StartCheckoutRequest $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->user();

        $session = $this->checkoutSessionService->createForCompany(
            company: $company,
            billingInterval: $request->billingInterval(),
        );

        return response()->json([
            'message' => 'Checkout session created successfully.',
            'data' => [
                'checkout_url' => $session->url,
                'session_id' => $session->id,
            ],
        ]);
    }
}
