<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\User;
use App\Repositories\ArchivoRepository;
use App\Repositories\CatalogoRepository;
use App\Repositories\ExpedienteRepository;
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
        'SELLO_FALTANTE', 'FIRMA_FALTANTE', 'FOJA_ILEGIBLE', 'VALORADO_FALTANTE', 'VALORADO_INSUFICIENTE',
        'FOLIACION_INCORRECTA', 'DOCUMENTO_EXTERNO', 'FUERA_DE_PLAZO', 'OTRO',
    ];

    private const ROLES_FIRMANTES = ['VICERRECTOR', 'SUPLENTE'];

    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private TramiteRepository $tramites,
        private CatalogoRepository $catalogos,
        private ExpedienteRepository $expedientes,
        private ArchivoRepository $archivos,
    ) {}

    /**
     * @return array{flujo: Collection, documentos: Collection, requisitos: Collection}
     */
    public function guia(): array
    {
        return [
            'flujo' => $this->catalogos->guiaProcesal(),
            'documentos' => $this->catalogos->rutaDocumentos(),
            'requisitos' => $this->catalogos->requisitosPorTipo(),
        ];
    }

    /**
     * Recepción recibe un trámite solicitado en línea por el estudiante (INGRESADO) y abre su revisión.
     * Es la vía para los trámites sin documentos que validar (descuento de diplomado).
     */
    public function iniciarRevision(int $tramiteId): void
    {
        $tramite = $this->obtener($tramiteId);
        if ($tramite->estado !== 'INGRESADO') {
            throw new ReglaNegocioException("El trámite {$tramite->codigo} ya fue recibido (estado {$tramite->estado}).");
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
        $firmas = $this->catalogos->firmasPrecedentes($documentos->pluck('catalogo_documento_id'));
        $tramite->documentos = $documentos->transform(function ($documento) use ($firmas) {
            $documento->firmas_precedentes = $firmas->get($documento->catalogo_documento_id, collect())->values();

            return $documento;
        });
        $tramite->requisitos = $this->tramites->requisitosDe([$id])->get($id, collect())->values();
        $tramite->observaciones = $this->tramites->observacionesDe($id);
        $tramite->descuento = $this->tramites->descuentosDe([$id])->get($id);
        $tramite->expedientes = $this->expedientes->historialDe($id);
        $tramite->certificado_vigente = $this->tramites->tieneCertificadoOficialVigente($id);
        $tramite->flujo = $this->tramites->flujoDe($tramite->codigo);

        return $tramite;
    }

    /**
     * Registra una observación y detiene el SLA (estado OBSERVADO).
     * SF-3 (CU5): la autoridad firmante también puede devolver un expediente desde la bandeja de despacho.
     *
     * @param  array{tipo: string, descripcion: string, tramite_documento_id?: ?int}  $datos
     * @return array{observacion_id: int, desde_despacho: bool}
     */
    public function registrarObservacion(User $usuario, int $tramiteId, array $datos): array
    {
        $tramite = $this->obtener($tramiteId);
        $desdeDespacho = $tramite->estado === 'EN_BANDEJA_DESPACHO' && in_array($usuario->rol, self::ROLES_FIRMANTES, true);
        if ($tramite->estado !== 'EN_REVISION' && ! $desdeDespacho) {
            throw new ReglaNegocioException('Solo se pueden registrar observaciones durante la revisión o, por la autoridad firmante, desde la bandeja de despacho.');
        }
        $documentoId = $datos['tramite_documento_id'] ?? null;
        if ($documentoId && ! $this->tramites->documentoPerteneceA($documentoId, $tramiteId)) {
            throw new ReglaNegocioException('El documento seleccionado no pertenece a este trámite.');
        }

        $observacionId = $this->transaccion->ejecutar(function () use ($usuario, $tramiteId, $datos, $documentoId) {
            $observacionId = $this->tramites->crearObservacion([
                'tramite_id' => $tramiteId,
                'tramite_documento_id' => $documentoId,
                'tipo' => $datos['tipo'],
                'descripcion' => trim($datos['descripcion']),
                'estado' => 'PENDIENTE',
                'registrado_por' => $usuario->id,
            ]);
            $this->tramites->cambiarEstado($tramiteId, 'OBSERVADO');

            return $observacionId;
        });

        return ['observacion_id' => $observacionId, 'desde_despacho' => $desdeDespacho];
    }

    /**
     * Subsana una observación con un archivo nuevo: versiona el expediente y, si no quedan
     * observaciones pendientes, el trámite vuelve a revisión. Devuelve true si reingresó a revisión.
     */
    public function subsanarObservacion(User $usuario, int $observacionId, UploadedFile $archivo): bool
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

        $ruta = $this->archivos->guardarSubido($archivo, "expedientes/{$observacion->codigo}/subsanaciones")
            ?? throw new ReglaNegocioException('No se pudo guardar el archivo de subsanación.', 500);

        try {
            return $this->transaccion->ejecutar(function () use ($observacion, $usuario, $ruta) {
                if ($observacion->tramite_documento_id) {
                    $this->tramites->actualizarDocumento($observacion->tramite_documento_id, ['archivo_ruta' => $ruta]);
                }
                // trg_expediente_version crea la nueva versión vigente
                $this->expedientes->crear([
                    'tramite_id' => $observacion->tramite_id,
                    'ruta_pdf' => $ruta,
                    'total_fojas' => max(1, (int) $observacion->total_fojas),
                    'hash_sha256' => hash('sha256', $observacion->codigo.'|'.$ruta.'|'.microtime(true)),
                    'compilado_por' => $usuario->id,
                ]);
                $this->tramites->marcarObservacionSubsanada($observacion->id);

                if ($this->tramites->tieneObservacionesPendientes($observacion->tramite_id)) {
                    return false;
                }
                $this->tramites->cambiarEstado($observacion->tramite_id, 'EN_REVISION', soloSiEstado: 'OBSERVADO');

                return true;
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
     * PostgreSQL valida requisitos, firmas precedentes, valorados y expediente compilado antes de aceptar el cambio.
     */
    public function enviarADespacho(int $tramiteId): void
    {
        $tramite = $this->obtener($tramiteId);
        if ($tramite->estado !== 'EN_REVISION') {
            throw new ReglaNegocioException('Solo los trámites en revisión pueden enviarse a despacho.');
        }

        $this->tramites->cambiarEstado($tramiteId, 'EN_BANDEJA_DESPACHO');
    }

    /**
     * Después de la firma: EXTERIOR se deriva a Rectorado; NACIONAL concluye con el certificado oficial vigente.
     *
     * @return array{estado: string, mensaje: string}
     */
    public function enrutarPosteriorFirma(int $tramiteId): array
    {
        $tramite = $this->obtener($tramiteId);
        if ($tramite->estado !== 'FIRMADO_DIGITALMENTE') {
            throw new ReglaNegocioException('El trámite debe tener una firma digital válida antes de continuar el flujo.');
        }

        if ($tramite->destino === 'EXTERIOR') {
            $estado = 'DERIVADO_RECTORADO';
            $mensaje = 'El expediente fue derivado a Rectorado para el sello institucional previo a Apostilla.';
        } else {
            if (! $this->tramites->tieneCertificadoOficialVigente($tramiteId)) {
                throw new ReglaNegocioException('Emita primero el certificado oficial con QR para concluir la ruta nacional.');
            }
            $estado = 'CONCLUIDO';
            $mensaje = 'La ruta nacional concluyó con el certificado oficial vigente.';
        }

        $this->tramites->cambiarEstado($tramiteId, $estado);

        return ['estado' => $estado, 'mensaje' => $mensaje];
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
