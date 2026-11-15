<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
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
                'message' => 'No se encontraron provincias para esta región.',
            ]);
    }

    public function test_get_districts_returns_404_when_not_found(): void
    {
        $response = $this->getJson('/api/distritos/9999');

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'No se encontraron distritos para esta provincia.',
            ]);
    }

    public function test_get_schools_returns_404_when_not_found(): void
    {
        $response = $this->getJson('/api/colegios/999999');

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'No se encontraron escuelas para este distrito.',
            ]);
    }

    public function test_sync_locations_command_syncs_data_correctly(): void
    {
        Http::fake([
            'https://escale.minedu.gob.pe/padron/rest/regiones' => Http::response([
                'region' => [
                    [
                        'idRegion' => '15',
                        'nombreRegion' => 'LIMA',
                        'pointX' => -12.046374,
                        'pointY' => -77.042793,
                        'zoom' => 10,
                    ],
                ],
            ], 200),
            'https://escale.minedu.gob.pe/padron/rest/provincias*' => Http::response([
                'provincia' => [
                    [
                        'idProvincia' => '1501',
                        'nombreProvincia' => 'LIMA',
                        'pointX' => -12.046374,
                        'pointY' => -77.042793,
                    ],
                ],
            ], 200),
            'https://escale.minedu.gob.pe/padron/rest/distritos*' => Http::response([
                'distrito' => [
                    [
                        'idDistrito' => '150101',
                        'nombreDistrito' => 'LIMA',
                    ],
                ],
            ], 200),
            'https://escale.minedu.gob.pe/padron/rest/instituciones*' => Http::response([
                'items' => [
                    [
                        'codMod' => '0273572',
                        'cenEdu' => 'JUAN JIMENEZ PIMENTEL',
                        'cenPob' => 'LIMA',
                        'dirCen' => 'JR. LAMPA 240',
                        'director' => 'PEDRO PEREZ',
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('sync:locations')
            ->expectsOutput('Iniciando sincronización de ubicaciones desde MINEDU/ESCALE...')
            ->assertExitCode(0);

        $this->assertDatabaseHas('regions', ['idRegion' => '15', 'nombreRegion' => 'LIMA']);
        $this->assertDatabaseHas('provinces', ['idProvincia' => '1501', 'nombreProvincia' => 'LIMA']);
        $this->assertDatabaseHas('districts', ['idDistrito' => '150101', 'nombreDistrito' => 'LIMA']);
        $this->assertDatabaseHas('schools', ['codMod' => '0273572', 'idDistrito' => '150101']);
    }
}
