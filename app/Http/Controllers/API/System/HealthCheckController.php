<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = $this->checkDatabaseConnection();

        return response()->json([
            'status' => $database['connected'] ? 'ok' : 'degraded',
            'application' => [
                'name' => config('app.name'),
                'environment' => config('app.env'),
                'debug' => config('app.debug'),
                'url' => config('app.url'),
            ],
            'database' => $database,
        ], $database['connected'] ? 200 : 503);
    }

    private function checkDatabaseConnection(): array
    {
        try {
            DB::connection()->getPdo();

            return [
                'connected' => true,
                'connection' => config('database.default'),
                'database' => config('database.connections.mysql.database'),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'connected' => false,
                'connection' => config('database.default'),
                'database' => config('database.connections.mysql.database'),
                'error' => 'Database connection failed.',
            ];
        }
    }
}
