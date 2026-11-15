# 🤖 AGENT.md - Architectural Record & Agent Runbook

Este documento actúa como registro de decisiones de arquitectura (ADR), bitácora de ejecución y manual operativo para cualquier Agente de Inteligencia Artificial o Desarrollador Senior que interactúe con este repositorio.

---

## 1. 📌 Contexto y Objetivo del Proyecto

* **Nombre del Repositorio:** `escale-bridge-api` (Original: `hubelsolis/brigesnote`)
* **Repositorio Remoto Oficial:** [https://github.com/Joshua-A-hub/escale-bridge-api](https://github.com/Joshua-A-hub/escale-bridge-api)
* **Repositorio Upstream (Cátedra):** [https://github.com/hubelsolis/brigesnote](https://github.com/hubelsolis/brigesnote)
* **Objetivo de la Tarea:** Migración arquitectónica integral desde una pila tecnológica obsoleta (**PHP 7.4 / Laravel 7**) hacia **PHP 8.3.x**, manteniendo intacto el contrato de API REST y la sincronización con el servicio estatal del **MINEDU / ESCALE**.

---

## 2. 🏛️ Arquitectura del Sistema

El sistema implementa un patrón **Bridge / Cache Proxy**:

```
[ Fuente Externa: API MINEDU / ESCALE ] (escale.minedu.gob.pe)
                    │
                    ▼  (Comando Artisan: sync:locations)
[ Base de Datos Relacional: MySQL 8 / MariaDB / SQLite ] (Base de datos: escalebridge)
  ├── regions
  ├── provinces
  ├── districts
  └── schools
                    │
                    ▼  (LocationController)
[ Microservicio REST Local ] (PHP 8.3 / Laravel 10)
  ├── GET /api/regiones
  ├── GET /api/provincias/{idRegion}
  ├── GET /api/distritos/{idProvince}
  └── GET /api/colegios/{ubigeo}
                    │
                    ▼
[ Clientes Consumidores ] (Frontend / Admisión / Intranets)
```

### Componentes Críticos del Dominio:
1. **Comando de Sincronización:** `app/Console/Commands/SyncLocations.php` (Refactorizado con tipado estricto `declare(strict_types=1);` y control resiliente de payload JSON).
2. **Controlador REST:** `app/Http/Controllers/LocationController.php` (Consultas SQL ANSI portables `SUBSTR()` compatibles con MySQL y SQLite).
3. **Modelos de Dominio:**
   * `app/Models/Region.php`
   * `app/Models/Province.php`
   * `app/Models/District.php`
   * `app/Models/School.php`
   * `app/Models/User.php`
4. **Definición de Rutas:** `routes/api.php`
5. **Migraciones:** `database/migrations/*`
6. **Tests Automatizados:** `tests/Feature/LocationApiTest.php` (Cobertura 100% de endpoints REST).

---

## 3. ⚖️ Decisiones de Arquitectura (ADR)

### ADR-001: Estrategia de Control de Versiones (Upstream vs Origin)
* **Decisión:** Conservar el repositorio de la cátedra como remoto `upstream` (`hubelsolis/brigesnote`) y configurar el repositorio del alumno como `origin` (`Joshua-A-hub/escale-bridge-api`).
* **Razón:** Permite trazabilidad completa, cumplimiento de buenas prácticas de Git (Fork & Branch Workflow) y asegura que la rama `main` contenga el baseline original antes de iniciar la migración.
* **Estrategia de Ramas:**
  * `main`: Línea base (PHP 7.4).
  * `feature/migracion-php8.3`: Rama activa de refactorización y migración.

### ADR-002: Actualización de Framework a Laravel 10 para Soporte Nativo PHP 8.3
* **Problema:** Laravel 7.x llegó al fin de su ciclo de vida (EOL) en 2021. Al intentar ejecutarse en PHP 8.1+, el motor arroja errores fatales por incompatibilidades en `ArrayAccess`, `Countable` y tipado estricto interno de Symfony v5.
* **Decisión:** Elevar la versión del framework a **Laravel 10.50.x** (LTS con soporte completo para PHP 8.3.x) preservando al 100% el esquema de base de datos, controladores, comandos y rutas existentes.
* **Razón:** Parchear Laravel 7 es un antipatrón (*monkey-patching*) que no cumple con estándares de ingeniería de software senior ni con entornos de producción.

### ADR-003: Compatibilidad Multi-Motor de Base de Datos en Consultas de Ubigeo
* **Problema:** `LocationController` utilizaba `LEFT(idProvincia, 2)`, función propietaria de MySQL no soportada en SQLite, impidiendo la ejecución de pruebas automatizadas in-memory.
* **Decisión:** Reemplazar `LEFT()` por la función ANSI SQL estándar `SUBSTR(campo, 1, n) = ?`.
* **Resultado:** Ejecución idéntica y de alto rendimiento tanto en MySQL en producción como en SQLite en suite de pruebas automáticas.

---

## 4. 📋 Bitácora de Fases y Estado

| Fase | Descripción | Estado | Commits / Notas |
|---|---|---|---|
| **Fase 0** | Aislamiento de Git y Setup Local | ✅ Completado | Directorio `brigesnote` aislado de `~` |
| **Fase 1** | Baseline Commit en Remoto | ✅ Completado | `main` sincronizado con GitHub del alumno |
| **Fase 2** | Creación de rama de trabajo | ✅ Completado | `feature/migracion-php8.3` activa y vinculada |
| **Fase 3** | Upgrade de dependencias y `composer.json` a PHP 8.3 | ✅ Completado | Laravel 10.50.4 + PHP 8.3.16 + PHPUnit 10 |
| **Fase 4** | Refactorización de código PHP 8.3 | ✅ Completado | Tipado estricto, safe nulls, ANSI SQL |
| **Fase 5** | Pruebas funcionales de migración y endpoints | ✅ Completado | 7 tests, 12 aserciones pasando al 100% |
| **Fase 6** | Commits semánticos y entrega | 🔄 En Progreso | Preparación de commit y git push |

---

## 5. 🛠️ Entorno de Ejecución Local Detectado

* **PHP Bin:** `C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe` (PHP 8.3.16 CLI ZTS x64)
* **Composer Bin:** `C:\laragon\bin\composer\composer.phar` (Composer 2.8.4)
* **Base de datos:** MySQL / MariaDB (Database: `escalebridge`, User: `root`, Password: ``) / SQLite in-memory para tests.
