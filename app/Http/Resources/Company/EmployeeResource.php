<?php
// app/Http/Resources/Company/EmployeeResource.php

namespace App\Http\Resources\Company;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\CompanyEmployeeAccessService;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $accessService = app(CompanyEmployeeAccessService::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'is_company_employee' => $this->is_company_employee,
            'roles' => $accessService->roleNames($this->resource),
            'permissions' => $accessService->permissionNames($this->resource),
            'company_id' => $this->company_id,
            'permission_template_id' => $this->company_permission_template_id,
            'permission_template' => $this->whenLoaded('companyPermissionTemplate', function () {
                return [
                    'id' => $this->companyPermissionTemplate?->id,
                    'name' => $this->companyPermissionTemplate?->name,
                    'is_active' => $this->companyPermissionTemplate?->is_active,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
