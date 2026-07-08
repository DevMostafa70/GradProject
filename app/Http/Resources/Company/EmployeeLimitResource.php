<?php
// app/Http/Resources/Company/EmployeeLimitResource.php

namespace App\Http\Resources\Company;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeLimitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'max_employees' => $this['max_employees'],
            'current_employees' => $this['current_employees'],
            'remaining_slots' => $this['remaining_slots'],
            'can_add' => $this['can_add'],
            'is_limit_reached' => !$this['can_add'],
            'plan_name' => $this['plan_name'],
            'plan_slug' => $this['plan_slug'],
        ];
    }
}
