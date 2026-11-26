<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\User;
use App\Repositories\ArchivoRepository;
use App\Repositories\CatalogoRepository;
use App\Repositories\DelegacionRepository;
use App\Repositories\FirmaRepository;
use App\Repositories\NotificacionRepository;
use App\Repositories\OrigenRepository;
use App\Repositories\TramiteRepository;
use App\Repositories\UnidadDeTrabajo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Origen facultativo: la unidad emisora carga el documento y cada autoridad de la cadena
 * (catalogo_firma_orden) lo firma digitalmente (PAdES incremental) en su turno.
 * Al completarse la cadena de todos los documentos, el trámite ingresa a Ventanilla (INGRESADO).
 */
class OrigenService
{
    /**
     * Unidad de origen que representa cada rol firmante.
     *
     * @var array<string, string>
     */
    public const UNIDAD_POR_ROL = [
        'DIRECTOR_CARRERA' => 'CARRERA',
        'DECANO' => 'DECANATO',
        'ADMISIONES' => 'ADMISIONES',
        'DAGA' => 'DAGA',
        'VICERRECTOR' => 'VICERRECTORADO',
    ];

    /** @var array<string, string> */
    private const LEYENDA_POR_ROL = [
        'DIRECTOR_CARRERA' => 'Director/a de Carrera',
        'DECANO' => 'Decano/a',
        'ADMISIONES' => 'Jefe/a de Admisiones y Registro',
        'DAGA' => 'Jefe/a de DAGA / DICA',
        'VICERRECTOR' => 'Vicerrector/a',
    ];

