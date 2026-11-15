<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_returns_service_metadata(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertJsonPath('service', 'ESCALE Bridge API')
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure([
                'service',
                'status',
                'php',
                'laravel',
                'endpoints',
                'health',
            ]);
    }

    public function test_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_unused_api_user_route_is_gone(): void
    {
        $this->getJson('/api/user')->assertNotFound();
    }

    public function test_api_is_limited_to_sixty_requests_per_minute(): void
    {
        for ($request = 1; $request <= 60; $request++) {
            $this->getJson('/api/regiones')->assertOk();
        }

        $this->getJson('/api/regiones')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }
}
