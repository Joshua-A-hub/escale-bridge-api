# Plan de migración — ESCALE Bridge API

**Fecha de elaboración:** 2026-11-15
**Rama de trabajo:** `feature/migracion-laravel13-php8.3`
**Baseline congelado:** `24261a4` (`main`, intento anterior con Laravel 10.50.4)
**Entorno verificado:** PHP 8.3.16 (Laragon, Windows x64) + Composer 2.8.4

> Este documento se commitea **antes** de modificar el código, tal como pidió el profesor
> ("plan de trabajo antes de ejecutar el agente"). El baseline queda intacto en `main`
> y en el tag `v1.0.0-php8.3` para poder comparar o revertir.

---

## 1. Contexto y estado inicial auditado

El repositorio `Joshua-A-hub/escale-bridge-api` es un fork de `hubelsolis/brigesnote`
(PHP 7.4 / Laravel 7). El intento anterior hizo una migración **funcional pero incompleta**:

| Punto auditado | Estado encontrado | Riesgo |
|---|---|---|
| Framework | Laravel 10.50.4 (fuera de soporte desde feb-2025) | Dependencias EOL y avisos de seguridad |
| Estructura | Conserva `app/Http/Kernel.php`, `app/Console/Kernel.php`, `RouteServiceProvider`, 5 providers y 9 middleware propios | No se evidencia el cambio de arquitectura que pide la cátedra |
| Document root | `index.php` y `.htaccess` en la raíz del proyecto | Si el docroot queda en la raíz, `.env` y el código quedan expuestos |
| Límite de peticiones | 60/min definido en `RouteServiceProvider` | Se debe conservar al cambiar de esqueleto |
| Ruta `/api/user` | Presente, sin uso real | Superficie innecesaria + dependencia de Sanctum |
| Migraciones | 7 migraciones con `class_exists()` y guardas `Schema::hasTable()` | Patrón obsoleto, propenso a errores |
| Frontend | Laravel Mix 5 (`webpack.mix.js`) para una API sin frontend real | Dependencias Node antiguas e innecesarias |
| Sincronización | Sin paginación ni reintentos | Pérdida de colegios y cortes totales ante fallos del MINEDU |

### Hallazgos funcionales verificados contra la API real del MINEDU

1. **Tope de 50 colegios.** `GET /padron/rest/instituciones?estados=1&ubigeo=...`
   devuelve como máximo 50 elementos. La API **sí acepta el parámetro `start`**
   (offset). Prueba real con el distrito `150101` (Lima): 11 páginas,
   **540 colegios únicos** (549 filas con 9 duplicados por variación de orden).
   El código actual solo guarda los primeros 50.
2. **Cortes del MINEDU.** Cualquier respuesta fallida hace `return` y detiene toda
   la cascada región → provincia → distrito → colegios. No hay `retry` ni
   continuación con las demás ramas.

---

## 2. Objetivo

Migrar el servicio a **Laravel 13.42** sobre **PHP 8.3**, adoptando el esqueleto
moderno del framework y corrigiendo los defectos funcionales y de seguridad,
sin romper el contrato REST existente:

- `GET /api/regiones`
- `GET /api/provincias/{idRegion}`
- `GET /api/distritos/{idProvince}`
- `GET /api/colegios/{ubigeo}`

La respuesta JSON (`status` + `data` / `message`) se mantiene para no romper a los
clientes que ya consumen la API.

### ¿Por qué Laravel 13 y no 12?

- Laravel 13 exige `php: ^8.3` y **resuelve correctamente con PHP 8.3.16**
  (verificado con `composer update --dry-run`: framework v13.42.0 + Symfony 7.4).
- Es la versión vigente y con soporte de seguridad más prolongado.
- El compañero `larzyor` aparece con Laravel 13.42 pero su proyecto no instala en
  PHP 8.3; acá se demuestra que sí es posible si se declara bien la plataforma.

---

## 3. Alcance

### Incluido

1. Actualización de `composer.json`/`composer.lock` a Laravel 13, PHPUnit 12,
   Collision 8, Pint y Tinker 3. Se elimina Sanctum (no se usa autenticación).
2. Esqueleto Laravel 13: `bootstrap/app.php` con `Application::configure()`,
   `bootstrap/providers.php`, sin `Http/Console Kernel`, sin `RouteServiceProvider`,
   sin `Exceptions/Handler`, sin middleware propio.
3. Seguridad: se eliminan `index.php`, `.htaccess`, `server.php` y `web.config`
   de la raíz. El único entrypoint es `public/index.php`; el docroot debe ser `public/`.
4. API: se elimina `/api/user`; se conserva el rate limit de **60 req/min por
   usuario/IP**; se agregan restricciones numéricas a las rutas.
5. Sincronización:
   - paginación con `start` hasta agotar los colegios de cada distrito;
   - deduplicación por `codMod`;
   - `retry` (3 intentos) y *timeout* por petición;
   - continuación de la cascada ante fallos, con conteo de errores y exit code
     `FAILURE` si hubo algún fallo;
   - resumen final de regiones/provincias/distritos/colegios sincronizados.
