<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CertificadoController;
use App\Http\Controllers\Api\CuatroController;
use App\Http\Controllers\Api\DelegacionController;
use App\Http\Controllers\Api\DespachoController;
use App\Http\Controllers\Api\EstadisticasController;
use App\Http\Controllers\Api\FirmaDigitalController;
use App\Http\Controllers\Api\NotificacionController;
use App\Http\Controllers\Api\OrigenController;
use App\Http\Controllers\Api\PagoController;
use App\Http\Controllers\Api\PortalPublicoController;
use App\Http\Controllers\Api\SlaController;
use App\Http\Controllers\Api\TramiteController;
use App\Http\Controllers\Api\UsuarioController;
use Illuminate\Support\Facades\Route;

/*
| Capa 2 - entrada de la lógica de negocio.
| auth:sanctum autentica; 'transaccion' ejecuta la petición en una transacción con el usuario de auditoría
| (set_config local) y 'permiso:...' aplica la matriz de acceso rol_permiso del rol activo.
| RegistrarAccesoWeb (grupo api) deja cada petición en bitacora_acceso.
*/

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/visitas', [AuthController::class, 'registrarVisita'])->middleware('throttle:visitas');

// Portal de seguimiento estudiantil y validador público de certificados (sin sesión)
Route::prefix('publico')->group(function () {
    Route::post('/seguimiento', [PortalPublicoController::class, 'seguimiento'])->middleware('throttle:portal-seguimiento');
    Route::get('/certificados/verificar', [PortalPublicoController::class, 'verificar'])->middleware('throttle:validador');
    Route::get('/certificados/{codigo}/pdf', [PortalPublicoController::class, 'descargar'])->middleware('throttle:validador');
});

// Webhook de Stripe (sin sesión; la firma Stripe-Signature se verifica en el servicio)
Route::post('/stripe/webhook', [PagoController::class, 'webhook'])->middleware('throttle:120,1');

// Validación pública de un PDF firmado (Validador Web de AGETIC) y su relación con el certificado oficial
Route::post('/certificados/validar-pdf', [PortalPublicoController::class, 'validarPdf'])->middleware('throttle:validador-archivo');

