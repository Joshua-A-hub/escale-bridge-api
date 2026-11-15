<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class LocationApiTest extends TestCase
{
    use DatabaseMigrations;

    public function test_get_regions_returns_successful_json_response(): void
    {
        Region::create([
            'idRegion' => '15',
            'nombreRegion' => 'LIMA',
            'pointX' => -12.046374,
            'pointY' => -77.042793,
            'zoom' => 10,
        ]);

        $response = $this->getJson('/api/regiones');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    [
                        'idRegion' => '15',
                        'nombreRegion' => 'LIMA',
                    ],
                ],
            ]);
    }

    public function test_get_provinces_by_region_returns_data(): void
    {
        Province::create([
            'idProvincia' => '1501',
            'nombreProvincia' => 'LIMA',
            'pointX' => -12.046374,
            'pointY' => -77.042793,
        ]);

        $response = $this->getJson('/api/provincias/15');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    [
                        'idProvincia' => '1501',
                        'nombreProvincia' => 'LIMA',
                    ],
                ],
            ]);
    }

    public function test_get_districts_by_province_returns_data(): void
    {
        District::create([
            'idDistrito' => '150101',
            'nombreDistrito' => 'LIMA',
        ]);

        $response = $this->getJson('/api/distritos/1501');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    [
                        'idDistrito' => '150101',
                        'nombreDistrito' => 'LIMA',
                    ],
                ],
            ]);
    }

    public function test_get_schools_by_ubigeo_returns_data(): void
    {
        School::create([
            'codMod' => '0273572',
            'idDistrito' => '150101',
            'nombreCenEdu' => 'JUAN JIMENEZ PIMENTEL',
            'cenPob' => 'LIMA',
            'dirCen' => 'JR. LAMPA 240',
            'director' => 'PEDRO PEREZ',
        ]);

        $response = $this->getJson('/api/colegios/150101');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    [
                        'codMod' => '0273572',
                        'idDistrito' => '150101',
                        'nombreCenEdu' => 'JUAN JIMENEZ PIMENTEL',
                    ],
                ],
            ]);
    }

    public function test_get_provinces_returns_404_when_not_found(): void
    {
        $response = $this->getJson('/api/provincias/99');

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
            ]);
    }
}
