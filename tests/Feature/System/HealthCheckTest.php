<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HealthCheckTest extends TestCase
{
    public function test_health_check_endpoint_returns_successful_response(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'application' => [
                    'name',
                    'environment',
                    'debug',
                    'url',
                ],
                'database' => [
                    'connected',
                    'connection',
                    'database',
                ],
            ]);
    }
}
