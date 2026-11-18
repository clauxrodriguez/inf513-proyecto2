<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CertificadoController;
use App\Http\Controllers\Api\CuatroController;
use App\Http\Controllers\Api\DelegacionController;
use App\Http\Controllers\Api\DespachoController;
use App\Http\Controllers\Api\NotificacionController;
use App\Http\Controllers\Api\PortalPublicoController;
use App\Http\Controllers\Api\SlaController;
use App\Http\Controllers\Api\TramiteController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Middleware\SetPostgresAuditUser;
use Illuminate\Support\Facades\Route;

/*
| Capa 2 - entrada de la lógica de negocio.
| auth:sanctum autentica, SetPostgresAuditUser identifica al usuario ante la bitácora de PostgreSQL
| y 'rol:...' (VerificarRol) controla qué roles acceden a cada operación.
*/

Route::post('/login', [AuthController::class, 'login']);
Route::post('/visitas', [AuthController::class, 'registrarVisita']);

// CU6: portal de seguimiento estudiantil y validador público (sin sesión, con límite de peticiones)
Route::prefix('publico')->group(function () {
    Route::post('/seguimiento', [PortalPublicoController::class, 'seguimiento'])->middleware('throttle:portal-seguimiento');
    Route::get('/certificados/verificar', [PortalPublicoController::class, 'verificar'])->middleware('throttle:validador');
    Route::post('/certificados/verificar-archivo', [PortalPublicoController::class, 'verificarArchivo'])->middleware('throttle:validador-archivo');
    Route::get('/certificados/{codigo}/pdf', [PortalPublicoController::class, 'descargar'])->middleware('throttle:validador');
});

