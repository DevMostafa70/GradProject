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

final class CheckPermission
{
    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        $actor = $request->user();

        if (! $actor) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // The super-admin role is intentionally unrestricted.
        if ($actor instanceof Admin && $actor->isSuperAdmin()) {
            return $next($request);
        }

        // A Company model is the company owner. Employees are User models and
        // must never receive this owner bypass.
        if ($actor instanceof Company
            && collect($permissions)->contains(
                static fn (string $permission): bool => str_starts_with($permission, 'company.')
            )) {
            return $next($request);
        }

        $effectivePermissions = $this->effectivePermissions($actor);

        if (array_intersect($permissions, $effectivePermissions) !== []) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized. You do not have the required permission.',
            'required_permissions' => array_values($permissions),
            'your_permissions' => $effectivePermissions,
        ], 403);
    }

    /** @return array<int, string> */
    private function effectivePermissions(object $actor): array
    {
        if ($actor instanceof Admin) {
            return $actor->getPermissionsByRole();
        }

        if ($actor instanceof User && $actor->isCompanyEmployee()) {
            return app(CompanyEmployeeAccessService::class)->permissionNames($actor);
        }

        try {
            if (method_exists($actor, 'getAllPermissions')) {
                return $actor->getAllPermissions()->pluck('name')->values()->all();
            }
        } catch (Throwable) {
            // Mixed legacy guard rows are handled by the normalization
            // migration. Until it runs, deny access rather than guessing.
        }

        return [];
    }
}
