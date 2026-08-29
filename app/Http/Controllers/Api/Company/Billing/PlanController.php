<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Company\Billing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\PlanResource;
use App\Models\Plan;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $plans = Plan::where('is_active', true)
            ->orderBy('id')
            ->get();

        return PlanResource::collection($plans);
    }
}
