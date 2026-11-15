# 🤖 AGENT.md — Registro de arquitectura y bitácora de migración

Este documento es el registro de decisiones de arquitectura (ADR), la bitácora de ejecución y el manual operativo para cualquier agente o desarrollador que interactúe con este repositorio.

---

## 1. 📌 Contexto y objetivo

| Dato | Valor |
|---|---|
| Repositorio propio (`origin`) | [Joshua-A-hub/escale-bridge-api](https://github.com/Joshua-A-hub/escale-bridge-api) |
| Repositorio de la cátedra (`upstream`) | [hubelsolis/brigesnote](https://github.com/hubelsolis/brigesnote) |
| Tarea | Migrar el proyecto a **PHP 8.3** |
| Requisitos del profesor | Plan de trabajo antes de ejecutar el agente, commits antes de inicializar, push |
| Fecha límite | Lunes 16/11/2026 |
| Estado | ✅ Migración completada, testeada y commiteada |

El proyecto original es un **Bridge / Cache Proxy**: consume la API pública del MINEDU/ESCALE, guarda los datos en una base local y los sirve mediante una API REST a sistemas de admisión, matrícula e intranets.

El contrato REST se mantuvo intacto:

- `GET /api/regiones`
- `GET /api/provincias/{idRegion}`
- `GET /api/distritos/{idProvince}`
- `GET /api/colegios/{ubigeo}`

---

## 2. 🏛️ Arquitectura del sistema

```
[ MINEDU / ESCALE ]  https://escale.minedu.gob.pe/padron/rest
          │
          │  php artisan sync:locations
          │  (paginación start + retry 3)
          ▼
[ MySQL / MariaDB / SQLite ]
  regions ─ provinces ─ districts ─ schools
          │
          ▼
[ Laravel 13 / PHP 8.3 — API REST ]
  LocationController
          │
          ▼
[ Clientes externos ]
```

### Componentes

1. **Comando de sincronización:** `app/Console/Commands/SyncLocations.php`
   - paginación de colegios con `start`;
   - deduplicación por `codMod`;
   - reintentos y continuación ante fallos;
   - resumen y exit code.
2. **Controlador REST:** `app/Http/Controllers/LocationController.php`
   - consultas ANSI (`SUBSTR`) compatibles con MySQL y SQLite;
   - respuestas `status` + `data` / `message`.
3. **Modelos:** `Region`, `Province`, `District`, `School`, `User`.
4. **Rutas:** `routes/api.php` (4 endpoints), `routes/web.php` (raíz JSON), `routes/console.php`.
5. **Rate limiting:** `app/Providers/AppServiceProvider.php` (60/min).
6. **Configuración del framework:** `bootstrap/app.php` + `bootstrap/providers.php`.
7. **Pruebas:** `tests/Feature/*` (16 tests, 109 aserciones).

---

## 3. ⚖️ Decisiones de arquitectura (ADR)

### ADR-001 — Flujo de Git: upstream, origin y rama de migración

- **Decisión:** conservar `upstream` (`hubelsolis/brigesnote`) y `origin` (`Joshua-A-hub/escale-bridge-api`); trabajar en `feature/migracion-laravel13-php8.3` y fusionar a `main`.
- **Razón:** trazabilidad, posibilidad de comparar contra la cátedra y cumplimiento del flujo fork + branch + merge.
- **Baseline:** `24261a4` (intento anterior en Laravel 10), preservado en `main` y en el tag `v1.0.0-php8.3`.
- **Plan:** `PLAN_MIGRACION.md` se commiteó **antes** de tocar código (`54fd2b3`).

### ADR-002 — Laravel 13.42 sobre PHP 8.3

- **Problema:** Laravel 10.50.4 ya está fuera de soporte y el intento anterior solo subió la restricción de Composer, sin cambiar el esqueleto.
- **Decisión:** migrar a **Laravel 13.42.0** declarando `php: ^8.3`.
- **Verificación:** `composer update --dry-run` resolvió Laravel 13.42 + Symfony 7.4 con PHP 8.3.16; el proyecto instala y corre en 8.3.
- **Por qué no Laravel 12:** 13 es la versión vigente y también soporta PHP 8.3; no hay motivo para quedarse una versión atrás.
- **Por qué no exigir PHP 8.4:** la tarea pide PHP 8.3; exigir 8.4 incumpliría la letra del encargo (el caso del compañero que usa Laravel 13 con 8.4.1).

### ADR-003 — Esqueleto Laravel 13, sin kernels ni providers propios

- **Decisión:** reemplazar el esqueleto Laravel 7/10 por el de Laravel 13:
  - `bootstrap/app.php` con `Application::configure()`;
  - `bootstrap/providers.php` con `AppServiceProvider`;
  - kernels del framework (`Illuminate\Foundation\Http\Kernel` y `Console\Kernel`);
  - sin `RouteServiceProvider`, `AuthServiceProvider`, `EventServiceProvider`, `BroadcastServiceProvider`;
  - sin `app/Http/Middleware` propio.
- **Razón:** es el cambio de arquitectura visible que pide la cátedra; además reduce código propio que mantener.
- **Routing:** `withRouting(web, api, commands, health: '/up')` registra las rutas y el health check.
- **Excepciones:** `shouldRenderJsonWhen()` garantiza JSON en `api/*`.

### ADR-004 — Docroot en `public/` y `.env` fuera del webroot

- **Problema:** el intento anterior conservaba `index.php` en la raíz. Si el servidor apuntaba ahí, `.env`, `vendor/` y el código quedaban accesibles.
- **Decisión:** eliminar `index.php`, `.htaccess`, `server.php`, `web.config`, `favicon.ico` y `robots.txt` de la raíz. El único entrypoint es `public/index.php`.
- **Consecuencia:** el document root **debe** ser `public/` (documentado en README). En `php artisan serve` ya es así.
- **Verificación:** no existe ningún archivo ejecutable en la raíz; `.env` está fuera de `public/`.

### ADR-005 — Rate limit de 60 peticiones por minuto

- **Problema:** al eliminar `RouteServiceProvider` se perdía el límite histórico.
- **Decisión:** definir el limiter `api` en `AppServiceProvider::boot()` y activarlo con `$middleware->throttleApi('api')` en `bootstrap/app.php`.
- **Comportamiento:** 60 req/min por usuario autenticado o IP; la petición 61 devuelve `429` con `Retry-After`.
- **Verificación:** test `test_api_is_limited_to_sixty_requests_per_minute`.

### ADR-006 — API-only: sin Sanctum, sin `/api/user`, sin Laravel Mix

- **Problema:** la API es pública; `/api/user` no se usaba, Sanctum solo existía por esa ruta y Laravel Mix compilaba assets de un welcome que no era un frontend real.
- **Decisión:**
  - eliminar `/api/user` y la dependencia `laravel/sanctum`;
  - eliminar `HasApiTokens` del modelo `User`;
  - eliminar `package.json`, `webpack.mix.js`, `resources/js`, `resources/sass` y `resources/views`;
  - la raíz `/` responde JSON con metadatos y `/up` es el health check.
- **Razón:** menos superficie de ataque, menos dependencias y un servicio coherente con la arquitectura de microservicios.
- **Nota:** la autenticación queda fuera de alcance; si se necesita, se puede agregar Sanctum o Passport con una decisión posterior.

### ADR-007 — Sincronización resiliente y paginada

- **Problema 1:** la API devuelve máximo 50 colegios por página; el código anterior guardaba solo los primeros 50 de cada distrito.
- **Problema 2:** cualquier fallo HTTP detenía toda la cascada.
- **Decisiones:**
  1. paginar con `start=0, 50, 100...` hasta que la página tenga menos de 50 o no aporte filas nuevas;
  2. deduplicar por `codMod` (la API puede repetir filas entre páginas);
  3. `retry(3, 500)` con timeout de 30 s y conexión de 10 s;
  4. continuar con las demás ramas ante un fallo y devolver exit code `1` si hubo errores;
  5. tope de seguridad de 200 páginas por distrito.
- **Evidencia en vivo:** distrito `150101` (Lima) → 540 colegios únicos en 11 páginas (549 filas, 9 duplicados).
- **Tests:** `SyncLocationsTest` cubre 120 colegios en 3 páginas, deduplicación, reintentos y continuación.

### ADR-008 — Migraciones anónimas

- **Decisión:** convertir las 7 migraciones a `return new class extends Migration` con `up(): void` / `down(): void`.
- **Razón:** es el formato estándar desde Laravel 8.37; se eliminan `class_exists()` y las guardas `Schema::hasTable()` que ocultaban errores.
- **Compatibilidad:** se conservan los nombres de tablas y columnas (incluida `password_resets`, usada por `config/auth.php`).

### ADR-009 — Calidad y verificación

- **Tests:** PHPUnit 12.5.44; 16 tests, 109 aserciones; SQLite en memoria.
- **Estilo:** Laravel Pint 1.x; `pint --test` pasa en 46 archivos.
- **Dependencias:** `composer validate --strict` válido y `composer audit` sin vulnerabilidades.
- **Cobertura:** endpoints, 404, paginación, deduplicación, reintentos, continuación, rate limit, raíz, health check y ausencia de `/api/user`.

---

## 4. 📋 Bitácora de fases

| Fase | Descripción | Estado | Commit |
|---|---|---|---|
| 0 | Auditoría y plan antes de ejecutar el agente | ✅ | `54fd2b3` |
| 1 | Upgrade de dependencias a Laravel 13 / PHP 8.3 | ✅ | `40e389d` |
| 2 | Esqueleto Laravel 13 y docroot `public/` | ✅ | `c3312b3` |
| 3 | API sin `/api/user` y rate limit 60/min | ✅ | `bf86a34` |
| 4 | Sync con paginación, reintentos y continuación | ✅ | `7bd98ab` |
| 5 | Migraciones anónimas | ✅ | `bf9ae47` |
| 6 | Eliminación del frontend Laravel Mix | ✅ | `f794067` |
| 7 | Pint + limpieza de tests de ejemplo | ✅ | `501a743` |
| 8 | Documentación final (README + AGENT) | ✅ | `b9d6d08` + `d2cc251` |
| 9 | Merge a `main`, tag y push | ✅ | `b8f3fcf` + tag `v2.0.0-laravel13` |

> El tag `v1.0.0-php8.3` marca el intento anterior; no se reescribió la historia.

---

## 5. 🛠️ Entorno de verificación

| Recurso | Valor |
|---|---|
| PHP CLI | `C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe` |
| PHP | 8.3.16 (ZTS x64) |
| Composer | 2.8.4 (`C:\laragon\bin\composer\composer.phar`) |
| Laravel | 13.42.0 |
| PHPUnit | 12.5.44 |
| Base de datos de tests | SQLite `:memory:` |
| Base de datos local | configurable por `.env` (MySQL/MariaDB/SQLite) |

---

## 6. ✅ Evidencia de aceptación

```text
$ php artisan about
Laravel Version ................................ 13.42.0
PHP Version .................................... 8.3.16
Timezone ....................................... America/Lima

$ php artisan route:list --except-vendor
GET|HEAD  /                         home
GET|HEAD  api/colegios/{ubigeo}     api.colegios
GET|HEAD  api/distritos/{idProvince} api.distritos
GET|HEAD  api/provincias/{idRegion}  api.provincias
GET|HEAD  api/regiones              api.regiones

$ php vendor/bin/phpunit
OK (16 tests, 109 assertions)

$ composer validate --strict
./composer.json is valid

$ composer audit
No security vulnerability advisories found.

$ vendor/bin/pint --test
PASS (46 files)
```

Comprobaciones manuales:

- `GET /api/user` → `404`.
- Petición 61 a `/api/regiones` → `429`.
- Distrito `150101`: 540 colegios únicos (paginación real contra MINEDU).
- No existe `index.php` en la raíz; el entrypoint es `public/index.php`.

---

## 7. ⚠️ Limitaciones conocidas y siguientes pasos

1. **Duración del sync completo.** Recorrer todo el Perú implica miles de peticiones; el comando es correcto pero puede tardar. Próximo paso: `--region=`/`--province=` para sincronizaciones parciales y un job en cola.
2. **Dependencia del MINEDU.** Los reintentos mitigan cortes, pero no controlan la disponibilidad del Estado. Se puede agregar cache o un snapshot periódico.
3. **Sin autenticación.** La API es pública por diseño; si se necesita, agregar Sanctum/Passport con una ADR nueva.
4. **Sin CI.** Próximo paso: GitHub Actions con `composer install`, `phpunit`, `pint --test` y `composer audit`.
5. **OpenAPI.** La cátedra mencionó una posible migración a OpenAPI; el contrato actual puede documentarse con un `openapi.yaml` sin cambiar los endpoints.

---

## 8. 📌 Proceso exigido por la cátedra

1. **Plan antes del agente:** `PLAN_MIGRACION.md` (`54fd2b3`) se escribió y commiteó antes de modificar el código.
2. **Commits antes de inicializar:** el baseline `24261a4` ya estaba commiteado y pusheado; la rama nueva parte de ahí.
3. **Push:** rama `feature/migracion-laravel13-php8.3`, merge a `main` y tag `v2.0.0-laravel13` publicados en `origin`.
