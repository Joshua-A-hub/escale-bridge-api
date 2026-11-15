<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SyncLocations extends Command
{
    /**
     * URL base de la API pública de MINEDU / ESCALE.
     */
    private const BASE_URL = 'https://escale.minedu.gob.pe/padron/rest';

    /**
     * La API devuelve como máximo 50 colegios por página.
     */
    private const SCHOOLS_PAGE_SIZE = 50;

    /**
     * Tope de seguridad para evitar un bucle infinito si la API ignora "start".
     */
    private const MAX_SCHOOL_PAGES_PER_DISTRICT = 200;

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
     * Cantidad de registros sincronizados por tipo.
     *
     * @var array{regions: int, provinces: int, districts: int, schools: int}
     */
    private array $counts = [
        'regions' => 0,
        'provinces' => 0,
        'districts' => 0,
        'schools' => 0,
    ];

    /**
     * Cantidad de fallos acumulados durante la sincronización.
     */
    private int $failures = 0;

    /**
     * Ejecuta el comando de consola.
     */
    public function handle(): int
    {
        $this->info('Iniciando sincronización de ubicaciones desde MINEDU/ESCALE...');

        $this->syncRegions();

        $this->info(sprintf(
            'Sincronización finalizada: %d regiones, %d provincias, %d distritos y %d colegios.',
            $this->counts['regions'],
            $this->counts['provinces'],
            $this->counts['districts'],
            $this->counts['schools'],
        ));

        if ($this->failures > 0) {
            $this->warn(sprintf(
                'Se completó con %d error(es). Los datos ya sincronizados se conservaron.',
                $this->failures,
            ));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Sincroniza el catálogo de regiones/departamentos.
     */
    private function syncRegions(): void
    {
        $this->info('Sincronizando regiones...');

        $data = $this->fetch('/regiones');
        if ($data === null) {
            return;
        }

        $regions = $data['region'] ?? [];
        if (! is_array($regions)) {
            $this->recordFailure('El formato de respuesta de regiones no es válido.');

            return;
        }

        $items = isset($regions['idRegion']) ? [$regions] : $regions;

        foreach ($items as $region) {
            if (! isset($region['idRegion'])) {
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

            $this->counts['regions']++;
            $this->syncProvinces((string) $region['idRegion']);
        }

        $this->info("Regiones sincronizadas: {$this->counts['regions']}.");
    }

    /**
     * Sincroniza provincias pertenecientes a una región dada.
     */
    private function syncProvinces(string $regionId): void
    {
        $this->line("Sincronizando provincias para la región {$regionId}...");

        $data = $this->fetch('/provincias', ['idRegion' => $regionId]);
        if ($data === null) {
            return;
        }

        $provinces = $data['provincia'] ?? [];
        if (! is_array($provinces)) {
            $this->recordFailure("El formato de provincias de la región {$regionId} no es válido.");

            return;
        }

        $items = isset($provinces['idProvincia']) ? [$provinces] : $provinces;

        foreach ($items as $province) {
            if (! isset($province['idProvincia'])) {
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

            $this->counts['provinces']++;
            $this->syncDistricts((string) $province['idProvincia']);
        }
    }

    /**
     * Sincroniza distritos pertenecientes a una provincia dada.
     */
    private function syncDistricts(string $provinceId): void
    {
        $this->line("Sincronizando distritos para la provincia {$provinceId}...");

        $data = $this->fetch('/distritos', ['idProvincia' => $provinceId]);
        if ($data === null) {
            return;
        }

        $districts = $data['distrito'] ?? [];
        if (! is_array($districts)) {
            $this->recordFailure("El formato de distritos de la provincia {$provinceId} no es válido.");

            return;
        }

        $items = isset($districts['idDistrito']) ? [$districts] : $districts;

        foreach ($items as $district) {
            if (! isset($district['idDistrito'])) {
                continue;
            }

            District::updateOrCreate(
                ['idDistrito' => (string) $district['idDistrito']],
                ['nombreDistrito' => $district['nombreDistrito'] ?? null]
            );

            $this->counts['districts']++;
            $this->syncSchools((string) $district['idDistrito']);
        }
    }

    /**
     * Sincroniza las instituciones educativas (colegios) de un distrito.
     *
     * La API de ESCALE devuelve 50 registros por página y acepta el parámetro
     * "start" como desplazamiento. Se recorre hasta agotar el distrito,
     * deduplicando por codMod por si la API repite filas entre páginas.
     */
    private function syncSchools(string $districtId): void
    {
        $start = 0;
        $pages = 0;
        $seen = [];

        do {
            $data = $this->fetch('/instituciones', [
                'estados' => 1,
                'ubigeo' => $districtId,
                'start' => $start,
            ]);

            if ($data === null) {
                return;
            }

            $schools = $data['items'] ?? [];
            if (! is_array($schools)) {
                $this->recordFailure("El formato de colegios del distrito {$districtId} no es válido.");

                return;
            }

            $items = isset($schools['codMod']) ? [$schools] : $schools;
            $newRows = 0;

            foreach ($items as $school) {
                if (! isset($school['codMod'])) {
                    continue;
                }

                $codMod = (string) $school['codMod'];
                if (isset($seen[$codMod])) {
                    continue;
                }

                $seen[$codMod] = true;
                $newRows++;

                School::updateOrCreate(
                    ['codMod' => $codMod],
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

            $this->counts['schools'] += $newRows;
            $start += self::SCHOOLS_PAGE_SIZE;
            $pages++;

            // Si la API ignorara "start", dejaría de haber filas nuevas.
            $hasNextPage = count($items) >= self::SCHOOLS_PAGE_SIZE && $newRows > 0;
        } while ($hasNextPage && $pages < self::MAX_SCHOOL_PAGES_PER_DISTRICT);

        if ($pages >= self::MAX_SCHOOL_PAGES_PER_DISTRICT) {
            $this->recordFailure("Se alcanzó el tope de páginas para el distrito {$districtId}.");
        }
    }

    /**
     * Ejecuta una petición GET con reintentos y timeout.
     *
     * Devuelve null cuando el fallo impide continuar con esa rama; devuelve
     * un arreglo vacío cuando la API responde 404 (sin datos).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function fetch(string $path, array $query = []): ?array
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->retry(3, 500, throw: false)
                ->get(self::BASE_URL.$path, $query);
        } catch (ConnectionException $exception) {
            $this->recordFailure("No se pudo conectar con MINEDU ({$path}): {$exception->getMessage()}");

            return null;
        }

        if ($response->status() === 404) {
            return [];
        }

        if ($response->failed()) {
            $this->recordFailure("MINEDU respondió HTTP {$response->status()} en {$path}.");

            return null;
        }

        $data = $response->json();
        if (! is_array($data)) {
            $this->recordFailure("Respuesta JSON inválida en {$path}.");

            return null;
        }

        return $data;
    }

    /**
     * Registra un fallo sin interrumpir la sincronización.
     */
    private function recordFailure(string $message): void
    {
        $this->failures++;
        $this->warn($message);
    }
}