Route::middleware(['auth:sanctum', 'transaccion'])->group(function () {
    // Sesión, menú dinámico y perfil propio (todos los roles)
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/menu', [AuthController::class, 'menu']);
    Route::put('/sesion/rol', [AuthController::class, 'cambiarRol']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/perfil', [UsuarioController::class, 'actualizarPerfil']);

    // Dashboard, búsqueda global del encabezado y reportes estadísticos
    Route::get('/dashboard', [EstadisticasController::class, 'resumen'])->middleware('permiso:DASHBOARD_VER');
    Route::get('/buscar', [EstadisticasController::class, 'buscar'])->middleware('throttle:busqueda');
    Route::get('/reportes', [EstadisticasController::class, 'reporte'])->middleware('permiso:REPORTES_VER');

    // Notificaciones web del usuario autenticado (campanita)
    Route::get('/notificaciones', [NotificacionController::class, 'index']);
    Route::patch('/notificaciones/leidas', [NotificacionController::class, 'marcarTodasLeidas']);
    Route::patch('/notificaciones/{id}/leida', [NotificacionController::class, 'marcarLeida'])->whereNumber('id');

    // CU1: Usuarios y roles
    Route::middleware('permiso:USUARIOS_GESTIONAR')->group(function () {
        Route::get('/usuarios', [UsuarioController::class, 'index']);
        Route::post('/usuarios', [UsuarioController::class, 'store']);
        Route::put('/usuarios/{id}', [UsuarioController::class, 'update'])->whereNumber('id');
        Route::patch('/usuarios/{id}/estado', [UsuarioController::class, 'toggleEstado'])->whereNumber('id');
    });

    // CU2: Delegación de autoridad (el panel también lista los certificados de firma registrados, solo consulta)
    Route::middleware('permiso:DELEGACION_GESTIONAR')->group(function () {
        Route::get('/delegaciones', [DelegacionController::class, 'index']);
        Route::post('/delegaciones', [DelegacionController::class, 'store']);
        Route::patch('/delegaciones/{id}/revocar', [DelegacionController::class, 'revocar'])->whereNumber('id');
    });

    // CU3: Trámites (el estudiante es el único iniciador)
    Route::get('/tramites/catalogos', [TramiteController::class, 'catalogos']);
    Route::get('/tramites', [TramiteController::class, 'index']);
    Route::post('/tramites', [TramiteController::class, 'store'])->middleware('permiso:TRAMITES_SOLICITAR');

    // Origen facultativo: emisión y firma digital en cadena (Carrera, Decanato, Admisiones, DAGA)
    Route::middleware('permiso:TRAMITES_ORIGEN')->group(function () {
        Route::get('/origen/bandeja', [OrigenController::class, 'bandeja']);
        Route::post('/origen/documentos/{documentoId}/emitir', [OrigenController::class, 'emitir'])->whereNumber('documentoId');
        Route::post('/origen/firmar', [OrigenController::class, 'firmar']);
    });

    // Pagos en línea al contado (Stripe): billetera de tarjetas y pago del arancel del trámite
    Route::middleware('permiso:PAGOS_REALIZAR')->group(function () {
        Route::get('/metodos-pago', [PagoController::class, 'metodos']);
        Route::post('/metodos-pago/setup-intent', [PagoController::class, 'prepararTarjeta']);
        Route::post('/metodos-pago', [PagoController::class, 'guardarTarjeta']);
        Route::patch('/metodos-pago/{id}/predeterminado', [PagoController::class, 'predeterminar'])->whereNumber('id');
        Route::delete('/metodos-pago/{id}', [PagoController::class, 'eliminarTarjeta'])->whereNumber('id');
        Route::post('/tramites/{id}/pagar', [PagoController::class, 'iniciarPago'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('/pagos/{transaccionId}/confirmar', [PagoController::class, 'confirmarPago'])->whereNumber('transaccionId');
    });
    Route::get('/pagos', [PagoController::class, 'historial'])->middleware('permiso:PAGOS_REALIZAR,TRAMITES_RECEPCION,REPORTES_VER');

    // Firma con Jacobitus (token / softoken del firmante): origen, despacho y Rectorado
    Route::middleware('permiso:TRAMITES_ORIGEN,DESPACHO_FIRMAR,RECTORADO_VISAR')->group(function () {
        Route::post('/firmas/preparar', [FirmaDigitalController::class, 'preparar']);
        Route::post('/firmas/confirmar-lote', [FirmaDigitalController::class, 'confirmarLote']);
        Route::post('/firmas/certificado', [FirmaDigitalController::class, 'registrarCertificado']);
    });

    // Ventanilla del Vicerrectorado: verificación de firmas, recibos de caja y compilación del expediente
    Route::get('/tramites/documentos/{documentoId}/verificacion', [TramiteController::class, 'verificarDocumento'])
        ->whereNumber('documentoId')->middleware('permiso:TRAMITES_RECEPCION,TRAMITES_ORIGEN');
    Route::middleware('permiso:TRAMITES_RECEPCION')->group(function () {
        Route::post('/tramites/{id}/comprobantes', [TramiteController::class, 'registrarComprobante'])->whereNumber('id');
        Route::delete('/tramites/comprobantes/{comprobanteId}', [TramiteController::class, 'eliminarComprobante'])->whereNumber('comprobanteId');
        Route::post('/tramites/{id}/compilar', [TramiteController::class, 'compilarExpediente'])->whereNumber('id');
    });

    // CU4: Flujo, guía procesal, observaciones y bitácora
    Route::get('/tramites/guia', [CuatroController::class, 'guia']);
    Route::get('/tramites/{id}', [CuatroController::class, 'show'])->whereNumber('id');
    Route::get('/tramites/documentos/{documentoId}/archivo', [CuatroController::class, 'archivoDocumento'])->whereNumber('documentoId');
    Route::get('/tramites/expedientes/{expedienteId}/archivo', [CuatroController::class, 'archivoExpediente'])->whereNumber('expedienteId');
    Route::post('/tramites/{id}/observaciones', [CuatroController::class, 'registrarObservacion'])
        ->whereNumber('id')->middleware('permiso:TRAMITES_ORIGEN,TRAMITES_RECEPCION,DESPACHO_FIRMAR,RECTORADO_VISAR');
    Route::post('/tramites/observaciones/{observacionId}/subsanar', [CuatroController::class, 'subsanarObservacion'])
        ->whereNumber('observacionId')->middleware('permiso:TRAMITES_SOLICITAR,TRAMITES_RECEPCION');
    Route::middleware('permiso:TRAMITES_RECEPCION')->group(function () {
        Route::patch('/tramites/{id}/iniciar-revision', [CuatroController::class, 'iniciarRevision'])->whereNumber('id');
        Route::patch('/tramites/{id}/requisitos/{requisitoId}', [CuatroController::class, 'actualizarRequisito'])
            ->whereNumber('id')->whereNumber('requisitoId');
        Route::patch('/tramites/{id}/enviar-despacho', [CuatroController::class, 'enviarADespacho'])->whereNumber('id');
    });
    Route::get('/bitacora', [CuatroController::class, 'bitacora'])->middleware('permiso:BITACORA_AUDITAR');

    // CU5: Despacho, previsualización de legajos y firma digital consolidada o remota
    Route::middleware('permiso:DESPACHO_FIRMAR')->group(function () {
        Route::get('/despacho', [DespachoController::class, 'bandeja']);
        Route::post('/despacho/firmar', [DespachoController::class, 'firmar']);
    });
    Route::middleware('permiso:DESPACHO_FIRMAR,RECTORADO_VISAR,BITACORA_AUDITAR')->group(function () {
        Route::get('/despacho/{id}/legajo', [DespachoController::class, 'legajo'])->whereNumber('id');
        Route::get('/despacho/{id}/verificacion', [DespachoController::class, 'verificar'])->whereNumber('id');
    });
    Route::middleware('permiso:RECTORADO_VISAR')->group(function () {
        Route::get('/rectorado/bandeja', [DespachoController::class, 'bandejaRectorado']);
        Route::post('/rectorado/{id}/firmar', [DespachoController::class, 'firmarRectorado'])->whereNumber('id');
    });

    // CU7: Monitoreo SLA de 72 horas hábiles
    Route::middleware('permiso:SLA_MONITOREAR')->group(function () {
        Route::get('/sla/monitor', [SlaController::class, 'monitor']);
        Route::get('/sla/tramites/{tramiteId}/fases', [SlaController::class, 'desglose'])->whereNumber('tramiteId');
        Route::get('/sla/configuracion', [SlaController::class, 'configuracion']);
    });
    Route::patch('/sla/alertas/{alertaId}/atender', [SlaController::class, 'atenderAlerta'])
        ->whereNumber('alertaId')->middleware('permiso:TRAMITES_RECEPCION');
    Route::middleware('permiso:SLA_CONFIGURAR')->group(function () {
        Route::put('/sla/configuracion', [SlaController::class, 'guardarConfiguracion']);
        Route::delete('/sla/configuracion/{tipo}', [SlaController::class, 'eliminarConfiguracion'])
            ->whereIn('tipo', ['LEGALIZACION', 'DESCUENTO_DIPLOMADO']);
    });

    // CU6: Certificado oficial con QR (emisión en Ventanilla; consulta y anulación también por las autoridades)
    Route::middleware('permiso:TRAMITES_RECEPCION,DESPACHO_FIRMAR,RECTORADO_VISAR')->group(function () {
        Route::get('/certificados', [CertificadoController::class, 'index']);
        Route::get('/certificados/{id}/pdf', [CertificadoController::class, 'pdf'])->whereNumber('id');
        Route::post('/certificados/{id}/anular', [CertificadoController::class, 'anular'])->whereNumber('id');
    });
    Route::middleware('permiso:TRAMITES_RECEPCION')->group(function () {
        Route::post('/certificados/tramites/{tramiteId}/emitir', [CertificadoController::class, 'emitir'])->whereNumber('tramiteId');
    });
});
