# Backend — API REST (Laravel 13)

Capa de **lógica de negocio** del Sistema de Legalizaciones. Expone una API REST bajo `/api`, autenticada con **Laravel Sanctum** mediante tokens Bearer. Delega la criptografía al microservicio [`../firmador`](../firmador) y persiste en **PostgreSQL**.

La instalación y la ejecución del proyecto completo están en el [README principal](../README.md).

## Organización por capas

```
app/
├── Http/
│   ├── Controllers/Api/   Solo HTTP: validan la entrada, llaman al servicio y responden JSON
│   └── Middleware/        VerificarRol (rol:…) · SetPostgresAuditUser (usuario de la bitácora)
├── Services/              Reglas de negocio: no usan Request ni SQL; lanzan ReglaNegocioException
├── Repositories/          ÚNICO lugar con DB:: y Storage:: (consultas, archivos, UnidadDeTrabajo)
├── Exceptions/            ReglaNegocioException → {"message": …} con su código HTTP
├── Console/Commands/      sla:verificar (programado cada 15 min)
└── Models/User.php        Usuario autenticable (tabla usuario)
```

Reglas que se mantienen en todo el código:
- **Controladores:** no contienen SQL ni reglas de negocio.
- **Servicios:** no usan `abort()`, `response()` ni la fachada `DB`.
- **Roles:** se declaran en [`routes/api.php`](routes/api.php) con el middleware `rol:ROL1,ROL2`.
- **Errores de PostgreSQL** (`RAISE EXCEPTION`, `CHECK`, `UNIQUE`): se convierten en un 422 legible en [`bootstrap/app.php`](bootstrap/app.php).

| Servicio | Caso de uso |
|---|---|
| `AuthService`, `UsuarioService` | CU1 · sesión, menú por rol, usuarios y perfil |
| `DelegacionService` | CU2 · suplencias, emisión de certificados de firma e indicadores |
| `TramiteService` | CU3 · registro de trámites, documentos, descuento y expediente |
| `FlujoTramiteService`, `AuditoriaService` | CU4 · revisión, observaciones, subsanación, archivos y bitácora |
| `DespachoService`, `LegajoService` | CU5 · bandeja, legajo foliado, firma por lote o remota y visado |
| `CertificadoService`, `SeguimientoService` | CU6 · certificado con QR, anulación, portal y validador |
| `SlaService`, `NotificacionService` | CU7 · monitor SLA, alertas, parametrización y notificaciones |
| `FirmadorService` | Cliente HTTP del microservicio de firma |

## Base de datos

- **Esquema completo:** [`database/sql/legalizaciones.sql`](database/sql/legalizaciones.sql). Contiene los ENUM, las tablas, la máquina de estados, la bitácora con cadena de hash, las reglas RN/RF en triggers, las vistas, los catálogos y los menús por rol.
- **Migración única:** [`0001_01_01_000000_crear_esquema_legalizaciones.php`](database/migrations/0001_01_01_000000_crear_esquema_legalizaciones.php) ejecuta ese script. Si la base ya tiene el esquema, no la modifica.
- **Seeder:** [`DatabaseSeeder`](database/seeders/DatabaseSeeder.php) crea un usuario de prueba por rol.

```bash
php artisan migrate --seed          # instalación nueva
php artisan migrate:fresh --seed    # reinstalar (borra los datos)
```

## Comandos útiles

```bash
php artisan serve                 # API en http://127.0.0.1:8000
php artisan route:list --path=api # endpoints y su middleware de rol
php artisan sla:verificar         # genera alertas SLA ahora mismo
php artisan schedule:work         # ejecuta las tareas programadas (desarrollo)
vendor/bin/pint                   # formato de código
```

## Configuración (`.env`)

| Variable | Uso |
|---|---|
| `DB_*` | Conexión a PostgreSQL |
| `APP_TIMEZONE` | `America/La_Paz`, la misma zona horaria que la base de datos |
| `FIRMADOR_URL`, `FIRMADOR_API_KEY`, `FIRMADOR_TIMEOUT` | Microservicio de firma (la clave debe coincidir con la del firmador) |
| `FRONTEND_URL` | Base de la URL que codifica el QR de los certificados (`/validar-certificado`) |

Los archivos subidos (documentos, legajos, PDF firmados, certificados y resoluciones) se guardan en `storage/app/public`. **No son públicos**: la API los entrega solo a usuarios autorizados.