6. Migraciones anónimas, sin `class_exists()` ni guardas `hasTable()`.
7. Eliminación del frontend de ejemplo y de Laravel Mix: el servicio queda
   **API-only**; la raíz responde JSON con metadatos y `/up` queda como health check.
8. Tests nuevos: rate limit 60/min, paginación de 120 colegios, reintentos,
   continuación ante fallos, raíz JSON, `/api/user` eliminado.
9. Documentación: este plan, `README.md` y `AGENT.md` actualizados con ADRs,
   bitácora y evidencia.

### Fuera de alcance

- Cambiar el esquema de datos o los nombres de tablas/columnas.
- Implementar autenticación real (la API es pública por diseño).
- Reescribir el frontend: se elimina porque no existe un frontend funcional.
- Rediseñar el contrato REST.

---

## 4. Fases y commits

| Fase | Contenido | Commit previsto |
|---|---|---|
| 0 | Este plan, antes de tocar código | `docs: plan de migración a Laravel 13 y PHP 8.3` |
| 1 | `composer.json` + `composer.lock` + plataforma PHP 8.3 | `build(deps): upgrade to Laravel 13 on PHP 8.3` |
| 2 | Esqueleto Laravel 13 y limpieza de Kernels/providers/middleware | `refactor: adopt Laravel 13 application skeleton` |
| 3 | Docroot `public/`, eliminación de entrypoints de raíz y `.env` fuera del webroot | `fix(security): move entrypoint to public and remove root index` |
| 4 | API: sin `/api/user`, rate limit 60/min, restricciones de rutas | `refactor(api): remove unused auth route and keep 60 rpm limiter` |
| 5 | Sync: paginación, reintentos y continuación | `fix(sync): paginate MINEDU schools and retry transient failures` |
| 6 | Migraciones anónimas y eliminación de Mix/frontend | `refactor: modernize migrations and drop unused frontend` |
| 7 | Tests y documentación final | `test: cover rate limit, pagination and resilient sync` |
| 8 | Merge a `main`, tag y push | `merge: laravel 13 migration` + tag `v2.0.0-laravel13` |

Cada commit debe pasar `php -l`, la suite de tests y `composer validate` cuando aplique.
No se reescribe la historia: se agrega una rama nueva sobre `main`.

---

## 5. Riesgos y mitigación

| Riesgo | Probabilidad | Mitigación |
|---|---|---|
| Laravel 13 no resuelve en PHP 8.3 | Baja (dry-run OK) | Si falla, bajar a Laravel 12.72 (también soporta 8.3) sin cambiar el resto del plan |
| El esqueleto nuevo rompe el arranque | Media | Probar `php artisan about`, `route:list` y la suite después de cada fase |
| La API del MINEDU cambia o limita | Media | Reintentos, timeout, continuación y tests con `Http::fake`; la paginación se valida contra la API real |
| El rate limit se pierde al migrar | Media | `throttleApi('api')` + test explícito de 61 peticiones |
| El push falla por credenciales | Media | Commit local garantizado; el push se hace con aprobación; si falla, se entrega el comando exacto |
| Sync muy lento por `updateOrCreate` fila a fila | Media | Se mide con un distrito real; si hace falta, se evalúa `upsert` por página |

---

## 6. Criterios de aceptación

1. `composer install` termina sin errores usando PHP 8.3.16.
2. `php artisan about` reporta **Laravel 13.x** y PHP 8.3.x.
3. No existen `app/Http/Kernel.php`, `app/Console/Kernel.php`,
   `app/Providers/RouteServiceProvider.php`, ni `index.php` en la raíz.
4. `php artisan route:list` muestra solo los 4 endpoints + `/` + `/up`;
   `/api/user` devuelve 404.
5. La petición 61 en un minuto a `/api/regiones` devuelve **429**.
6. Un distrito con 120 colegios en 3 páginas de 50/50/20 termina con 120 filas.
7. Un fallo 500 temporal se reintenta y no impide sincronizar las demás ramas.
8. `php vendor/bin/phpunit` pasa completo (mínimo 12 tests).
9. `composer audit` no reporta vulnerabilidades en dependencias de producción.
10. `git status` limpio; rama y `main` pusheadas a `origin`.

---

## 7. Comandos de verificación

```bash
# Plataforma y framework
php -v
composer validate --strict
composer audit
php artisan about
php artisan route:list

# Calidad
php -l app/Console/Commands/SyncLocations.php
php vendor/bin/phpunit

# Migraciones en SQLite (sin tocar MySQL)
php artisan migrate:fresh --database=sqlite --force
```

---

## 8. Nota sobre el proceso

El baseline `24261a4` ya estaba commiteado y pusheado, por lo que se cumple
"commits antes de inicializar". A partir de este plan, cada fase se commitea por
separado para que el profesor pueda leer la evolución y no solo el resultado final.
