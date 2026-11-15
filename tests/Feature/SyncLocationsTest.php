<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncLocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_paginates_all_schools_beyond_fifty(): void
    {
        $schools = [];
        for ($i = 1; $i <= 120; $i++) {
            $schools[] = [
                'codMod' => sprintf('S%05d', $i),
                'cenEdu' => "Colegio {$i}",
                'cenPob' => 'LIMA',
                'dirCen' => 'DIRECCION',
                'director' => 'DIRECTOR',
            ];
        }

        Http::fake(function (ClientRequest $request) use ($schools) {
            $url = $request->url();

            if (str_contains($url, '/regiones')) {
                return Http::response([
                    'region' => [['idRegion' => '15', 'nombreRegion' => 'LIMA']],
                ]);
            }

            if (str_contains($url, '/provincias')) {
                return Http::response([
                    'provincia' => [['idProvincia' => '1501', 'nombreProvincia' => 'LIMA']],
                ]);
            }

            if (str_contains($url, '/distritos')) {
                return Http::response([
                    'distrito' => [['idDistrito' => '150101', 'nombreDistrito' => 'LIMA']],
                ]);
            }

            if (str_contains($url, '/instituciones')) {
                $start = (int) ($request->data()['start'] ?? 0);

                return Http::response([
                    'items' => array_slice($schools, $start, 50),
                ]);
            }

            return Http::response([], 404);
        });

        $this->artisan('sync:locations')->assertExitCode(0);

        $this->assertSame(120, School::count());
        $this->assertDatabaseHas('schools', ['codMod' => 'S00120']);
    }

    public function test_sync_retries_transient_failures(): void
    {
        $regionCalls = 0;

        Http::fake(function (ClientRequest $request) use (&$regionCalls) {
            $url = $request->url();

            if (str_contains($url, '/regiones')) {
                $regionCalls++;

                if ($regionCalls < 3) {
                    return Http::response(['error' => 'fallo temporal'], 500);
                }

                return Http::response([
                    'region' => [['idRegion' => '15', 'nombreRegion' => 'LIMA']],
                ]);
            }

            if (str_contains($url, '/provincias')) {
                return Http::response([
                    'provincia' => [['idProvincia' => '1501', 'nombreProvincia' => 'LIMA']],
                ]);
            }

            if (str_contains($url, '/distritos')) {
                return Http::response([
                    'distrito' => [['idDistrito' => '150101', 'nombreDistrito' => 'LIMA']],
                ]);
            }

            return Http::response(['items' => []]);
        });

        $this->artisan('sync:locations')->assertExitCode(0);

        $this->assertSame(3, $regionCalls);
        $this->assertDatabaseHas('regions', ['idRegion' => '15']);
    }

    public function test_sync_continues_after_a_region_failure(): void
    {
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            if (str_contains($url, '/regiones')) {
                return Http::response([
                    'region' => [
                        ['idRegion' => '15', 'nombreRegion' => 'LIMA'],
                        ['idRegion' => '16', 'nombreRegion' => 'AMAZONAS'],
                    ],
                ]);
            }

            if (str_contains($url, '/provincias')) {
                if (str_contains($url, 'idRegion=15')) {
                    return Http::response(['error' => 'MINEDU caido'], 500);
                }

                return Http::response([
                    'provincia' => [['idProvincia' => '1601', 'nombreProvincia' => 'CHACHAPOYAS']],
                ]);
            }

            if (str_contains($url, '/distritos')) {
                return Http::response([
                    'distrito' => [['idDistrito' => '160101', 'nombreDistrito' => 'CHACHAPOYAS']],
                ]);
            }

            return Http::response([
                'items' => [['codMod' => 'S00001', 'cenEdu' => 'COLEGIO']],
            ]);
        });

        $this->artisan('sync:locations')->assertExitCode(1);

        $this->assertDatabaseHas('regions', ['idRegion' => '15']);
        $this->assertDatabaseHas('regions', ['idRegion' => '16']);
        $this->assertDatabaseHas('provinces', ['idProvincia' => '1601']);
        $this->assertDatabaseHas('schools', ['codMod' => 'S00001']);
        $this->assertSame(0, Province::where('idProvincia', '1501')->count());
    }

    public function test_sync_deduplicates_schools_repeated_across_pages(): void
    {
        $page = [];
        for ($i = 1; $i <= 50; $i++) {
            $page[] = ['codMod' => sprintf('D%05d', $i), 'cenEdu' => "Colegio {$i}"];
        }

        Http::fake(function (ClientRequest $request) use ($page) {
            $url = $request->url();

            if (str_contains($url, '/regiones')) {
                return Http::response(['region' => [['idRegion' => '15', 'nombreRegion' => 'LIMA']]]);
            }
            if (str_contains($url, '/provincias')) {
                return Http::response(['provincia' => [['idProvincia' => '1501', 'nombreProvincia' => 'LIMA']]]);
            }
            if (str_contains($url, '/distritos')) {
                return Http::response(['distrito' => [['idDistrito' => '150101', 'nombreDistrito' => 'LIMA']]]);
            }

            // Todas las páginas repiten los mismos 50 registros: debe detenerse.
            return Http::response(['items' => $page]);
        });

        $this->artisan('sync:locations')->assertExitCode(0);

        $this->assertSame(50, School::count());
        $this->assertSame(1, Region::count());
        $this->assertSame(1, Province::count());
    }
}
