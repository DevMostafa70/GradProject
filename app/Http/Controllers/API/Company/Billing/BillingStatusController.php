<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Company\Billing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\CompanyBillingStatusResource;
use App\Models\Company;
use Illuminate\Http\Request;

final class BillingStatusController extends Controller
{
    public function show(Request $request): CompanyBillingStatusResource
    {
        /** @var Company $company */
        $company = $request->user();

        return new CompanyBillingStatusResource(
            $company->loadMissing('selectedPlan')
        );
    }
}
