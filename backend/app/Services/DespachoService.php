<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\User;
use App\Repositories\ArchivoRepository;
use App\Repositories\DelegacionRepository;
use App\Repositories\FirmaRepository;
use App\Repositories\TramiteRepository;
use App\Repositories\UnidadDeTrabajo;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * CU5 - Despachar, previsualizar legajos y ejecutar firma digital consolidada o remota.
 */
class DespachoService
{
    private const UBICACION_CAMPUS = 'Santa Cruz de la Sierra, Bolivia (Vicerrectorado UAGRM)';

    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private FirmaRepository $firmas,
        private TramiteRepository $tramites,
        private DelegacionRepository $delegaciones,
        private ArchivoRepository $archivos,
        private LegajoService $legajos,
        private FirmadorService $firmador,
    ) {}

    /**
     * Bandeja priorizada (RF-11) y habilitación del firmante.
     *
     * @return array{habilitacion: array<string, mixed>, expedientes: Collection}
     */
    public function bandeja(User $usuario): array
    {
        $expedientes = $this->firmas->bandejaDespacho();
        $resumen = $this->tramites->resumenDocumentos($expedientes->pluck('tramite_id'));

        $expedientes->transform(function ($expediente) use ($resumen) {
            $documentos = $resumen->get($expediente->tramite_id);
            $expediente->total_documentos = (int) ($documentos->total_documentos ?? 0);
            $expediente->firmas_verificadas = $documentos ? (bool) $documentos->firmas_verificadas : null;

            return $expediente;
        });

        return ['habilitacion' => $this->habilitacion($usuario), 'expedientes' => $expedientes];
    }

    /**
     * @return array{ruta: string, codigo: string}
     */
    public function documentoParaVisualizar(int $tramiteId): array
    {
        $tramite = $this->obtenerTramite($tramiteId);

        return ['ruta' => $this->legajos->documentoParaVisualizar($tramiteId)['ruta'], 'codigo' => $tramite->codigo];
    }

    /**
     * Firma individual (un expediente) o consolidada por lote con una sola confirmación del PIN.
     * Primero se firman los PDF en el microservicio; luego, en una transacción, fn_firmar_lote registra
     * las firmas y avanza el flujo. Si algo falla, se descartan los PDF firmados (no quedan firmas parciales).
     *
     * @param  array{tramites: array<int>, pin: string, modalidad: string, ubicacion?: ?string}  $datos
     * @return array{lote_id: int, calidad: string, es_remota: bool, total: int, estados: Collection}
     */
    public function firmar(User $usuario, array $datos, ?string $ipOrigen): array
    {
        $habilitacion = $this->habilitacion($usuario);
        if (! $habilitacion['puede_firmar']) {
            throw new ReglaNegocioException($habilitacion['motivo']);
        }

        $tramites = $this->tramites->buscarVarios($datos['tramites']);
        if ($tramites->count() !== count($datos['tramites'])) {
            throw ReglaNegocioException::noEncontrado('Uno de los trámites seleccionados no existe.');
        }
        $fueraDeBandeja = $tramites->where('estado', '<>', 'EN_BANDEJA_DESPACHO')->pluck('codigo');
        if ($fueraDeBandeja->isNotEmpty()) {
            throw new ReglaNegocioException('Solo se firman expedientes en bandeja de despacho: '.$fueraDeBandeja->implode(', ').'.');
        }

        $esRemota = $datos['modalidad'] === 'REMOTA';
        $ubicacion = $esRemota ? trim($datos['ubicacion']).' (firma remota)' : self::UBICACION_CAMPUS;
        $firmados = [];

        try {
            foreach ($datos['tramites'] as $tramiteId) {
                $tramite = $tramites->get($tramiteId);
                $legajo = $this->legajos->legajoVigente($tramiteId);
                $resultado = $this->firmador->firmar($this->archivos->leer($legajo['ruta']), [
                    'numeroSerie' => $habilitacion['certificado']['numero_serie'],
                    'pin' => $datos['pin'],
                    'nombre' => trim("{$usuario->nombres} {$usuario->apellidos}"),
                    'razon' => $habilitacion['razon'],
                    'ubicacion' => $ubicacion,
                    'contacto' => $usuario->email,
                    'leyenda' => $habilitacion['leyenda'],
                ]);
                $firmados[] = [
                    'tramite_id' => $tramiteId,
                    'firma' => $resultado['firmaCms'],
                    'hash' => $resultado['hashDocumento'],
                    'hash_firmado' => $resultado['hashFirmado'],
                    'ruta' => $this->archivos->guardarContenido(
                        "expedientes/{$tramite->codigo}",
                        sprintf('legajo_v%d_firmado_%s.pdf', $legajo['expediente']->version, now()->format('YmdHis')),
                        $resultado['pdfFirmado']
                    ),
                ];
            }

            $loteId = $this->transaccion->ejecutar(function () use ($usuario, $habilitacion, $esRemota, $ubicacion, $ipOrigen, $firmados) {
                $loteId = $this->firmas->crearLote([
                    'firmante_id' => $usuario->id,
                    'certificado_id' => $habilitacion['certificado']['id'],
                    'es_remota' => $esRemota,
                    'ubicacion' => mb_substr($ubicacion, 0, 150),
                    'ip_origen' => $ipOrigen,
                ]);
                $this->firmas->firmarLote($loteId, $firmados);

                return $loteId;
            });
        } catch (Throwable $e) {
            foreach ($firmados as $firmado) {
                $this->archivos->eliminar($firmado['ruta']);
            }
            throw $e;
        }

        $lote = $this->firmas->buscarLote($loteId);

        return [
            'lote_id' => $loteId,
            'calidad' => $lote->calidad,
            'es_remota' => (bool) $lote->es_remota,
            'total' => count($firmados),
            'estados' => $this->tramites->buscarVarios(array_column($firmados, 'tramite_id'))->pluck('estado', 'codigo'),
        ];
    }

    /**
     * SF-2: expedientes al exterior firmados por el Vicerrectorado, con su firma y visado.
     */
    public function bandejaRectorado(): Collection
    {
        $tramites = $this->firmas->bandejaRectorado();
        $ids = $tramites->pluck('id');
        $firmas = $this->firmas->firmasVigentesDe($ids);
        $visados = $this->firmas->visadosDe($ids);

        return $tramites->transform(function ($tramite) use ($firmas, $visados) {
            $tramite->firma = $firmas->get($tramite->id);
            $tramite->visado = $visados->get($tramite->id);

            return $tramite;
        });
    }

    /**
     * SF-2: sello institucional de Rectorado como firma incremental sobre el legajo firmado.
     */
    public function visar(User $usuario, int $tramiteId, ?string $observacion): string
    {
        $tramite = $this->obtenerTramite($tramiteId);
        if ($tramite->estado !== 'DERIVADO_RECTORADO') {
            throw new ReglaNegocioException('Solo se visan trámites derivados a Rectorado.');
        }
        if ($this->firmas->visadoDe($tramiteId)) {
            throw new ReglaNegocioException('El trámite ya cuenta con el visado de Rectorado.');
        }
        $rutaFirmado = $this->archivos->rutaEnDisco($this->firmas->firmaVigente($tramiteId)?->ruta_pdf_firmado)
            ?? throw new ReglaNegocioException('No se encontró el legajo firmado por el Vicerrectorado.');

        $resultado = $this->firmador->sellar($this->archivos->leer($rutaFirmado), [
            'nombre' => 'Sello Institucional UAGRM - Rectorado',
            'razon' => 'Visado institucional final de Rectorado previo a la Apostilla (Cancillería)',
            'ubicacion' => 'Santa Cruz de la Sierra, Bolivia (Rectorado UAGRM)',
            'contacto' => $usuario->email,
            'leyenda' => 'Visado por '.trim("{$usuario->nombres} {$usuario->apellidos}").' · Rectorado',
        ]);
        $ruta = $this->archivos->guardarContenido(
            "expedientes/{$tramite->codigo}",
            'legajo_sellado_rectorado_'.now()->format('YmdHis').'.pdf',
            $resultado['pdfFirmado']
        );

        try {
            $this->firmas->crearVisado([
                'tramite_id' => $tramiteId,
                'usuario_id' => $usuario->id,
                'ruta_pdf_sellado' => $ruta,
                'hash_sha256' => $resultado['hashFirmado'],
                'observacion' => $observacion,
            ]);
        } catch (Throwable $e) {
            $this->archivos->eliminar($ruta);
            throw $e;
        }

        return $tramite->codigo;
    }

    /**
     * Verificación criptográfica de las firmas del PDF vigente del trámite.
     *
     * @return array<string, mixed>
     */
    public function verificar(int $tramiteId): array
    {
        $this->obtenerTramite($tramiteId);
        $documento = $this->legajos->documentoParaVisualizar($tramiteId);
        $contenido = $this->archivos->leer($documento['ruta']);

        if ($documento['etapa'] === 'LEGAJO') {
            return ['etapa' => 'LEGAJO', 'valido' => false, 'totalFirmas' => 0, 'hashSha256' => hash('sha256', $contenido), 'firmas' => []];
        }

        return ['etapa' => $documento['etapa'], ...$this->firmador->verificar($contenido)];
    }

    /**
     * Calidad del firmante (TITULAR / INTERINO), delegación vigente y certificado digital activo.
     * Replica las validaciones de trg_lote_validar para informar antes de pedir el PIN.
     *
     * @return array{calidad: ?string, delegacion: ?array<string, mixed>, certificado: ?array<string, mixed>, puede_firmar: bool, motivo: ?string, razon: ?string, leyenda: ?string}
     */
    public function habilitacion(User $usuario): array
    {
        $calidad = null;
        $delegacion = null;
        $motivo = null;

        if ($usuario->rol === 'VICERRECTOR') {
            $calidad = 'TITULAR';
        } elseif ($delegacion = $this->delegaciones->vigenteDelSuplente($usuario->id)) {
            $calidad = 'INTERINO';
        } else {
            $motivo = 'No tiene una delegación de autoridad ACTIVA que cubra la fecha actual (CU2). No puede firmar como suplente.';
        }

        $certificado = $this->delegaciones->certificadoVigente($usuario->id);
        if (! $certificado && ! $motivo) {
            $motivo = 'No cuenta con un certificado digital activo y vigente. Solicite a Rectorado su emisión (Delegación de Autoridad).';
        }

        $razon = null;
        $leyenda = null;
        if ($calidad === 'TITULAR') {
            $razon = 'Vicerrector/a titular';
            $leyenda = 'Vicerrector/a titular · UAGRM';
        } elseif ($calidad === 'INTERINO') {
            // Anexo Delegaciones, punto 4: el interino deja constancia de la resolución que lo habilita
            $resolucion = $delegacion->tipo_resolucion === 'ICU' ? 'Resolución del ICU' : 'Resolución Rectoral';
            $fecha = Carbon::parse($delegacion->fecha_resolucion)->format('d/m/Y');
            $razon = "Vicerrector/a Interino/a según {$resolucion} N° {$delegacion->nro_resolucion} del {$fecha}";
            $leyenda = "Vicerrector/a Interino/a · Res. N° {$delegacion->nro_resolucion}";
        }

        return [
            'calidad' => $calidad,
            'delegacion' => $delegacion ? (array) $delegacion : null,
            'certificado' => $certificado ? (array) $certificado : null,
            'puede_firmar' => $motivo === null,
            'motivo' => $motivo,
            'razon' => $razon,
            'leyenda' => $leyenda,
        ];
    }

    private function obtenerTramite(int $tramiteId): object
    {
        return $this->tramites->buscar($tramiteId) ?? throw ReglaNegocioException::noEncontrado('El trámite no existe.');
    }
}
