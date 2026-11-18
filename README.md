# Sistema de Legalizaciones con Firma Digital — UAGRM

Proyecto académico **INF513 (Tecnología Web)** para el Vicerrectorado de la Universidad Autónoma Gabriel René Moreno.
Gestiona la **legalización de documentos académicos** (destino nacional o exterior con apostilla) y el
**descuento del 50 % en diplomados**, desde la solicitud del estudiante hasta el certificado oficial con QR,
con **firma digital PAdES**, delegación de autoridad, bitácora inalterable y control del plazo de **72 horas hábiles**.

> ⚠️ La firma digital usa una **Autoridad Certificadora de prueba** que ocupa el lugar de ADSIB.
> Los documentos generados **no tienen validez legal**.

---

## Arquitectura (3 capas)

```
┌───────────────────────────────────────────────────────────────┐
│ CAPA 1 · PRESENTACIÓN          frontend/  (React 19 + Vite)    │
│   pages/ → components/ → api/*.js (único punto que usa Axios)  │
└──────────────────────────────┬────────────────────────────────┘
                               │ JSON / HTTP (API REST)
┌──────────────────────────────▼────────────────────────────────┐
│ CAPA 2 · LÓGICA DE NEGOCIO     backend/   (Laravel 13)         │
│   Middleware   auth:sanctum · rol:… · usuario de auditoría     │
│   Controllers  solo HTTP: validan, llaman al servicio, responden│
│   Services     reglas de negocio y transacciones               │
│   Repositories único lugar con SQL, archivos y transacciones   │
│        └──► firmador/ (Java 17 · Spring Boot) firma PAdES, AC  │
│             de prueba, legajos y certificados PDF/A con QR     │
└──────────────────────────────┬────────────────────────────────┘
                               │ SQL transaccional
┌──────────────────────────────▼────────────────────────────────┐
│ CAPA 3 · PERSISTENCIA          PostgreSQL ≥ 13                 │
│   tablas, ENUM, triggers (máquina de estados, bitácora con     │
│   cadena de hash), funciones, vistas y contadores              │
└───────────────────────────────────────────────────────────────┘
```

| Carpeta | Contenido | Documentación |
|---|---|---|
| [`backend/`](backend/) | API REST Laravel + esquema SQL | [backend/README.md](backend/README.md) |
| [`frontend/`](frontend/) | SPA React | [frontend/README.md](frontend/README.md) |
| [`firmador/`](firmador/) | Microservicio Java de firma digital | [firmador/README.md](firmador/README.md) |
| [`docs/`](docs/) | Guía para probar todas las funcionalidades | [docs/GUIA_DE_PRUEBAS.md](docs/GUIA_DE_PRUEBAS.md) |

---

## Requisitos

| Herramienta | Versión |
|---|---|
| PHP | 8.3 o superior, con las extensiones `pdo_pgsql`, `openssl` y `curl` |
| Composer | 2.x |
| Node.js / npm | 20 o superior |
| PostgreSQL | 13 o superior |
| Java (JDK) | 17 o superior. Maven **no** hace falta: se incluye `mvnw` |

---

## Instalación (primera vez)

### 1. Base de datos
Crea una base **vacía** en PostgreSQL:
```sql
CREATE DATABASE db_legalizaciones ENCODING 'UTF8';
ALTER DATABASE db_legalizaciones SET timezone TO 'America/La_Paz';
```

### 2. Backend
```bash
cd backend
composer install
cp .env.example .env          # en PowerShell: copy .env.example .env
#  → edita .env: DB_USERNAME y DB_PASSWORD de tu PostgreSQL
php artisan key:generate
php artisan migrate --seed    # crea todo el esquema y los usuarios de prueba
```
La migración ejecuta [`database/sql/legalizaciones.sql`](backend/database/sql/legalizaciones.sql), que contiene el esquema completo con sus catálogos, y el seeder crea un usuario por rol.

### 3. Frontend
```bash
cd frontend
npm install
```

