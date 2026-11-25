<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\User;
use App\Repositories\ArchivoRepository;
use App\Repositories\CatalogoRepository;
use App\Repositories\ExpedienteRepository;
use App\Repositories\OrigenRepository;
use App\Repositories\TramiteRepository;
use App\Repositories\UnidadDeTrabajo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Solicitud de trámites por el estudiante y operaciones de Ventanilla: recibos de caja,
 * verificación criptográfica de las firmas de origen y compilación del expediente.
 */
class TramiteService
{
    /** Estados en los que Ventanilla puede registrar recibos y compilar el expediente. */
    private const ESTADOS_VENTANILLA = ['INGRESADO', 'EN_REVISION', 'OBSERVADO'];

    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private TramiteRepository $tramites,
        private CatalogoRepository $catalogos,
        private ExpedienteRepository $expedientes,
        private OrigenRepository $origen,
        private ArchivoRepository $archivos,
        private LegajoService $legajos,
        private FirmadorService $firmador,
    ) {}

    /**
     * @return array{documentos: Collection, requisitos: Collection, carreras: Collection}
     */
    public function catalogos(User $usuario): array
    {
        $firmas = $this->catalogos->firmasPrecedentes();
        $documentos = $this->catalogos->documentosActivos()->transform(function (object $documento) use ($firmas): object {
            $documento->firmas_precedentes = $firmas->get($documento->id, collect())->values();

            return $documento;
        });

        return [
            'documentos' => $documentos,
            'requisitos' => $this->catalogos->requisitos(),
            'carreras' => $this->catalogos->carrerasDeUsuario($usuario->id),
        ];
    }

    /**
     * El estudiante solo ve sus propios trámites; el personal, todos (filtrables por estado).
     *
     * @param  list<string>  $estados
     */
    public function listar(User $usuario, array $estados = []): Collection
    {
        $tramites = $this->tramites->listar($usuario->rol === 'ESTUDIANTE' ? $usuario->id : null, $estados);
        $ids = $tramites->pluck('id');

        $documentos = $this->tramites->documentosDe($ids);
        $descuentos = $this->tramites->descuentosDe($ids);
        $expedientes = $this->expedientes->vigentesDe($ids);

        return $tramites->transform(function (object $tramite) use ($documentos, $descuentos, $expedientes): object {
            $tramite->documentos = $documentos->get($tramite->id, collect())->values();
            $tramite->descuento = $descuentos->get($tramite->id);
            $tramite->expediente = $expedientes->get($tramite->id);

            return $tramite;
        });
    }

    /**
     * Registra la solicitud del estudiante. Una legalización queda en SOLICITADO_ORIGEN hasta que las
     * unidades emitan y firmen sus documentos; un descuento de diplomado (sin documentos que firmar
     * en origen) ingresa directamente a Ventanilla con el respaldo adjunto.
     *
     * @param  array<string, mixed>  $datos
     */
    public function registrar(User $usuario, array $datos, ?UploadedFile $respaldo): object
    {
        $carreras = $this->catalogos->carrerasDeUsuario($usuario->id);
        $carreraId = isset($datos['carrera_id']) ? (int) $datos['carrera_id'] : $carreras->first()?->id;
        if (! $carreraId || ! $carreras->contains('id', $carreraId)) {
            throw new ReglaNegocioException('Seleccione una de las carreras en las que está inscrito.');
        }
        $esLegalizacion = $datos['tipo'] === 'LEGALIZACION';
        if ($esLegalizacion) {
            $this->validarDocumentosSolicitados($datos['documentos']);
        }
        $rutaRespaldo = null;

        try {
            return $this->transaccion->ejecutar(function () use ($usuario, $datos, $respaldo, $carreraId, $esLegalizacion, &$rutaRespaldo): object {
                // Los triggers generan el código (LEG-AAAA-000001), asignan los requisitos y exigen solicitante ESTUDIANTE
                $tramiteId = $this->tramites->crear([
                    'tipo_tramite_id' => $this->catalogos->tipoTramiteId($datos['tipo']),
                    'solicitante_id' => $usuario->id,
                    'carrera_id' => $carreraId,
                    'destino' => $esLegalizacion ? $datos['destino'] : null,
                    'pais_destino' => $esLegalizacion && $datos['destino'] === 'EXTERIOR' ? $datos['pais_destino'] : null,
                    'institucion_destino' => $esLegalizacion ? ($datos['institucion_destino'] ?? null) : null,
                    'prioridad' => 'NORMAL',
                    'registrado_por' => $usuario->id,
                ]);

                if ($esLegalizacion) {
                    foreach ($datos['documentos'] as $documento) {
                        $this->tramites->agregarDocumento([
                            'tramite_id' => $tramiteId,
                            'catalogo_documento_id' => (int) $documento['catalogo_documento_id'],
                            'cantidad_paginas' => (int) $documento['cantidad_paginas'],
                            'cantidad_materias' => isset($documento['cantidad_materias']) ? (int) $documento['cantidad_materias'] : null,
                        ]);
                    }

                    return $this->tramites->buscar($tramiteId);
                }

                // El trigger calcula fecha_limite, dentro_plazo y financiamiento (1 año desde la última nota)
                $this->tramites->agregarDescuento([
                    'tramite_id' => $tramiteId,
                    'unidad_postgrado' => $datos['descuento']['unidad_postgrado'],
                    'nombre_diplomado' => $datos['descuento']['nombre_diplomado'],
                    'nro_avance_academico' => $datos['descuento']['nro_avance_academico'],
                    'fecha_ultima_nota' => $datos['descuento']['fecha_ultima_nota'],
                ]);
                $tramite = $this->tramites->buscar($tramiteId);

                if ($respaldo) {
                    $rutaRespaldo = $this->archivos->guardarSubido($respaldo, "tramites/{$tramite->codigo}");
                    $this->expedientes->crearVersion([
                        'tramite_id' => $tramiteId,
                        'ruta_pdf' => $rutaRespaldo,
                        'total_fojas' => 1,
                        'hash_sha256' => hash_file('sha256', $respaldo->getRealPath()),
                        'compilado_por' => $usuario->id,
                    ]);
                }
                $this->tramites->cambiarEstado($tramiteId, 'EN_FIRMA_ORIGEN');
                $this->tramites->cambiarEstado($tramiteId, 'INGRESADO');

                return $this->tramites->buscar($tramiteId);
            });
        } catch (Throwable $e) {
            $this->archivos->eliminar($rutaRespaldo);
            throw $e;
        }
    }

    /**
     * Ventanilla registra un recibo emitido por Caja/Tesorería; el trigger actualiza importe_pagado.
     *
     * @param  array{codigo_recibo: string, concepto: string, importe: float|string, fecha_pago: string}  $datos
     */
    public function registrarComprobante(User $usuario, int $tramiteId, array $datos): object
    {
        $this->exigirEstadoVentanilla($tramiteId);

        $this->tramites->crearComprobante([
            'tramite_id' => $tramiteId,
            'codigo_recibo' => trim($datos['codigo_recibo']),
            'concepto' => $datos['concepto'],
            'importe' => $datos['importe'],
            'fecha_pago' => $datos['fecha_pago'],
            'registrado_por' => $usuario->id,
        ]);

        return $this->tramites->buscar($tramiteId);
    }

    public function eliminarComprobante(int $comprobanteId): void
    {
        $comprobante = $this->tramites->buscarComprobante($comprobanteId)
            ?? throw ReglaNegocioException::noEncontrado('El recibo no existe.');
        $this->exigirEstadoVentanilla($comprobante->tramite_id);

        $this->tramites->eliminarComprobante($comprobanteId);
    }

    /**
     * Ensambla el expediente PDF/A con foliación continua; cada compilación es una nueva versión vigente.
     */
    public function compilarExpediente(User $usuario, int $tramiteId): object
    {
        $tramite = $this->exigirEstadoVentanilla($tramiteId);
        if ($tramite->estado !== 'EN_REVISION') {
            throw new ReglaNegocioException('Inicie la revisión del trámite antes de compilar el expediente.');
        }

        return $this->transaccion->ejecutar(fn (): object => $this->legajos->compilar($tramiteId, $usuario->id));
    }

    /**
     * Verificación criptográfica (integridad y cadena de confianza) de las firmas en origen de un documento,
     * y coincidencia de su huella SHA-256 con la registrada en la última firma.
     *
     * @return array<string, mixed>
     */
    public function verificarDocumento(int $documentoId): array
    {
        $documento = $this->tramites->buscarDocumento($documentoId)
            ?? throw ReglaNegocioException::noEncontrado('El documento no existe.');
        $ruta = $this->archivos->rutaEnDisco($documento->archivo_ruta)
            ?? throw new ReglaNegocioException('El documento aún no tiene un PDF emitido.');

        $contenido = $this->archivos->leer($ruta);
        $firmasRegistradas = $this->origen->firmasDe([$documentoId])->get($documentoId, collect());
        $hashActual = hash('sha256', $contenido);

        return [
            ...$this->firmador->verificar($contenido),
            'firmas_registradas' => $firmasRegistradas->count(),
            'hash_coincide' => $firmasRegistradas->isNotEmpty() && $firmasRegistradas->last()->hash_documento === $hashActual,
            'cadena_completa' => (bool) $documento->firmas_origen_ok,
        ];
    }

    /**
     * Solo documentos activos del catálogo; los que se cobran por materia exigen la cantidad de materias.
     *
     * @param  list<array{catalogo_documento_id: int|string, cantidad_materias?: int|string|null}>  $documentos
     */
    private function validarDocumentosSolicitados(array $documentos): void
    {
        $catalogo = $this->catalogos->documentosActivos()->keyBy('id');

        foreach ($documentos as $documento) {
            $item = $catalogo->get((int) $documento['catalogo_documento_id'])
                ?? throw new ReglaNegocioException('Uno de los documentos seleccionados no está disponible para legalización.');

            if ($item->tipo_cobro === 'MATERIA' && empty($documento['cantidad_materias'])) {
                throw new ReglaNegocioException("Indique la cantidad de materias para «{$item->nombre}».");
            }
        }
    }

    private function exigirEstadoVentanilla(int $tramiteId): object
    {
        $tramite = $this->tramites->buscar($tramiteId) ?? throw ReglaNegocioException::noEncontrado('El trámite no existe.');
        if (! in_array($tramite->estado, self::ESTADOS_VENTANILLA, true)) {
            throw new ReglaNegocioException("El trámite {$tramite->codigo} no está en Ventanilla (estado {$tramite->estado}).");
        }

        return $tramite;
    }
}
