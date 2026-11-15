# 🏫 ESCALE Bridge API — Laravel 13 / PHP 8.3

Microservicio REST que sincroniza y sirve el catálogo oficial de **regiones, provincias, distritos y colegios** del Perú, consumiendo la API pública de **MINEDU / ESCALE** y exponiendo los datos desde una base local.

> **Estado:** migración completada a **Laravel 13.42.0** sobre **PHP 8.3.16**, con esqueleto moderno, docroot en `public/`, 16 tests automatizados y auditoría de dependencias limpia.
>
> Este repositorio es un fork de [`hubelsolis/brigesnote`](https://github.com/hubelsolis/brigesnote). El repositorio propio es [`Joshua-A-hub/escale-bridge-api`](https://github.com/Joshua-A-hub/escale-bridge-api).

---

## 📋 Contenido

1. [Qué cambió respecto del intento anterior](#-qué-cambió-respecto-del-intento-anterior)
2. [Arquitectura](#-arquitectura)
3. [Requisitos](#-requisitos)
4. [Instalación](#-instalación)
5. [Variables de entorno](#-variables-de-entorno)
6. [Endpoints de la API](#-endpoints-de-la-api)
7. [Sincronización con MINEDU](#-sincronización-con-minedu)
8. [Pruebas](#-pruebas)
9. [Estructura del proyecto](#-estructura-del-proyecto)
10. [Decisiones de migración](#-decisiones-de-migración)
11. [Flujo de Git y entrega](#-flujo-de-git-y-entrega)

---

## 🔄 Qué cambió respecto del intento anterior

| Aspecto | Intento anterior (Laravel 10) | Estado actual (Laravel 13) |
|---|---|---|
| Framework | Laravel 10.50.4 (EOL) | **Laravel 13.42.0** |
| Plataforma | PHP 8.2/8.3 | **PHP 8.3** (`composer.json`) |
| Esqueleto | `Http/Console Kernel`, `RouteServiceProvider`, 5 providers, 9 middleware propios | **`bootstrap/app.php`**, `bootstrap/providers.php`, framework kernels, sin middleware propio |
| Entrypoint | `index.php` en la raíz del proyecto | **Solo `public/index.php`**; el docroot debe ser `public/` |
| `.env` | Quedaba dentro del webroot si se apuntaba a la raíz | **Fuera del webroot** por diseño |
| Ruta `/api/user` | Presente, sin uso, con Sanctum | **Eliminada**; Sanctum ya no es dependencia |
| Límite de peticiones | 60/min en `RouteServiceProvider` | **60/min** en `AppServiceProvider` + `throttleApi('api')` |
| Colegios por distrito | Solo los primeros 50 | **Paginación completa** con `start` + deduplicación por `codMod` |
| Cortes del MINEDU | Detenían toda la sincronización | **3 reintentos** y continuación de las demás ramas; exit code 1 si hubo fallos |
| Migraciones | `class_exists()` y guardas `Schema::hasTable()` | **Migraciones anónimas** estándar |
| Frontend | Laravel Mix 5 (`webpack.mix.js`) para una API sin frontend | **API-only**; se eliminó Mix y los assets no usados |
| Tests | 8 tests | **16 tests / 109 aserciones** |
| Auditoría | Advisories pendientes | **Sin vulnerabilidades** (`composer audit`) |

El detalle de las decisiones está en [`AGENT.md`](./AGENT.md) y el plan previo a la ejecución en [`PLAN_MIGRACION.md`](./PLAN_MIGRACION.md).

---

## 🏗️ Arquitectura

```
[ API pública MINEDU / ESCALE ]
  https://escale.minedu.gob.pe/padron/rest
                │
                │  php artisan sync:locations
                │  (paginación start=0,50,100... + retry 3)
                ▼
[ Base de datos local: MySQL / MariaDB / SQLite ]
  regions ── provinces ── districts ── schools
                │
                ▼
[ API REST Laravel 13 / PHP 8.3 ]
  GET /api/regiones
  GET /api/provincias/{idRegion}
  GET /api/distritos/{idProvince}
  GET /api/colegios/{ubigeo}
                │
                ▼
[ Clientes: admisión, matrícula, intranets, frontends ]
```

Patrón: **Bridge / Cache Proxy**. El servicio desacopla a los clientes de la latencia y disponibilidad del MINEDU, y conserva el contrato REST histórico (`status` + `data` / `message`).

---

## 💻 Requisitos

- **PHP 8.3.x** con `pdo`, `pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl`, `curl`, `zip`.
- **Composer 2.x**.
- **MySQL 5.7+ / 8.0+ / MariaDB 10.3+** (o SQLite para pruebas).
- Servidor web con **document root en `public/`** (Laragon, Apache, Nginx, Docker).
- No se requiere Node.js: el proyecto es API-only.

Comandos de referencia usados en la verificación:

```bash
php -v          # PHP 8.3.16
composer -V     # Composer 2.8.4
```

---

## 🚀 Instalación

```bash
git clone https://github.com/Joshua-A-hub/escale-bridge-api.git
cd escale-bridge-api

composer install
cp .env.example .env
php artisan key:generate
```

Configura la base de datos en `.env` y ejecuta:

```bash
php artisan migrate
php artisan sync:locations
php artisan serve
```

La API queda en `http://127.0.0.1:8000`. La raíz `/` devuelve metadatos del servicio y `/up` es el health check.

### ⚠️ Document root obligatorio: `public/`

El proyecto **no** tiene `index.php` en la raíz. Si usas Apache/Laragon, apunta el Virtual Host a:

```text
C:\ruta\al\proyecto\public
```

Si usas Nginx:

```nginx
root /ruta/al/proyecto/public;
index index.php;
```

Si usas el servidor embebido, `php artisan serve` ya usa `public/index.php`.

> Esto es lo que protege `.env`, `vendor/`, `storage/` y el código fuente. **No** publiques el proyecto apuntando el docroot a la raíz.

---

## ⚙️ Variables de entorno

| Variable | Valor de ejemplo | Uso |
|---|---|---|
| `APP_NAME` | `ESCALE Bridge API` | Nombre del servicio |
| `APP_ENV` | `local` / `production` | Entorno |
| `APP_KEY` | `base64:...` | Generada con `php artisan key:generate` |
| `APP_DEBUG` | `true` en local, `false` en producción | Depuración |
| `APP_URL` | `http://localhost:8000` | URL base |
| `APP_TIMEZONE` | `America/Lima` | Zona horaria del servicio |
| `DB_CONNECTION` | `mysql` | Motor de base de datos |
| `DB_DATABASE` | `escalebridge` | Nombre de la base |
| `CACHE_STORE` | `file` | Cache (no requiere tabla `cache`) |
| `QUEUE_CONNECTION` | `sync` | Colas |
| `SESSION_DRIVER` | `file` | Sesiones |

En pruebas se usa SQLite en memoria, cache `array` y sesiones `array` (ver `phpunit.xml`).

---

## 📡 Endpoints de la API

Todos los endpoints son públicos, usan el prefijo `/api` y responden JSON. El límite es **60 peticiones por minuto por usuario/IP**; al superarlo se devuelve `429` con `Retry-After`.

### 1. Regiones

```http
GET /api/regiones
```

```json
{
  "status": "success",
  "data": [
    {
      "idRegion": "15",
      "nombreRegion": "LIMA",
      "pointX": -12.046374,
      "pointY": -77.042793,
      "zoom": 10
    }
  ]
}
```

### 2. Provincias por región

```http
GET /api/provincias/15
```

### 3. Distritos por provincia

```http
GET /api/distritos/1501
```

### 4. Colegios por ubigeo

```http
GET /api/colegios/150101
```

### Errores

```json
{
  "status": "error",
  "message": "No se encontraron provincias para esta región."
}
```

Códigos usados: `200` correcto, `404` sin datos o ruta inexistente, `429` límite excedido, `500` error interno.

---

## 🔁 Sincronización con MINEDU

```bash
php artisan sync:locations
```

El comando recorre la cascada **región → provincia → distrito → colegios** y hace `updateOrCreate` por `codMod` (colegios) y por sus claves naturales en las demás tablas.

### Correcciones implementadas

1. **Paginación real.** La API devuelve como máximo 50 colegios por página y acepta `start` como offset. El comando pide `start=0, 50, 100...` hasta agotar el distrito.
   - Verificación en vivo con el distrito `150101` (Lima): **540 colegios únicos** en 11 páginas (549 filas con 9 duplicados por variación de orden).
2. **Deduplicación.** Se descartan `codMod` repetidos entre páginas para no duplicar filas ni conteos.
3. **Reintentos.** Cada petición usa `retry(3, 500)` con timeout de 30 s y conexión de 10 s.
4. **Continuidad.** Si una región/provincia/distrito falla, se registra el error y se sigue con las demás ramas. Al final:
   - exit code `0` si todo salió bien;
   - exit code `1` si hubo fallos, conservando lo ya sincronizado.
5. **Resumen final.** Imprime cuántas regiones, provincias, distritos y colegios se procesaron.

### Salida esperada

```text
Iniciando sincronización de ubicaciones desde MINEDU/ESCALE...
Sincronizando regiones...
...
Sincronización finalizada: 25 regiones, 196 provincias, 1874 distritos y 104321 colegios.
```

> El tiempo depende de la respuesta del MINEDU. Si el servicio estatal corta la conexión, el comando reintenta y continúa; no pierde lo ya grabado.

---

## 🧪 Pruebas

```bash
php artisan test
# o directamente:
vendor/bin/phpunit
```

Resultado verificado:

```text
PHPUnit 12.5.44 by Sebastian Bergmann and contributors.
Runtime: PHP 8.3.16

................                                                  16 / 16 (100%)

OK (16 tests, 109 assertions)
```

Cobertura funcional:

- 4 endpoints con datos y 3 casos `404`.
- Sincronización completa con `Http::fake`.
- **Paginación de 120 colegios** en páginas de 50/50/20.
- **Deduplicación** cuando la API repite filas entre páginas.
- **Reintento** ante dos fallos 500 consecutivos.
- **Continuidad** cuando una región falla y otra sí sincroniza (exit code 1).
- **Rate limit**: 60 peticiones OK y la 61 devuelve `429`.
- Raíz JSON, health check `/up` y ausencia de `/api/user`.

También se verificó:

```bash
composer validate --strict   # ./composer.json is valid
composer audit               # No security vulnerability advisories found.
vendor/bin/pint --test       # PASS (46 files)
```

---

## 📂 Estructura del proyecto

```text
app/
  Console/Commands/SyncLocations.php   # Sync con paginación y reintentos
  Http/Controllers/LocationController.php
  Models/{Region,Province,District,School,User}.php
  Providers/AppServiceProvider.php     # Rate limiter 60/min
bootstrap/
  app.php                              # Configuración Laravel 13
  providers.php                        # Providers registrados
config/                                # Configuración del framework
database/
  migrations/                          # Migraciones anónimas
  factories/ seeders/
public/
  index.php                            # ÚNICO entrypoint web
  .htaccess
routes/
  api.php  web.php  console.php
tests/Feature/
  LocationApiTest.php
  ApiHardeningTest.php
  SyncLocationsTest.php
PLAN_MIGRACION.md
AGENT.md
```

No existen `app/Http/Kernel.php`, `app/Console/Kernel.php`, `RouteServiceProvider`, `webpack.mix.js` ni `index.php` en la raíz.

---

## 🏛️ Decisiones de migración

Las decisiones completas (ADR-001 a ADR-009), la bitácora y la evidencia están en [`AGENT.md`](./AGENT.md). Resumen:

- **Laravel 13.42** en PHP 8.3: versión vigente, con soporte, y resuelve correctamente en 8.3.16.
- **Esqueleto nuevo**: `bootstrap/app.php` + `bootstrap/providers.php`; los kernels del framework reemplazan a los de la app.
- **Docroot `public/`**: elimina la exposición de `.env`.
- **Rate limit 60/min** conservado y probado.
- **API-only**: se elimina el frontend de ejemplo y Laravel Mix; no hay Node.js.
- **Sync resiliente**: paginación `start`, deduplicación, reintentos y continuación.

---

## 🌿 Flujo de Git y entrega

- `upstream` → `hubelsolis/brigesnote` (repositorio de la cátedra).
- `origin` → `Joshua-A-hub/escale-bridge-api` (repositorio propio).
- `main` → línea base + migración integrada.
- Rama de trabajo: `feature/migracion-laravel13-php8.3`.
- Tag de entrega: `v2.0.0-laravel13`.

Historial de la migración:

```bash
git log --oneline --decorate
```

La estrategia cumple lo pedido por el profesor: **plan de trabajo antes de ejecutar el agente** (`PLAN_MIGRACION.md`), **baseline commiteado antes de inicializar** (`24261a4`) y **push** del resultado.

---

## 📄 Licencia

MIT.