    private const UBICACION_CAMPUS = 'Santa Cruz de la Sierra, Bolivia (UAGRM)';

    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private OrigenRepository $origen,
        private TramiteRepository $tramites,
        private CatalogoRepository $catalogos,
        private DelegacionRepository $delegaciones,
        private FirmaRepository $firmas,
        private ArchivoRepository $archivos,
        private NotificacionRepository $notificaciones,
        private FirmadorService $firmador,
    ) {}

    /**
     * Documentos cuya firma pendiente corresponde a la unidad del usuario, con las firmas ya realizadas.
     *
     * @return array{habilitacion: array<string, mixed>, documentos: Collection}
     */
    public function bandeja(User $usuario): array
    {
        $documentos = $this->origen->documentosPendientes($this->alcance($usuario));
        $firmas = $this->origen->firmasDe($documentos->pluck('id'));

        $documentos->transform(function (object $documento) use ($firmas): object {
            $documento->firmas = $firmas->get($documento->id, collect())->values();
            $documento->es_emisor = (int) $documento->orden_pendiente === 1;

            return $documento;
        });

        return ['habilitacion' => $this->habilitacion($usuario), 'documentos' => $documentos];
    }

    /**
     * La unidad emisora (primera de la cadena) carga el PDF del documento antes de firmarlo.
     * Calcula automáticamente la cantidad de páginas del PDF y actualiza el trámite.
     */
    public function emitir(User $usuario, int $documentoId, UploadedFile $archivo): string
    {
        $documento = $this->documentoAsignado($usuario, $documentoId);
        if ((int) $documento->orden_pendiente !== 1) {
            throw new ReglaNegocioException('El documento ya tiene firmas en origen: no puede reemplazarse el archivo emitido.');
        }

        $paginas = $this->archivos->contarPaginasPdf($archivo->getRealPath());
        $ruta = $this->archivos->guardarSubido($archivo, "tramites/{$documento->tramite_codigo}/origen")
            ?? throw new ReglaNegocioException('No se pudo guardar el documento emitido.', 500);

        try {
            $this->transaccion->ejecutar(function () use ($documento, $ruta, $paginas): void {
                $this->tramites->actualizarDocumento($documento->id, [
                    'archivo_ruta' => $ruta,
                    'cantidad_paginas' => $paginas,
                    'estado' => 'EN_FIRMA',
                ]);
                $this->tramites->cambiarEstado($documento->tramite_id, 'EN_FIRMA_ORIGEN', soloSiEstado: 'SOLICITADO_ORIGEN');
            });
        } catch (Throwable $e) {
            $this->archivos->eliminar($ruta);
            throw $e;
        }

        $this->archivos->eliminar($documento->archivo_ruta);

        return $documento->documento_nombre;
    }

    /**
     * Documentos que esperan la firma de la unidad del usuario y ya tienen su PDF emitido.
     *
     * @param  list<int>  $documentoIds
     * @return Collection<int, object>
     */
    public function documentosParaFirmar(User $usuario, array $documentoIds): Collection
    {
        $documentos = $this->origen->documentosPendientes([...$this->alcance($usuario), 'documentos' => $documentoIds])->keyBy('id');
        if (array_diff($documentoIds, $documentos->keys()->all()) !== []) {
            throw new ReglaNegocioException('Uno o más documentos seleccionados no esperan la firma de su unidad o ya fueron firmados.');
        }
        $sinArchivo = $documentos->filter(fn (object $documento): bool => ! $this->archivos->rutaEnDisco($documento->archivo_ruta));
        if ($sinArchivo->isNotEmpty()) {
            throw new ReglaNegocioException('Primero debe emitirse (cargarse) el PDF de: '.$sinArchivo->pluck('documento_nombre')->implode(', ').'.');
        }

        return $documentos;
    }

    /**
     * Modo simulación: firma en el servidor con el microservicio (el PIN viaja al backend).
     * Con Jacobitus la firma la hace el navegador y se registra con registrarFirmas().
     *
     * @param  array{documentos: list<int>, pin: string, modalidad: string, ubicacion?: ?string}  $datos
     * @return array{total: int, ingresados: list<string>}
     */
    public function firmar(User $usuario, array $datos, ?string $ipOrigen): array
    {
        $habilitacion = $this->exigirHabilitacion($usuario);
        $documentos = $this->documentosParaFirmar($usuario, $datos['documentos']);
        $esRemota = $datos['modalidad'] === 'REMOTA';
        $ubicacion = $this->ubicacion($esRemota, $datos['ubicacion'] ?? null);

        $firmados = $documentos->map(function (object $documento) use ($usuario, $habilitacion, $datos, $ubicacion): array {
            $resultado = $this->firmador->firmar($this->archivos->leer($this->archivos->rutaEnDisco($documento->archivo_ruta)), [
                'numeroSerie' => $habilitacion['certificado']['numero_serie'],
                'pin' => $datos['pin'],
                'nombre' => trim("{$usuario->nombres} {$usuario->apellidos}"),
                'razon' => "Firma en origen ({$documento->orden_pendiente}/{$documento->total_firmas}): {$documento->cargo_firmante}",
                'ubicacion' => $ubicacion,
                'contacto' => $usuario->email,
                'leyenda' => $habilitacion['leyenda'],
            ]);

            return ['documento' => $documento, 'pdf' => $resultado['pdfFirmado'], 'hash' => $resultado['hashFirmado'], 'firma' => $resultado['firmaCms']];
        })->values()->all();

        return $this->registrarFirmas($usuario, $habilitacion, $firmados, $esRemota, $ubicacion, $ipOrigen);
    }

    /**
     * Guarda cada PDF co-firmado como nueva versión del documento y, en una transacción, registra el lote,
     * una firma ORIGEN por documento (el trigger marca firmas_origen_ok al completar la cadena) y pasa a
     * Ventanilla los trámites completos. Si algo falla, se descartan los archivos guardados.
     *
     * @param  array<string, mixed>  $habilitacion
     * @param  list<array{documento: object, pdf: string, hash: string, firma: string}>  $firmados
     * @return array{total: int, ingresados: list<string>}
     */
    public function registrarFirmas(User $usuario, array $habilitacion, array $firmados, bool $esRemota, string $ubicacion, ?string $ipOrigen): array
    {
        $rutas = [];

        try {
            foreach ($firmados as $indice => $firmado) {
                $documento = $firmado['documento'];
                $rutas[$indice] = $this->archivos->guardarContenido(
                    "tramites/{$documento->tramite_codigo}/origen",
                    sprintf('%s_doc%d_firma%d_%s.pdf', $documento->documento_codigo, $documento->id, $documento->orden_pendiente, now()->format('YmdHis')),
                    $firmado['pdf'],
                );
            }

            $ingresados = $this->transaccion->ejecutar(function () use ($usuario, $habilitacion, $esRemota, $ubicacion, $ipOrigen, $firmados, $rutas): array {
                $loteId = $this->firmas->crearLote([
                    'firmante_id' => $usuario->id,
                    'certificado_id' => $habilitacion['certificado']['id'],
                    'delegacion_id' => null,
                    'es_remota' => $esRemota,
                    'ubicacion' => mb_substr($ubicacion, 0, 150),
                    'ip_origen' => $ipOrigen,
                ]);

                foreach ($firmados as $indice => ['documento' => $documento, 'hash' => $hash, 'firma' => $firma]) {
                    $this->tramites->actualizarDocumento($documento->id, ['archivo_ruta' => $rutas[$indice], 'estado' => 'EN_FIRMA']);
                    $this->tramites->cambiarEstado($documento->tramite_id, 'EN_FIRMA_ORIGEN', soloSiEstado: 'SOLICITADO_ORIGEN');
                    $this->origen->registrarFirma([
                        'lote_id' => $loteId,
                        'tramite_id' => $documento->tramite_id,
                        'tramite_documento_id' => $documento->id,
                        'orden_firma' => (int) $documento->orden_pendiente,
                        'hash_documento' => $hash,
                        'firma_valor' => $firma,
                    ]);
                }

                return $this->ingresarTramitesCompletos(collect($firmados)->pluck('documento.tramite_id')->unique());
            });
        } catch (Throwable $e) {
            foreach ($rutas as $ruta) {
                $this->archivos->eliminar($ruta);
            }
            throw $e;
        }

        return ['total' => count($firmados), 'ingresados' => $ingresados];
    }

    /**
     * @return array<string, mixed>
     */
    public function exigirHabilitacion(User $usuario): array
    {
        $habilitacion = $this->habilitacion($usuario);
        if (! $habilitacion['puede_firmar']) {
            throw new ReglaNegocioException($habilitacion['motivo']);
        }

        return $habilitacion;
    }

    public function ubicacion(bool $esRemota, ?string $ubicacion): string
    {
        return $esRemota ? trim((string) $ubicacion).' (firma remota)' : self::UBICACION_CAMPUS;
    }

    /**
     * Unidad, certificado digital vigente y leyenda del firmante en origen.
     *
     * @return array{unidad: ?string, certificado: ?array<string, mixed>, puede_firmar: bool, motivo: ?string, leyenda: ?string}
     */
    public function habilitacion(User $usuario): array
    {
        $rol = $usuario->rol;
        $unidad = self::UNIDAD_POR_ROL[$rol] ?? null;
        $certificado = $this->delegaciones->certificadoVigente($usuario->id);

        $motivo = match (true) {
            $unidad === null => 'Su rol no firma documentos en origen.',
            $certificado === null => 'No tiene un certificado digital registrado y vigente. Regístrelo desde su token o softoken de Jacobitus, o solicítelo al Rectorado.',
            default => null,
        };

        return [
            'unidad' => $unidad,
            'certificado' => $certificado ? (array) $certificado : null,
            'puede_firmar' => $motivo === null,
            // Solo falta registrar el certificado: el modal de firma permite registrarlo desde Jacobitus
            'sin_certificado' => $unidad !== null && $certificado === null,
            'motivo' => $motivo,
            'leyenda' => $unidad ? (self::LEYENDA_POR_ROL[$rol].' · UAGRM') : null,
        ];
    }

    /**
     * Pasa a INGRESADO (Ventanilla) los trámites cuyos documentos completaron todas sus firmas en origen.
     *
     * @param  Collection<int, int>  $tramiteIds
     * @return list<string> códigos de los trámites que ingresaron
     */
    private function ingresarTramitesCompletos(Collection $tramiteIds): array
    {
        $ingresados = [];

        foreach ($tramiteIds as $tramiteId) {
            if ($this->tramites->documentosFirmadosEnOrigen($tramiteId)
                && $this->tramites->cambiarEstado($tramiteId, 'INGRESADO', soloSiEstado: 'EN_FIRMA_ORIGEN') > 0) {
                $codigo = $this->tramites->buscar($tramiteId)->codigo;
                $ingresados[] = $codigo;
                $this->notificaciones->notificarRol('RECEPCION', $tramiteId, 'PENDIENTE_REVISION',
                    "El trámite {$codigo} completó sus firmas en origen e ingresó a Ventanilla.");
            }
        }

        return $ingresados;
    }

    /**
     * Documento pendiente cuya siguiente firma corresponde a la unidad (y al alcance) del usuario.
     */
    private function documentoAsignado(User $usuario, int $documentoId): object
    {
        return $this->origen->documentosPendientes([...$this->alcance($usuario), 'documentos' => [$documentoId]])->first()
            ?? throw ReglaNegocioException::noEncontrado('El documento no existe o no espera la firma de su unidad.');
    }

    /**
     * Alcance de la bandeja: el Director ve sus carreras; el Decano, sus facultades; las demás unidades, todo.
     *
     * @return array{unidad: string, carreras?: list<int>, facultades?: list<int>}
     */
    private function alcance(User $usuario): array
    {
        $unidad = self::UNIDAD_POR_ROL[$usuario->rol]
            ?? throw ReglaNegocioException::prohibido('Su rol no firma documentos en origen.');
        $carreras = $this->catalogos->carrerasDeUsuario($usuario->id);

        return match ($unidad) {
            'CARRERA' => ['unidad' => $unidad, 'carreras' => $carreras->pluck('id')->all()],
            'DECANATO' => ['unidad' => $unidad, 'facultades' => $carreras->pluck('facultad_id')->unique()->values()->all()],
            default => ['unidad' => $unidad],
        };
    }
}
