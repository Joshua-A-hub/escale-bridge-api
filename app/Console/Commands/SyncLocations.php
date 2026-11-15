<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncLocations extends Command
{
    /**
     * El nombre y la firma del comando de consola.
     *
     * @var string
     */
    protected $signature = 'sync:locations';

    /**
     * Descripción del comando de consola.
     *
     * @var string
     */
    protected $description = 'Sincroniza regiones, provincias, distritos y escuelas desde la API de MINEDU/ESCALE y las registra en la base de datos';

    /**
     * Ejecuta el comando de consola.
     */
    public function handle(): int
    {
        $this->info('Iniciando sincronización de ubicaciones desde MINEDU/ESCALE...');

        // Sincronizar regiones en cascada
        $this->syncRegions();

        $this->info('Sincronización completa.');
        return Command::SUCCESS;
    }

    /**
     * Sincroniza el catálogo de regiones/departamentos.
     */
    private function syncRegions(): void
    {
        $this->info('Sincronizando regiones...');

        $response = Http::withHeaders([
            'Accept' => 'application/json',
        ])->timeout(30)->get('https://escale.minedu.gob.pe/padron/rest/regiones');

        if ($response->failed()) {
            $this->error('Error al obtener regiones desde MINEDU');
            return;
        }

        $regions = $response->json('region');
        if (!is_array($regions)) {
            $this->error('El formato de respuesta de regiones no es válido.');
            return;
        }

        foreach ($regions as $region) {
            if (!isset($region['idRegion'])) {
                continue;
            }

            Region::updateOrCreate(
                ['idRegion' => (string) $region['idRegion']],
                [
                    'nombreRegion' => $region['nombreRegion'] ?? null,
                    'pointX' => isset($region['pointX']) ? (float) $region['pointX'] : null,
                    'pointY' => isset($region['pointY']) ? (float) $region['pointY'] : null,
                    'zoom' => isset($region['zoom']) ? (int) $region['zoom'] : null,
                ]
            );

            $this->syncProvinces((string) $region['idRegion']);
        }

        $this->info('Regiones sincronizadas correctamente.');
    }

    /**
     * Sincroniza provincias pertenecientes a una región dada.
     */
    private function syncProvinces(string $regionId): void
    {
        $this->info("Sincronizando provincias para la región {$regionId}...");

        $response = Http::withHeaders([
            'Accept' => 'application/json',
        ])->timeout(30)->get('https://escale.minedu.gob.pe/padron/rest/provincias', [
            'idRegion' => $regionId,
        ]);

        if ($response->failed()) {
            $this->error("Error al obtener provincias para la región {$regionId}");
            return;
        }

        $provinces = $response->json('provincia');
        if (!is_array($provinces)) {
            return;
        }

        // Manejar tanto objeto individual como lista de provincias
        $items = isset($provinces['idProvincia']) ? [$provinces] : $provinces;

        foreach ($items as $province) {
            if (!isset($province['idProvincia'])) {
                continue;
            }

            Province::updateOrCreate(
                ['idProvincia' => (string) $province['idProvincia']],
                [
                    'nombreProvincia' => $province['nombreProvincia'] ?? null,
                    'pointX' => isset($province['pointX']) ? (float) $province['pointX'] : null,
                    'pointY' => isset($province['pointY']) ? (float) $province['pointY'] : null,
                ]
            );

            $this->syncDistricts((string) $province['idProvincia']);
        }

        $this->info("Provincias para la región {$regionId} sincronizadas correctamente.");
    }

    /**
     * Sincroniza distritos pertenecientes a una provincia dada.
     */
    private function syncDistricts(string $provinceId): void
    {
        $this->info("Sincronizando distritos para la provincia {$provinceId}...");

        $response = Http::withHeaders([
            'Accept' => 'application/json',
        ])->timeout(30)->get('https://escale.minedu.gob.pe/padron/rest/distritos', [
            'idProvincia' => $provinceId,
        ]);

        if ($response->failed()) {
            $this->error("Error al obtener distritos para la provincia {$provinceId}");
            return;
        }

        $districts = $response->json('distrito');
        if (!is_array($districts)) {
            return;
        }

        // Manejar tanto objeto individual como lista de distritos
        $items = isset($districts['idDistrito']) ? [$districts] : $districts;

        foreach ($items as $district) {
            if (!isset($district['idDistrito'])) {
                continue;
            }

            District::updateOrCreate(
                ['idDistrito' => (string) $district['idDistrito']],
                [
                    'nombreDistrito' => $district['nombreDistrito'] ?? null,
                ]
            );

            $this->syncSchools((string) $district['idDistrito']);
        }

        $this->info("Distritos para la provincia {$provinceId} sincronizados correctamente.");
    }

    /**
     * Sincroniza instituciones educativas (colegios) de un distrito dado.
     */
    private function syncSchools(string $districtId): void
    {
        $this->info("Sincronizando escuelas para el distrito {$districtId}...");

        $response = Http::withHeaders([
            'Accept' => 'application/json',
        ])->timeout(30)->get('https://escale.minedu.gob.pe/padron/rest/instituciones', [
            'estados' => 1,
            'ubigeo' => $districtId,
        ]);

        if ($response->failed()) {
            $this->error("Error al obtener escuelas para el distrito {$districtId}");
            return;
        }

        $schools = $response->json('items');
        if (!is_array($schools)) {
            return;
        }

        // Manejar tanto objeto individual como lista de escuelas
        $items = isset($schools['codMod']) ? [$schools] : $schools;

        foreach ($items as $school) {
            if (!isset($school['codMod'])) {
                continue;
            }

            School::updateOrCreate(
                ['codMod' => (string) $school['codMod']],
                [
                    'idDistrito' => $districtId,
                    'nombreCenEdu' => $school['cenEdu'] ?? null,
                    'cenPob' => $school['cenPob'] ?? null,
                    'dirCen' => $school['dirCen'] ?? null,
                    'director' => $school['director'] ?? null,
                    'dis_crecer' => $school['dis_crecer'] ?? null,
                    'dis_juntos' => $school['dis_juntos'] ?? null,
                ]
            );
        }

        $this->info("Escuelas para el distrito {$districtId} sincronizadas correctamente.");
    }
}
