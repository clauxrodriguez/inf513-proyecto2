<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\User;
use App\Repositories\ArchivoRepository;
use App\Repositories\CatalogoRepository;
use App\Repositories\ExpedienteRepository;
use App\Repositories\NotificacionRepository;
use App\Repositories\OrigenRepository;
use App\Repositories\TramiteRepository;
use App\Repositories\UnidadDeTrabajo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Throwable;

/**
 * CU4 - Enrutar flujo, guía procesal, observaciones y subsanaciones.
 */
class FlujoTramiteService
{
    public const TIPOS_OBSERVACION = [
        'FOJA_ILEGIBLE', 'SELLO_FALTANTE', 'FIRMA_FALTANTE', 'PAGO_INSUFICIENTE',
        'FOLIACION_INCORRECTA', 'DOCUMENTO_EXTERNO', 'FUERA_DE_PLAZO', 'OTRO',
    ];

    private const ESTADOS_ORIGEN = ['SOLICITADO_ORIGEN', 'EN_FIRMA_ORIGEN'];

    private const ROLES_FIRMANTES_DESPACHO = ['VICERRECTOR'];

    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private TramiteRepository $tramites,
        private CatalogoRepository $catalogos,
        private ExpedienteRepository $expedientes,
        private OrigenRepository $origen,
        private ArchivoRepository $archivos,
        private NotificacionRepository $notificaciones,
    ) {}

    /**
     * @return array{flujo: Collection, documentos: Collection, requisitos: Collection}
     */
    public function guia(): array
    {
        $firmas = $this->catalogos->firmasPrecedentes();

        return [
            'flujo' => $this->catalogos->guiaProcesal(),
            'documentos' => $this->catalogos->documentosActivos()->transform(function (object $documento) use ($firmas): object {
                $documento->firmas_precedentes = $firmas->get($documento->id, collect())->values();

                return $documento;
            }),
            'requisitos' => $this->catalogos->requisitos(),
        ];
    }

    /**
     * Ventanilla recibe un trámite que completó sus firmas en origen (INGRESADO) y abre su revisión.
     */
    public function iniciarRevision(int $tramiteId): void
    {
        $tramite = $this->obtener($tramiteId);
        if ($tramite->estado !== 'INGRESADO') {
            throw new ReglaNegocioException("El trámite {$tramite->codigo} no está pendiente de recepción (estado {$tramite->estado}).");
        }

        $this->tramites->cambiarEstado($tramiteId, 'EN_REVISION');
    }

    /**
     * Archivo digital de un documento del trámite (solo el titular o el personal autorizado).
     *
     * @return array{ruta: string, nombre: string}
     */
    public function archivoDocumento(User $usuario, int $documentoId): array
    {
        $documento = $this->tramites->buscarDocumento($documentoId)
            ?? throw ReglaNegocioException::noEncontrado('El documento no existe.');
        $tramite = $this->obtenerConAcceso($usuario, $documento->tramite_id);

        return $this->archivo($documento->archivo_ruta, "{$tramite->codigo}_documento_{$documentoId}");
    }

    /**
     * PDF de una versión del expediente (solo el titular o el personal autorizado).
     *
     * @return array{ruta: string, nombre: string}
     */
    public function archivoExpediente(User $usuario, int $expedienteId): array
    {
        $expediente = $this->expedientes->buscar($expedienteId)
            ?? throw ReglaNegocioException::noEncontrado('La versión del expediente no existe.');
        $tramite = $this->obtenerConAcceso($usuario, $expediente->tramite_id);

        return $this->archivo($expediente->ruta_pdf, "{$tramite->codigo}_expediente_v{$expediente->version}");
    }

    /**
     * Detalle completo del trámite. El estudiante solo accede a los suyos.
     */
    public function detalle(User $usuario, int $id): object
    {
        $tramite = $this->tramites->buscarDetalle($id) ?? throw ReglaNegocioException::noEncontrado('El trámite no existe.');
        if ($usuario->rol === 'ESTUDIANTE' && (int) $tramite->solicitante_id !== (int) $usuario->id) {
            throw ReglaNegocioException::noEncontrado('El trámite no existe.');
        }

        $documentos = $this->tramites->documentosDe([$id])->get($id, collect())->values();
        $cadenas = $this->catalogos->firmasPrecedentes($documentos->pluck('catalogo_documento_id'));
        $firmas = $this->origen->firmasDe($documentos->pluck('id'));
        $tramite->documentos = $documentos->transform(function (object $documento) use ($cadenas, $firmas): object {
            $documento->firmas_precedentes = $cadenas->get($documento->catalogo_documento_id, collect())->values();
            $documento->firmas_origen = $firmas->get($documento->id, collect())->values();

            return $documento;
        });
        $tramite->requisitos = $this->tramites->requisitosDe([$id])->get($id, collect())->values();
        $tramite->observaciones = $this->tramites->observacionesDe($id);
        $tramite->comprobantes = $this->tramites->comprobantesDe($id);
        $tramite->descuento = $this->tramites->descuentosDe([$id])->get($id);
        $tramite->expedientes = $this->expedientes->historialDe($id);
        $tramite->certificado_vigente = $this->tramites->tieneCertificadoOficialVigente($id);
        $tramite->flujo = $this->tramites->flujoDe($tramite);
        $tramite->ingreso_a_ventanilla = $this->tramites->pasoPorEstado($id, 'INGRESADO');

        return $tramite;
    }

    /**
     * Registra una observación y detiene el SLA (estado OBSERVADO). Pueden observar:
     * las unidades de origen mientras el trámite está en firma de origen, Ventanilla durante la revisión
     * y la autoridad firmante desde la bandeja de despacho.
     *
     * @param  array{tipo: string, descripcion: string, tramite_documento_id?: ?int}  $datos
     * @return array{observacion_id: int, etapa: string}
     */
    public function registrarObservacion(User $usuario, int $tramiteId, array $datos): array
    {
        $tramite = $this->obtener($tramiteId);
        $etapa = match (true) {
            in_array($tramite->estado, self::ESTADOS_ORIGEN, true) && isset(OrigenService::UNIDAD_POR_ROL[$usuario->rol]) => 'ORIGEN',
            $tramite->estado === 'EN_REVISION' && $usuario->rol === 'RECEPCION' => 'VENTANILLA',
            $tramite->estado === 'EN_BANDEJA_DESPACHO' && in_array($usuario->rol, self::ROLES_FIRMANTES_DESPACHO, true) => 'DESPACHO',
            default => throw new ReglaNegocioException('En el estado actual del trámite su rol no puede registrar observaciones.'),
        };
        $documentoId = $datos['tramite_documento_id'] ?? null;
        if ($documentoId && ! $this->tramites->documentoPerteneceA($documentoId, $tramiteId)) {
            throw new ReglaNegocioException('El documento seleccionado no pertenece a este trámite.');
        }

        $observacionId = $this->transaccion->ejecutar(function () use ($usuario, $tramiteId, $datos, $documentoId): int {
            $observacionId = $this->tramites->crearObservacion([
                'tramite_id' => $tramiteId,
                'tramite_documento_id' => $documentoId,
                'tipo' => $datos['tipo'],
                'descripcion' => trim($datos['descripcion']),
                'registrado_por' => $usuario->id,
            ]);
            $this->tramites->cambiarEstado($tramiteId, 'OBSERVADO');

            return $observacionId;
        });

        return ['observacion_id' => $observacionId, 'etapa' => $etapa];
    }

    /**
     * Subsana una observación (con respaldo opcional). Si no quedan observaciones pendientes, el trámite
     * vuelve a su etapa: firma en origen (aún sin expediente) o revisión de Ventanilla.
     *
     * @return ?string estado al que volvió el trámite, o null si quedan observaciones pendientes
     */
    public function subsanarObservacion(User $usuario, int $observacionId, ?UploadedFile $archivo): ?string
    {
        $observacion = $this->tramites->buscarObservacionConTramite($observacionId)
            ?? throw ReglaNegocioException::noEncontrado('La observación no existe.');
        if ($usuario->rol === 'ESTUDIANTE' && (int) $observacion->solicitante_id !== (int) $usuario->id) {
            throw ReglaNegocioException::noEncontrado('La observación no existe.');
        }
        if ($observacion->estado !== 'PENDIENTE') {
            throw new ReglaNegocioException('Esta observación ya fue subsanada o anulada.');
        }
        if ($observacion->tramite_estado !== 'OBSERVADO') {
            throw new ReglaNegocioException('El trámite ya no está en estado observado.');
        }

        // Si el trámite nunca ingresó a Ventanilla, la observación fue de origen: vuelve a la cadena de firmas
        $enVentanilla = $this->tramites->pasoPorEstado($observacion->tramite_id, 'INGRESADO');

        // Los documentos firmados en origen no se reemplazan: el respaldo queda como nueva versión del expediente
        $ruta = $archivo && $enVentanilla
            ? ($this->archivos->guardarSubido($archivo, "tramites/{$observacion->codigo}/subsanaciones")
                ?? throw new ReglaNegocioException('No se pudo guardar el archivo de subsanación.', 500))
            : null;

        try {
            return $this->transaccion->ejecutar(function () use ($observacion, $usuario, $ruta, $archivo, $enVentanilla): ?string {
                if ($ruta) {
                    $this->expedientes->crearVersion([
                        'tramite_id' => $observacion->tramite_id,
                        'ruta_pdf' => $ruta,
                        'total_fojas' => max(1, (int) $observacion->total_fojas),
                        'hash_sha256' => hash_file('sha256', $archivo->getRealPath()),
                        'compilado_por' => $usuario->id,
                    ]);
                }
                $this->tramites->marcarObservacionSubsanada($observacion->id);

                if ($this->tramites->tieneObservacionesPendientes($observacion->tramite_id)) {
                    return null;
                }
                $estado = $enVentanilla ? 'EN_REVISION' : 'EN_FIRMA_ORIGEN';
                $this->tramites->cambiarEstado($observacion->tramite_id, $estado, soloSiEstado: 'OBSERVADO');

                return $estado;
            });
        } catch (Throwable $e) {
            $this->archivos->eliminar($ruta);
            throw $e;
        }
    }

    public function actualizarRequisito(User $usuario, int $tramiteId, int $requisitoId, bool $cumplido): void
    {
        $tramite = $this->obtener($tramiteId);
        if ($tramite->estado !== 'EN_REVISION') {
            throw new ReglaNegocioException('Los requisitos solo pueden verificarse durante la revisión.');
        }
        if (! $this->tramites->tieneRequisito($tramiteId, $requisitoId)) {
            throw ReglaNegocioException::noEncontrado('El requisito no corresponde a este trámite.');
        }

        $this->tramites->actualizarRequisito($tramiteId, $requisitoId, [
            'cumplido' => $cumplido,
            'verificado_por' => $cumplido ? $usuario->id : null,
            'fecha_verificacion' => $cumplido ? now() : null,
        ]);
    }

    /**
     * PostgreSQL valida observaciones, requisitos, recibos, plazo del descuento y expediente antes de aceptar el cambio.
     */
    public function enviarADespacho(int $tramiteId): void
    {
        $tramite = $this->obtener($tramiteId);
        if ($tramite->estado !== 'EN_REVISION') {
            throw new ReglaNegocioException('Solo los trámites en revisión pueden enviarse a despacho.');
        }

        $this->tramites->cambiarEstado($tramiteId, 'EN_BANDEJA_DESPACHO');
        $this->notificaciones->notificarRol('VICERRECTOR', $tramiteId, 'PENDIENTE_FIRMA',
            "El expediente {$tramite->codigo} espera su firma digital en la bandeja de despacho.");
    }

    private function obtenerConAcceso(User $usuario, int $tramiteId): object
    {
        $tramite = $this->obtener($tramiteId);
        if ($usuario->rol === 'ESTUDIANTE' && (int) $tramite->solicitante_id !== (int) $usuario->id) {
            throw ReglaNegocioException::noEncontrado('El trámite no existe.');
        }

        return $tramite;
    }

    /**
     * @return array{ruta: string, nombre: string}
     */
    private function archivo(?string $rutaPublica, string $nombreBase): array
    {
        $ruta = $this->archivos->rutaEnDisco($rutaPublica)
            ?? throw ReglaNegocioException::noEncontrado('El archivo digital no está disponible en el servidor.');

        return ['ruta' => $ruta, 'nombre' => $nombreBase.'.'.pathinfo($ruta, PATHINFO_EXTENSION)];
    }

    private function obtener(int $tramiteId): object
    {
        return $this->tramites->buscar($tramiteId) ?? throw ReglaNegocioException::noEncontrado('El trámite no existe.');
    }
}