### 4. Firmador
No requiere configuración: la primera vez que arranca crea la AC de prueba en `firmador/datos/`.

---

## Ejecución

Abre **3 terminales** e inicia primero el firmador, porque Laravel lo usa para compilar, firmar y emitir.

```bash
# 1) Firmador · http://127.0.0.1:8081
cd firmador
./mvnw spring-boot:run        # Windows: .\mvnw.cmd spring-boot:run

# 2) Backend · http://127.0.0.1:8000
cd backend
php artisan serve

# 3) Frontend · http://localhost:5173
cd frontend
npm run dev
```

Opcional: para la verificación periódica de las alertas SLA (cada 15 min), ejecuta además `php artisan schedule:work` en `backend/`. Sin ella, las alertas se generan igual al abrir el monitor SLA.

Abre **http://localhost:5173**.

---

## Usuarios de prueba

| Rol | Correo | Contraseña |
|---|---|---|
| Administrador | `admin@uagrm.edu.bo` | `Admin123*` |
| Recepción | `recepcion@uagrm.edu.bo` | `Recepcion123*` |
| Vicerrectora (titular) | `vicerrector@uagrm.edu.bo` | `Vice123*` |
| Rectorado | `rectorado@uagrm.edu.bo` | `Rectorado123*` |
| Decano suplente | `suplente@uagrm.edu.bo` | `Suplente123*` |
| Estudiante | `estudiante@uagrm.edu.bo` | `Estudiante123*` |

El estudiante tiene **Registro 219012345** y **C.I. 7654321**; con esos datos se usa el portal público de seguimiento.

Para recorrer todo el sistema paso a paso, consulta la **[Guía de pruebas](docs/GUIA_DE_PRUEBAS.md)**.

---

## Casos de uso

| CU | Funcionalidad | Estado |
|---|---|---|
| CU1 | Gestión de usuarios y perfil | ✅ |
| CU2 | Delegación de autoridad, emisión de certificados de firma e indicadores | ✅ |
| CU3 | Trámites, selección de documentos, requisitos y expediente | ✅ |
| CU4 | Flujo, guía procesal, observaciones, subsanación y bitácora inalterable | ✅ |
| CU5 | Bandeja de despacho, visor de legajos, firma PAdES individual, por lote o remota, y visado de Rectorado | ✅ |
| CU6 | Certificado oficial PDF/A con QR, portal de seguimiento y validador público | ✅ |
| CU7 | Monitoreo SLA de 72 h hábiles, alertas, desglose por fase y parametrización | ✅ |
| CU8 | Reportes y estadísticas | ⏳ pendiente (las vistas `v_rep_*` ya existen en la BD) |

Páginas públicas, sin iniciar sesión: **`/seguimiento`** (portal del estudiante) y **`/validar-certificado`** (destino del QR).

---

## Reinstalar desde cero

```bash
cd backend
php artisan migrate:fresh --seed
```
⚠️ Esto **borra todos los datos**. Los archivos subidos quedan en `backend/storage/app/public/`; puedes borrarlos a mano.

Si tu base ya tenía el esquema cargado con `psql`, `php artisan migrate` lo detecta y **no lo modifica**.

---

## Solución de problemas

| Síntoma | Causa y solución |
|---|---|
| *"El microservicio de firma digital no está disponible"* | El firmador no está corriendo. Inícialo con `mvnw spring-boot:run`. |
| *"Clave de acceso al microservicio de firma inválida"* | `FIRMADOR_API_KEY` en `backend/.env` debe coincidir con `firmador.api-key`. |
| Las horas aparecen desplazadas | Revisa que `APP_TIMEZONE=America/La_Paz` esté en `.env` y que la base use la misma zona horaria. |
| *"Demasiadas consultas"* en el portal | Es el límite de peticiones de las páginas públicas: espera un minuto. |
| Adobe Reader muestra la firma como "desconocida" | Descarga el certificado raíz desde la bandeja de despacho y márcalo como confiable en Adobe (*Preferencias → Firmas → Identidades y certificados de confianza*). |
