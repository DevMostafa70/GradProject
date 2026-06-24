<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        if (Auth::user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Admin only.'
            ], 403);
        }

        return $next($request);
    }
}
