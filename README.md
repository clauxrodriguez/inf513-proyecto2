# Sistema de Gestión Documental, Legalizaciones y Firma Digital — UAGRM (INF-513 · Proyecto 2)

Sistema en 3 capas desacopladas para la gestión de trámites de legalización, descuentos de diplomado, cadena de firmas digitales PAdES y emisión de certificados oficiales PDF/A-2b con código QR del Vicerrectorado de la UAGRM.

## Estructura del repositorio

| Carpeta | Tecnología | Función | Puerto por defecto |
|---|---|---|---|
| [`firmador/`](./firmador) | Java 17 · Spring Boot 3.4 · PDFBox 3 · Bouncy Castle | Microservicio interno de Autoridad Certificadora (AC), compilación de legajos foliados, firma digital PAdES y generación de certificados PDF/A-2b con QR | `http://127.0.0.1:8081` |
| [`backend/`](./backend) | PHP 8.3+ · Laravel 13 · Sanctum · PostgreSQL 13+ | API REST, lógica de negocio, auditoría transaccional, integración con Stripe, Jacobitus y AGETIC | `http://127.0.0.1:8000` |
| [`frontend/`](./frontend) | React 19 · Vite 8 · Tailwind CSS 4 | Aplicación web (SPA) con menú dinámico por rol, visor de PDFs, firma local con Jacobitus y portal público | `http://localhost:5173` |
| [`docs/`](./docs) | Markdown | [Guía de pruebas paso a paso](./docs/GUIA_DE_PRUEBAS.md) | — |

---

## Requisitos previos

- **PostgreSQL** `>= 13` (con una base de datos vacía creada, por defecto `db_legalizaciones`)
- **Java JDK** `17` o superior (incluye Maven Wrapper `./mvnw`, no requiere instalar Maven global)
- **PHP** `>= 8.3` con extensiones `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `curl`, `fileinfo` y **Composer** `2.x`
- **Node.js** `>= 20` y **npm**

---

## Instalación paso a paso

### 1. Base de datos (PostgreSQL)

Crea la base de datos vacía en PostgreSQL antes de correr las migraciones:

```sql
CREATE DATABASE db_legalizaciones;
```

### 2. Instalar el Firmador (`firmador/`)

El microservicio incluye **Maven Wrapper** (`mvnw` / `mvnw.cmd`), por lo que descarga sus dependencias y compila con:

```bash
cd firmador

# Windows (PowerShell / CMD)
.\mvnw.cmd clean package -DskipTests

# Linux / macOS / Git Bash
./mvnw clean package -DskipTests
```

### 3. Instalar el Backend (`backend/`)

```bash
cd backend

# 1. Instalar dependencias de PHP
composer install

# 2. Crear archivo de entorno (si no existe)
# En Windows PowerShell: Copy-Item .env.example .env
cp .env.example .env

# 3. Generar la clave de la aplicación
php artisan key:generate
```

Edita `backend/.env` con tus credenciales de PostgreSQL (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) y ejecuta las migraciones con los datos iniciales (roles, permisos, menús, catálogos y usuarios de prueba):

```bash
# Crear tablas, funciones PL/pgSQL, triggers, vistas y cargar datos semilla
php artisan migrate --seed
```

> **Nota:** Si deseas reiniciar toda la base de datos desde cero en cualquier momento, ejecuta:
> ```bash
> php artisan migrate:fresh --seed
> ```

### 4. Instalar el Frontend (`frontend/`)

```bash
cd frontend

# 1. Instalar dependencias de Node.js
npm install

# 2. (Opcional) Copiar variables de entorno si necesitas cambiar URLs o configurar Stripe
# En Windows PowerShell: Copy-Item .env.example .env.local
cp .env.example .env.local
```

---

## Ejecución

Para levantar el sistema completo abre **3 terminales** (una por cada servicio):

### Terminal 1 — Firmador (puerto `8081`)

```bash
cd firmador

# Windows (PowerShell / CMD)
.\mvnw.cmd spring-boot:run

# Linux / macOS / Git Bash
./mvnw spring-boot:run

# Alternativa si ya compilaste el .jar con package:
java -jar target/firmador.jar
```

Puedes verificar que está activo abriendo `http://127.0.0.1:8081/api/salud`.

### Terminal 2 — Backend API (puerto `8000`)

```bash
cd backend
php artisan serve
```

 La API quedará disponible en `http://127.0.0.1:8000/api`.

*(Opcional)* Con el **firmador** y el **backend** corriendo, puedes emitir de una sola vez los certificados digitales de prueba para todas las autoridades firmantes con:

```bash
cd backend
php artisan certificados:prueba --pin=123456
```

### Terminal 3 — Frontend SPA (puerto `5173`)

```bash
cd frontend
npm run dev
```

Abre en tu navegador: **`http://localhost:5173`**

---

## Usuarios de prueba (cargados con `--seed`)

| Rol | Correo | Contraseña | Detalle |
|---|---|---|---|
| **Administrador** | `admin@uagrm.edu.bo` | `Admin123*` | Gestión de usuarios, SLA y auditoría de bitácora |
| **Director de Carrera** | `carrera.sistemas@uagrm.edu.bo` | `Carrera123*` | Emisión y 1.ª firma en origen (Ing. de Sistemas) |
| **Decano FICCT** | `decano.ficct@uagrm.edu.bo` | `Decano123*` | Firma en origen (FICCT) |
| **Decano Derecho (Suplente)** | `decano.derecho@uagrm.edu.bo` | `DecanoDer123*` | Decano con prelación 1 (suplente del Vicerrector) |
| **Admisiones** | `admisiones@uagrm.edu.bo` | `Admisiones123*` | Emisión y firma en origen (Registros e Inscripciones) |
| **DAGA** | `daga@uagrm.edu.bo` | `Daga123*` | Emisión y firma en origen (DAGA) |
| **Recepción / Ventanilla** | `recepcion@uagrm.edu.bo` | `Recepcion123*` | Revisión, comprobantes, compilación y emisión QR |
| **Vicerrectora (Titular)** | `vicerrector@uagrm.edu.bo` | `Vice123*` | Despacho y firma digital consolidada o remota |
| **Rectorado** | `rectorado@uagrm.edu.bo` | `Rectorado123*` | Delegaciones de autoridad y visado al exterior |
| **Estudiante** | `estudiante@uagrm.edu.bo` | `Estudiante123*` | Registro `219012345` · C.I. `7654321` |
