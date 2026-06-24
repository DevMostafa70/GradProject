<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Company\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\Billing\SelectCompanyPlanRequest;
use App\Http\Resources\Billing\CompanyBillingStatusResource;
use App\Models\Company;
use App\Services\Billing\CompanyBillingService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class SelectPlanController extends Controller
{
    public function __construct(
        private readonly CompanyBillingService $companyBillingService,
    ) {
    }

    public function store(SelectCompanyPlanRequest $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->user();

        $company = $this->companyBillingService->selectPlan(
            company: $company,
            planCode: $request->planCode(),
        );

        return response()->json([
            'message' => 'Plan selected successfully. Checkout will be created in the next billing step.',
            'data' => new CompanyBillingStatusResource($company),
        ], Response::HTTP_OK);
    }
}
