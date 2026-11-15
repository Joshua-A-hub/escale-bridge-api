# 🏫 Bridges Note - MINEDU ESCALE Bridge API (PHP 8.3)

Microservicio y API REST modernizado y migrado a **PHP 8.3 / Laravel 10** diseñado para sincronizar, estructurar y servir el catálogo oficial de instituciones educativas (colegios) y la división política/geográfica (regiones, provincias y distritos) del Perú, consumiendo directamente la API pública oficial de **MINEDU / ESCALE** (*Estadística de la Calidad Educativa*).

---

## 📋 Tabla de Contenidos

1. [Propósito del Proyecto](#-propósito-del-proyecto)
2. [Arquitectura y Flujo de Datos](#-arquitectura-y-flujo-de-datos)
3. [Requisitos del Sistema (Migrado a PHP 8.3)](#-requisitos-del-sistema-migrado-a-php-83)
4. [Instalación y Puesta en Marcha](#-instalación-y-puesta-en-marcha)
5. [Configuración de Entorno (.env)](#-configuración-de-entorno-env)
6. [Sincronización de Datos (Consola Artisan)](#-sincronización-de-datos-consola-artisan)
7. [Documentación de la API REST](#-documentación-de-la-api-rest)
8. [Ejecución de Pruebas Automatizadas](#-ejecución-de-pruebas-automatizadas)
9. [Estructura del Proyecto y Decisiones de Migración](#-estructura-del-proyecto-y-decisiones-de-migración)

---

## 🎯 Propósito del Proyecto

Servir como un **puente de datos optimizado** para aplicaciones cliente (sistemas de admisión universitaria, plataformas de matrícula escolar, intranets, etc.), permitiéndoles autocompletar ubigeos y consultar colegios mediante consultas locales ultrarrápidas, sin depender de la latencia o disponibilidad de los servidores estatales.

---

## 🔄 Arquitectura y Flujo de Datos

```
[ API MINEDU / ESCALE ] 
 (escale.minedu.gob.pe)
          │
          ▼  (Comando: php artisan sync:locations)
[ Base de Datos Local MySQL / MariaDB (escalebridge) ]
  ├── regions
  ├── provinces
  ├── districts
  └── schools (con ubigeo / idDistrito)
          │
          ▼  (LocationController con tipado estricto PHP 8.3)
[ Endpoints API REST Local / Producción ]
  ├── GET /api/regiones
  ├── GET /api/provincias/{idRegion}
  ├── GET /api/distritos/{idProvince}
  └── GET /api/colegios/{ubigeo}
          │
          ▼
[ Clientes / Aplicaciones Externas ] (ej. admisionweb)
```

---

## 💻 Requisitos del Sistema (Migrado a PHP 8.3)

- **PHP:** `^8.3.x` (Totalmente compatible y optimizado para PHP 8.3).
  > ✅ **Migración Completada:** Se superó la restricción de incompatibilidad de `ArrayAccess` de PHP 8.1+ al elevar las dependencias base a **Laravel 10**, implementando tipado estricto (`declare(strict_types=1);`), consultas SQL portables ANSI y manejo seguro de respuestas nulas.
- **Laravel Framework:** `10.50.x`
- **Gestor de Paquetes:** Composer 2.x
- **Extensiones PHP requeridas:** `pdo`, `pdo_mysql`, `pdo_sqlite` (para tests), `curl`, `mbstring`, `zip`.
- **Base de Datos:** MySQL 5.7+ / 8.0+ o MariaDB 10.3+
- **Servidor Web Local:** Laragon (con PHP 8.3 TS), Docker o `php artisan serve`.

---

## 🚀 Instalación y Puesta en Marcha

### 1. Clonar el repositorio
```bash
git clone https://github.com/Joshua-A-hub/escale-bridge-api.git
cd escale-bridge-api
```

### 2. Instalar dependencias de Composer (bajo PHP 8.3)
```bash
composer install
```

### 3. Configurar variables de entorno
Copia el archivo de ejemplo y genera la clave criptográfica:
```bash
cp .env.example .env
php artisan key:generate
```

### 4. Crear la Base de Datos
Crea una base de datos vacía en tu gestor MySQL (ej. HeidiSQL o phpMyAdmin):
- **Nombre:** `escalebridge`
- **Cotejamiento:** `utf8mb4_unicode_ci`

### 5. Ejecutar las Migraciones
Crea las tablas en la base de datos:
```bash
php artisan migrate
```

### 6. Sincronizar el catálogo escolar
Descarga la información oficial desde MINEDU:
```bash
php artisan sync:locations
```

### 7. Iniciar el servidor
```bash
php artisan serve
```
La aplicación quedará disponible en `http://127.0.0.1:8000`.

---

## 🧪 Ejecución de Pruebas Automatizadas

El proyecto incluye una suite completa de pruebas unitarias y de integración para validar los endpoints de la API bajo PHP 8.3 utilizando SQLite en memoria:

```bash
vendor/bin/phpunit
```

Resultado esperado:
```text
PHPUnit 10.5.67 by Sebastian Bergmann and contributors.
Runtime: PHP 8.3.16
OK (7 tests, 12 assertions)
```

---

## 📡 Documentación de la API REST

Todos los endpoints retornan respuestas en formato JSON bajo el prefijo `/api/`.

### 1. Listar Regiones / Departamentos
- **URL:** `/api/regiones`
- **Método:** `GET`
- **Respuesta Exitosa (200 OK):**
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

---

### 2. Listar Provincias por Región
- **URL:** `/api/provincias/{idRegion}`
- **Método:** `GET`
- **Ejemplo:** `/api/provincias/15` (Provincias de Lima)
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "idProvincia": "1501",
      "nombreProvincia": "LIMA",
      "pointX": -12.046374,
      "pointY": -77.042793
    }
  ]
}
```

---

### 3. Listar Distritos por Provincia
- **URL:** `/api/distritos/{idProvince}`
- **Método:** `GET`
- **Ejemplo:** `/api/distritos/1501` (Distritos de la provincia de Lima)
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "idDistrito": "150101",
      "nombreDistrito": "LIMA"
    }
  ]
}
```

---

### 4. Listar Colegios por Distrito (Ubigeo)
- **URL:** `/api/colegios/{ubigeo}`
- **Método:** `GET`
- **Ejemplo:** `/api/colegios/150101`
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "codMod": "0273572",
      "idDistrito": "150101",
      "nombreCenEdu": "JUAN JIMENEZ PIMENTEL",
      "cenPob": "LIMA",
      "dirCen": "JR. LAMPA 240",
      "director": "PEDRO PEREZ"
    }
  ]
}
```

---

## 📂 Estructura del Proyecto y Decisiones de Migración

Consulta el archivo [`AGENT.md`](./AGENT.md) para revisar los *Architectural Decision Records (ADRs)*, la bitácora de migración y la estrategia de versionamiento (`upstream` vs `origin`).

---

## 📄 Licencia

Este proyecto es de código abierto bajo la licencia [MIT](LICENSE).