Route::middleware(['auth:sanctum', SetPostgresAuditUser::class])->group(function () {
    // Sesión y perfil propio (todos los roles)
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/perfil', [UsuarioController::class, 'actualizarPerfil']);

    // CU1: Administración de usuarios
    Route::middleware('rol:ADMINISTRADOR')->group(function () {
        Route::get('/usuarios', [UsuarioController::class, 'index']);
        Route::post('/usuarios', [UsuarioController::class, 'store']);
        Route::put('/usuarios/{id}', [UsuarioController::class, 'update'])->whereNumber('id');
        Route::patch('/usuarios/{id}/estado', [UsuarioController::class, 'toggleEstado'])->whereNumber('id');
    });

    // CU2: Delegación de autoridad, certificados digitales e indicadores
    Route::get('/delegaciones', [DelegacionController::class, 'index']);
    Route::get('/certificados-digitales/ac-raiz', [DelegacionController::class, 'certificadoRaiz']);
    Route::get('/delegaciones/{id}/resolucion', [DelegacionController::class, 'resolucion'])->whereNumber('id');
    Route::middleware('rol:RECTORADO')->group(function () {
        Route::post('/delegaciones', [DelegacionController::class, 'store']);
        Route::patch('/delegaciones/{id}/revocar', [DelegacionController::class, 'revocar'])->whereNumber('id');
        Route::post('/certificados-digitales', [DelegacionController::class, 'registrarCertificado']);
    });

    // CU3: Trámites, documentos y expediente
    Route::get('/tramites/catalogos', [TramiteController::class, 'catalogos']);
    Route::get('/tramites', [TramiteController::class, 'index']);
    Route::post('/tramites', [TramiteController::class, 'store'])->middleware('rol:RECEPCION,ESTUDIANTE');
    Route::patch('/tramites/documentos/{documentoId}/validar', [TramiteController::class, 'validarDocumento'])
        ->whereNumber('documentoId')->middleware('rol:RECEPCION');

    // CU4: Flujo, guía procesal, observaciones y bitácora
    Route::get('/tramites/guia', [CuatroController::class, 'guia']);
    Route::get('/tramites/{id}', [CuatroController::class, 'show'])->whereNumber('id');
    // Archivos digitales del trámite: solo el titular o el personal (se sirven por la API, no son públicos)
    Route::get('/tramites/documentos/{documentoId}/archivo', [CuatroController::class, 'archivoDocumento'])->whereNumber('documentoId');
    Route::get('/tramites/expedientes/{expedienteId}/archivo', [CuatroController::class, 'archivoExpediente'])->whereNumber('expedienteId');
    Route::post('/tramites/{id}/observaciones', [CuatroController::class, 'registrarObservacion'])
        ->whereNumber('id')->middleware('rol:RECEPCION,VICERRECTOR,SUPLENTE,RECTORADO,ADMINISTRADOR');
    Route::post('/tramites/observaciones/{observacionId}/subsanar', [CuatroController::class, 'subsanarObservacion'])
        ->whereNumber('observacionId')->middleware('rol:ESTUDIANTE,RECEPCION');
    Route::middleware('rol:RECEPCION')->group(function () {
        Route::patch('/tramites/{id}/iniciar-revision', [CuatroController::class, 'iniciarRevision'])->whereNumber('id');
        Route::patch('/tramites/{id}/requisitos/{requisitoId}', [CuatroController::class, 'actualizarRequisito'])
            ->whereNumber('id')->whereNumber('requisitoId');
        Route::patch('/tramites/{id}/enviar-despacho', [CuatroController::class, 'enviarADespacho'])->whereNumber('id');
    });
    Route::patch('/tramites/{id}/enrutar-posterior-firma', [CuatroController::class, 'enrutarPosteriorFirma'])
        ->whereNumber('id')->middleware('rol:VICERRECTOR,SUPLENTE');
    Route::get('/bitacora', [CuatroController::class, 'bitacora'])->middleware('rol:ADMINISTRADOR');

    // CU5: Despachar, previsualizar legajos y firma digital consolidada o remota
    Route::middleware('rol:VICERRECTOR,SUPLENTE')->group(function () {
        Route::get('/despacho', [DespachoController::class, 'bandeja']);
        Route::post('/despacho/firmar', [DespachoController::class, 'firmar']);
    });
    Route::middleware('rol:VICERRECTOR,SUPLENTE,RECTORADO,ADMINISTRADOR')->group(function () {
        Route::get('/despacho/{id}/legajo', [DespachoController::class, 'legajo'])->whereNumber('id');
        Route::get('/despacho/{id}/verificacion', [DespachoController::class, 'verificar'])->whereNumber('id');
    });
    Route::middleware('rol:RECTORADO')->group(function () {
        Route::get('/rectorado/bandeja', [DespachoController::class, 'bandejaRectorado']);
        Route::post('/rectorado/{id}/visar', [DespachoController::class, 'visar'])->whereNumber('id');
    });

    // RF-19: notificaciones del usuario autenticado (todos los roles)
    Route::get('/notificaciones', [NotificacionController::class, 'index']);
    Route::patch('/notificaciones/leidas', [NotificacionController::class, 'marcarTodasLeidas']);
    Route::patch('/notificaciones/{id}/leida', [NotificacionController::class, 'marcarLeida'])->whereNumber('id');

    // CU7: Monitoreo SLA de 72 horas hábiles
    Route::middleware('rol:RECEPCION,VICERRECTOR,SUPLENTE,RECTORADO,ADMINISTRADOR')->group(function () {
        Route::get('/sla/monitor', [SlaController::class, 'monitor']);
        Route::get('/sla/tramites/{tramiteId}/fases', [SlaController::class, 'desglose'])->whereNumber('tramiteId');
        Route::get('/sla/configuracion', [SlaController::class, 'configuracion']);
    });
    Route::patch('/sla/alertas/{alertaId}/atender', [SlaController::class, 'atenderAlerta'])
        ->whereNumber('alertaId')->middleware('rol:RECEPCION');
    Route::middleware('rol:ADMINISTRADOR')->group(function () {
        Route::put('/sla/configuracion', [SlaController::class, 'guardarConfiguracion']);
        Route::delete('/sla/configuracion/{tipo}', [SlaController::class, 'eliminarConfiguracion'])
            ->whereIn('tipo', ['LEGALIZACION', 'DESCUENTO_DIPLOMADO']);
    });

    // CU6: Certificado oficial con QR (emisión en Recepción; consulta y anulación también por las autoridades)
    Route::middleware('rol:RECEPCION,VICERRECTOR,SUPLENTE,RECTORADO')->group(function () {
        Route::get('/certificados', [CertificadoController::class, 'index']);
        Route::get('/certificados/{id}/pdf', [CertificadoController::class, 'pdf'])->whereNumber('id');
        Route::post('/certificados/{id}/anular', [CertificadoController::class, 'anular'])->whereNumber('id');
    });
    Route::middleware('rol:RECEPCION')->group(function () {
        Route::post('/certificados/tramites/{tramiteId}/emitir', [CertificadoController::class, 'emitir'])->whereNumber('tramiteId');
        Route::post('/certificados/{id}/entrega', [CertificadoController::class, 'registrarEntrega'])->whereNumber('id');
    });
});
