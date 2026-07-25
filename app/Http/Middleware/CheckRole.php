<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyEmployeeAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $actor = $request->user();

        if (! $actor) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $effectiveRoles = $this->effectiveRoles($actor);

        if (array_intersect($roles, $effectiveRoles) !== []) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized. You do not have the required role.',
            'required_roles' => array_values($roles),
            'your_roles' => $effectiveRoles,
        ], 403);
    }

    /** @return array<int, string> */
    private function effectiveRoles(object $actor): array
    {
        if ($actor instanceof Admin) {
            // admins.role is the canonical source; stale Spatie rows cannot
            // elevate a normal admin to super_admin.
            return [$actor->role ?? 'admin'];
        }

        if ($actor instanceof Company) {
            return ['company_owner'];
        }

        if ($actor instanceof User && $actor->isCompanyEmployee()) {
            $roles = app(CompanyEmployeeAccessService::class)->roleNames($actor);

            return $roles !== [] ? $roles : ['company_employee'];
        }

        try {
            if (method_exists($actor, 'getRoleNames')) {
                $roles = $actor->getRoleNames()->values()->all();
                if ($roles !== []) {
                    return $roles;
                }
            }
        } catch (Throwable) {
            // Deny by default when legacy role rows are inconsistent.
        }

        return $actor instanceof User ? ['regular_user'] : ['unknown'];
    }
}
