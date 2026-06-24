<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Company\Billing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\PlanResource;
use App\Services\Billing\PlanService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PlanController extends Controller
{
    public function __construct(
        private readonly PlanService $planService,
    ) {
    }

    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(
            $this->planService->getActivePlans()
        );
    }
}
