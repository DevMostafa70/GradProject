<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        channels: __DIR__ . '/../routes/channels.php',
        commands: __DIR__ . '/../routes/console.php',

        health: '/up',
    )
   ->withMiddleware(function (Middleware $middleware) {
    $middleware->statefulApi();
    $middleware->validateCsrfTokens(except: [
        'broadcasting/auth','stripe/*'
    ]);
    $middleware->alias([
        // ✅ تغيير اسم Middleware المخصص من 'role' إلى 'checkrole'
        'checkrole' => \App\Http\Middleware\CheckRole::class,
        'checkpermission' => \App\Http\Middleware\CheckPermission::class,  // ✅ جديد
        'admin' => \App\Http\Middleware\AdminMiddleware::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'company.authenticated' => \App\Http\Middleware\EnsureAuthenticatedCompany::class,
        'company.approved' => \App\Http\Middleware\EnsureCompanyApproved::class,
        'company.paid' => \App\Http\Middleware\EnsureCompanyHasPaidAccess::class,

        // ✅ Spatie Middleware مع تحديد الـ Guard
    'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
        'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
    ]);
})
    ->withExceptions(function (Exceptions $exceptions): void {
        // تخصيص رسالة تجاوز الحد
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'لقد تجاوزت الحد المسموح للطلبات. يرجى الانتظار قليلاً ثم المحاولة مرة أخرى.',
                    'retry_after' => $e->getHeaders()['Retry-After'] ?? 60,
                ], 429);
            }
        });
    })->create();
