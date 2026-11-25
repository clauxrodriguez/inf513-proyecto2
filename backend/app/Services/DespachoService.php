<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\User;
use App\Repositories\ArchivoRepository;
use App\Repositories\DelegacionRepository;
use App\Repositories\ExpedienteRepository;
use App\Repositories\FirmaRepository;
use App\Repositories\NotificacionRepository;
use App\Repositories\TramiteRepository;
use App\Repositories\UnidadDeTrabajo;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * CU5 - Despacho del Vicerrectorado (firma individual, por lote o remota, del titular o del suplente)
 * y firma institucional final del Rectorado para los trámites al exterior.
 * Cada firma PAdES se agrega sobre el PDF vigente y se registra como nueva versión del expediente.
 */
class DespachoService
{
    private const UBICACION_VICERRECTORADO = 'Santa Cruz de la Sierra, Bolivia (Vicerrectorado UAGRM)';

    private const UBICACION_RECTORADO = 'Santa Cruz de la Sierra, Bolivia (Rectorado UAGRM)';

    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private FirmaRepository $firmas,
        private TramiteRepository $tramites,
        private ExpedienteRepository $expedientes,
        private DelegacionRepository $delegaciones,
        private NotificacionRepository $notificaciones,
        private ArchivoRepository $archivos,
        private LegajoService $legajos,
        private FirmadorService $firmador,
    ) {}

    /**
     * Bandeja priorizada y habilitación del firmante.
     *
     * @return array{habilitacion: array<string, mixed>, expedientes: Collection}
     */
    public function bandeja(User $usuario): array
    {
        $expedientes = $this->firmas->bandejaDespacho();
        $resumen = $this->tramites->resumenDocumentos($expedientes->pluck('tramite_id'));

        $expedientes->transform(function (object $expediente) use ($resumen): object {
            $documentos = $resumen->get($expediente->tramite_id);
            $expediente->total_documentos = (int) ($documentos->total_documentos ?? 0);
            $expediente->firmas_origen_ok = $documentos ? (bool) $documentos->firmas_origen_ok : null;

            return $expediente;
        });

        return ['habilitacion' => $this->habilitacionDespacho($usuario), 'expedientes' => $expedientes];
    }

    /**
     * @return array{ruta: string, codigo: string}
     */
    public function documentoParaVisualizar(int $tramiteId): array
    {
        return ['ruta' => $this->legajos->legajoVigente($tramiteId)['ruta'], 'codigo' => $this->obtenerTramite($tramiteId)->codigo];
    }

    /**
     * Modo simulación del Vicerrectorado: firma en el servidor (individual o por lote).
     *
     * @param  array{tramites: list<int>, pin: string, modalidad: string, ubicacion?: ?string}  $datos
     * @return array{lote_id: int, calidad: string, es_remota: bool, total: int, estados: Collection}
     */
    public function firmar(User $usuario, array $datos, ?string $ipOrigen): array
    {
        $habilitacion = $this->habilitacionPara($usuario, 'DESPACHO', exigir: true);
        $tramites = $this->tramitesParaFirmar('DESPACHO', $datos['tramites']);
        $esRemota = $datos['modalidad'] === 'REMOTA';
        $ubicacion = $this->ubicacion('DESPACHO', $esRemota, $datos['ubicacion'] ?? null);

        $loteId = $this->registrarFirmas($usuario, $habilitacion, 'DESPACHO',
            $this->firmarEnServidor($usuario, $habilitacion, $tramites, $datos['pin'], $ubicacion), $esRemota, $ubicacion, $ipOrigen);

        return [
            'lote_id' => $loteId,
            'calidad' => $habilitacion['calidad'],
            'es_remota' => $esRemota,
            'total' => $tramites->count(),
            'estados' => $this->tramites->buscarVarios($datos['tramites'])->pluck('estado', 'codigo'),
        ];
    }

    /**
     * Trámites al exterior derivados a Rectorado, con la firma del Vicerrectorado y la de Rectorado si ya existe.
     *
     * @return array{habilitacion: array<string, mixed>, expedientes: Collection}
     */
    public function bandejaRectorado(User $usuario): array
    {
        $tramites = $this->firmas->bandejaRectorado();
        $firmas = $this->firmas->firmasDe($tramites->pluck('id'));

        $tramites->transform(function (object $tramite) use ($firmas): object {
            $tramite->firma_despacho = $firmas->get($tramite->id)?->get('DESPACHO');
            $tramite->firma_rectorado = $firmas->get($tramite->id)?->get('RECTORADO');

            return $tramite;
        });

        return ['habilitacion' => $this->habilitacionRectorado($usuario), 'expedientes' => $tramites];
    }

    /**
     * Modo simulación del Rectorado: firma institucional final en el servidor.
     *
     * @param  array{pin: string}  $datos
     */
    public function firmarRectorado(User $usuario, int $tramiteId, array $datos, ?string $ipOrigen): string
    {
        $habilitacion = $this->habilitacionPara($usuario, 'RECTORADO', exigir: true);
        $tramites = $this->tramitesParaFirmar('RECTORADO', [$tramiteId]);
        $ubicacion = $this->ubicacion('RECTORADO', false, null);

        $this->registrarFirmas($usuario, $habilitacion, 'RECTORADO',
            $this->firmarEnServidor($usuario, $habilitacion, $tramites, $datos['pin'], $ubicacion), false, $ubicacion, $ipOrigen);

        return $tramites->first()->codigo;
    }

    /**
     * Trámites que pueden firmarse en la etapa: DESPACHO (en bandeja) o RECTORADO (derivados y aún sin esa firma).
     *
     * @param  list<int>  $tramiteIds
     * @return Collection<int, object>
     */
    public function tramitesParaFirmar(string $etapa, array $tramiteIds): Collection
    {
        $tramites = $this->tramites->buscarVarios($tramiteIds);
        if ($tramites->count() !== count($tramiteIds)) {
            throw ReglaNegocioException::noEncontrado('Uno de los trámites seleccionados no existe.');
        }

        $estado = $etapa === 'RECTORADO' ? 'DERIVADO_RECTORADO' : 'EN_BANDEJA_DESPACHO';
        $fuera = $tramites->where('estado', '<>', $estado)->pluck('codigo');
        if ($fuera->isNotEmpty()) {
            throw new ReglaNegocioException($etapa === 'RECTORADO'
                ? 'Solo se firman en Rectorado los trámites derivados al exterior: '.$fuera->implode(', ').'.'
                : 'Solo se firman expedientes en bandeja de despacho: '.$fuera->implode(', ').'.');
        }
        if ($etapa === 'RECTORADO') {
            $firmados = $tramites->filter(fn (object $t) => $this->firmas->ultimaFirma($t->id, 'RECTORADO'))->pluck('codigo');
            if ($firmados->isNotEmpty()) {
                throw new ReglaNegocioException('Ya tienen la firma del Rectorado: '.$firmados->implode(', ').'.');
            }
        }

        return $tramites->sortBy(fn (object $t) => array_search($t->id, $tramiteIds, true))->values();
    }

    /**
     * Registra el lote, una nueva versión del expediente con el PDF firmado y la firma de la etapa por trámite,
     * y avanza el flujo: DESPACHO deja FIRMADO_DIGITALMENTE (EXTERIOR se deriva a Rectorado); RECTORADO
     * deja el trámite listo para el certificado. Si algo falla, se descartan los PDF guardados.
     *
     * @param  array<string, mixed>  $habilitacion
     * @param  list<array{tramite: object, pdf: string, hash: string, firma: string}>  $firmados
     */
    public function registrarFirmas(User $usuario, array $habilitacion, string $etapa, array $firmados,
        bool $esRemota, string $ubicacion, ?string $ipOrigen): int
    {
        $rutas = [];

        try {
            foreach ($firmados as $indice => $firmado) {
                $version = $this->legajos->legajoVigente($firmado['tramite']->id)['expediente']->version;
                $rutas[$indice] = $this->archivos->guardarContenido(
                    "tramites/{$firmado['tramite']->codigo}",
                    sprintf('legajo_v%d_firma_%s_%s.pdf', $version, strtolower($etapa), now()->format('YmdHis')),
                    $firmado['pdf'],
                );
            }

            return $this->transaccion->ejecutar(function () use ($usuario, $habilitacion, $etapa, $firmados, $rutas, $esRemota, $ubicacion, $ipOrigen): int {
                $loteId = $this->firmas->crearLote([
                    'firmante_id' => $usuario->id,
                    'certificado_id' => $habilitacion['certificado']['id'],
                    'delegacion_id' => $habilitacion['delegacion']['id'] ?? null,
                    'es_remota' => $esRemota,
                    'ubicacion' => mb_substr($ubicacion, 0, 150),
                    'ip_origen' => $ipOrigen,
                ]);

                foreach ($firmados as $indice => ['tramite' => $tramite, 'hash' => $hash, 'firma' => $firma]) {
                    $anterior = $this->legajos->legajoVigente($tramite->id)['expediente'];
                    $version = $this->expedientes->crearVersion([
                        'tramite_id' => $tramite->id,
                        'ruta_pdf' => $rutas[$indice],
                        'total_fojas' => $anterior->total_fojas,
                        'hash_sha256' => $hash,
                        'compilado_por' => $usuario->id,
                    ]);
                    $this->firmas->registrarFirma([
                        'lote_id' => $loteId,
                        'tramite_id' => $tramite->id,
                        'expediente_id' => $version->id,
                        'etapa' => $etapa,
                        'hash_documento' => $hash,
                        'firma_valor' => $firma,
                    ]);
                    $this->avanzarFlujo($tramite, $etapa);
                }

                return $loteId;
            });
        } catch (Throwable $e) {
            foreach ($rutas as $ruta) {
                $this->archivos->eliminar($ruta);
            }
            throw $e;
        }
    }

    /**
     * Verificación criptográfica de todas las firmas del PDF vigente del trámite.
     *
     * @return array<string, mixed>
     */
    public function verificar(int $tramiteId): array
    {
        return $this->firmador->verificar($this->archivos->leer($this->legajos->legajoVigente($tramiteId)['ruta']));
    }

    /**
     * @return array<string, mixed>
     */
    public function habilitacionPara(User $usuario, string $etapa, bool $exigir = false): array
    {
        $habilitacion = $etapa === 'RECTORADO' ? $this->habilitacionRectorado($usuario) : $this->habilitacionDespacho($usuario);
        if ($exigir && ! $habilitacion['puede_firmar']) {
            throw new ReglaNegocioException($habilitacion['motivo']);
        }

        return $habilitacion;
    }

    public function ubicacion(string $etapa, bool $esRemota, ?string $ubicacion): string
    {
        if ($esRemota) {
            return trim((string) $ubicacion).' (firma remota)';
        }

        return $etapa === 'RECTORADO' ? self::UBICACION_RECTORADO : self::UBICACION_VICERRECTORADO;
    }

    /**
     * Vicerrector/a titular, o Decano/a con delegación ACTIVA vigente (interino), con certificado vigente.
     *
     * @return array<string, mixed>
     */
    public function habilitacionDespacho(User $usuario): array
    {
        $calidad = null;
        $delegacion = null;
        $motivo = null;

        if ($usuario->rol === 'VICERRECTOR') {
            $calidad = 'TITULAR';
        } elseif ($delegacion = $this->delegaciones->vigenteDelSuplente($usuario->id)) {
            $calidad = 'INTERINO';
        } else {
            $motivo = 'No tiene una delegación de autoridad ACTIVA que cubra la fecha actual: no puede firmar como suplente.';
        }

        $razon = null;
        $leyenda = null;
        if ($calidad === 'TITULAR') {
            $razon = 'Vicerrector/a titular';
            $leyenda = 'Vicerrector/a · UAGRM';
        } elseif ($calidad === 'INTERINO') {
            $fecha = Carbon::parse($delegacion->fecha_resolucion)->format('d/m/Y');
            $razon = "Vicerrector/a Interino/a según Resolución N° {$delegacion->nro_resolucion} del {$fecha}";
            $leyenda = "Vicerrector/a Interino/a · Res. N° {$delegacion->nro_resolucion}";
        }

        return $this->conCertificado($usuario, $motivo, [
            'calidad' => $calidad,
            'delegacion' => $delegacion ? (array) $delegacion : null,
            'razon' => $razon,
            'leyenda' => $leyenda,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function habilitacionRectorado(User $usuario): array
    {
        $motivo = $usuario->rol === 'RECTOR' ? null : 'Solo el Rector/a firma en Rectorado.';

        return $this->conCertificado($usuario, $motivo, [
            'calidad' => 'TITULAR',
            'delegacion' => null,
            'razon' => 'Firma institucional del Rectorado previa a la Apostilla',
            'leyenda' => 'Rector/a · UAGRM',
        ]);
    }

    /**
     * @param  array<string, mixed>  $habilitacion
     * @param  Collection<int, object>  $tramites
     * @return list<array{tramite: object, pdf: string, hash: string, firma: string}>
     */
    private function firmarEnServidor(User $usuario, array $habilitacion, Collection $tramites, string $pin, string $ubicacion): array
    {
        return $tramites->map(function (object $tramite) use ($usuario, $habilitacion, $pin, $ubicacion): array {
            $resultado = $this->firmador->firmar($this->archivos->leer($this->legajos->legajoVigente($tramite->id)['ruta']), [
                'numeroSerie' => $habilitacion['certificado']['numero_serie'],
                'pin' => $pin,
                'nombre' => trim("{$usuario->nombres} {$usuario->apellidos}"),
                'razon' => $habilitacion['razon'],
                'ubicacion' => $ubicacion,
                'contacto' => $usuario->email,
                'leyenda' => $habilitacion['leyenda'],
            ]);

            return ['tramite' => $tramite, 'pdf' => $resultado['pdfFirmado'], 'hash' => $resultado['hashFirmado'], 'firma' => $resultado['firmaCms']];
        })->values()->all();
    }

    private function avanzarFlujo(object $tramite, string $etapa): void
    {
        if ($etapa === 'RECTORADO') {
            $this->notificaciones->notificarRol('RECEPCION', $tramite->id, 'LISTO_CERTIFICADO',
                "El trámite {$tramite->codigo} tiene la firma del Rectorado: emita el certificado oficial con QR.");

            return;
        }

        $this->tramites->cambiarEstado($tramite->id, 'FIRMADO_DIGITALMENTE');
        if ($tramite->destino === 'EXTERIOR') {
            $this->tramites->cambiarEstado($tramite->id, 'DERIVADO_RECTORADO');
            $this->notificaciones->notificarRol('RECTOR', $tramite->id, 'PENDIENTE_RECTORADO',
                "El trámite {$tramite->codigo} (exterior) espera la firma institucional del Rectorado.");
        } else {
            $this->notificaciones->notificarRol('RECEPCION', $tramite->id, 'LISTO_CERTIFICADO',
                "El trámite {$tramite->codigo} fue firmado por el Vicerrectorado: emita el certificado oficial con QR.");
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function conCertificado(User $usuario, ?string $motivo, array $datos): array
    {
        $certificado = $this->delegaciones->certificadoVigente($usuario->id);
        $rolHabilitado = $motivo === null;
        if (! $certificado && $rolHabilitado) {
            $motivo = 'No tiene un certificado digital registrado y vigente. Regístrelo desde su token o softoken de Jacobitus, o solicítelo al Rectorado.';
        }

        return [
            ...$datos,
            'certificado' => $certificado ? (array) $certificado : null,
            'puede_firmar' => $motivo === null,
            'sin_certificado' => $rolHabilitado && ! $certificado,
            'motivo' => $motivo,
        ];
    }

    private function obtenerTramite(int $tramiteId): object
    {
        return $this->tramites->buscar($tramiteId) ?? throw ReglaNegocioException::noEncontrado('El trámite no existe.');
    }
}
