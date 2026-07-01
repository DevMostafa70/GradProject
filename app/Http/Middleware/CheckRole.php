<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // تحديد دور المستخدم حسب نوعه
        $userRole = $this->getUserRole($user);

        // التحقق من أن دور المستخدم موجود في الأدوار المسموحة
        if (!in_array($userRole, $roles)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You do not have the required role.',
            ], 403);
        }

        return $next($request);
    }

    private function getUserRole($user): string
    {
        if ($user instanceof \App\Models\Admin) {
            return $user->role ?? 'admin';
        }

        if ($user instanceof \App\Models\Company) {
            return 'company';
        }

        if ($user instanceof \App\Models\User) {
            return $user->role ?? 'user';
        }

        if ($user instanceof \App\Models\Candidate) {
            return 'candidate';
        }

        return 'unknown';
    }
}
