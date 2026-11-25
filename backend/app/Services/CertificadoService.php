<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Repositories\ArchivoRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\CertificadoRepository;
use App\Repositories\FirmaRepository;
use App\Repositories\NotificacionRepository;
use App\Repositories\TramiteRepository;
use App\Repositories\UnidadDeTrabajo;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * CU6 - Emisión del certificado oficial / constancia en PDF/A con QR verificable y anulación definitiva.
 */
class CertificadoService
{
    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private CertificadoRepository $certificados,
        private TramiteRepository $tramites,
        private FirmaRepository $firmas,
        private AuditoriaRepository $auditoria,
        private NotificacionRepository $notificaciones,
        private ArchivoRepository $archivos,
        private LegajoService $legajos,
        private FirmadorService $firmador,
    ) {}

    /**
     * @return array{listos: Collection, emitidos: Collection}
     */
    public function bandeja(): array
    {
        $listos = $this->certificados->listosParaEmision();
        $firmas = $this->firmas->firmasDe($listos->pluck('id'));
        $listos->transform(function (object $tramite) use ($firmas): object {
            $tramite->firma = $firmas->get($tramite->id)?->get('DESPACHO');
            $tramite->es_reemision = $tramite->estado === 'CONCLUIDO';

            return $tramite;
        });

        return ['listos' => $listos, 'emitidos' => $this->certificados->emitidos()];
    }

    /**
     * Registra el certificado (el trigger genera número, UUID y QR con la huella del expediente firmado),
     * genera el PDF/A con esos datos y concluye el trámite (el trigger notifica al estudiante).
     */
    public function emitir(int $tramiteId): object
    {
        $tramite = $this->tramites->buscarDetalle($tramiteId) ?? throw ReglaNegocioException::noEncontrado('El trámite no existe.');
        if (! $this->certificados->listosParaEmision()->contains('id', $tramiteId)) {
            throw new ReglaNegocioException("El trámite {$tramite->codigo} no está listo para emitir el certificado (estado {$tramite->estado}).");
        }
        $firma = $this->firmas->ultimaFirma($tramiteId, 'RECTORADO') ?? $this->firmas->ultimaFirma($tramiteId, 'DESPACHO')
            ?? throw new ReglaNegocioException('El trámite no tiene una firma digital válida del Vicerrectorado.');
        $firmaDespacho = $this->firmas->ultimaFirma($tramiteId, 'DESPACHO');
        $expediente = $this->legajos->legajoVigente($tramiteId);
        $esLegalizacion = $tramite->tipo === 'LEGALIZACION';
        $ruta = null;

        try {
            return $this->transaccion->ejecutar(function () use ($tramite, $firma, $firmaDespacho, $expediente, $esLegalizacion, &$ruta): object {
                $certificado = $this->certificados->crear([
                    'tramite_id' => $tramite->id,
                    'firma_id' => $firma->id,
                    'tipo' => $esLegalizacion ? 'LEGALIZACION' : 'CONSTANCIA_DIPLOMADO',
                    'ruta_pdfa' => 'pendiente',
                    'hash_sha256' => $expediente['expediente']->hash_sha256,
                ]);

                $descuento = $this->tramites->descuentosDe([$tramite->id])->get($tramite->id);
                $documento = $this->firmador->generarCertificadoOficial([
                    'numero' => $certificado->numero,
                    'tipo' => $esLegalizacion ? 'LEGALIZACION' : 'DESCUENTO_DIPLOMADO',
                    'codigoTramite' => $tramite->codigo,
                    'titular' => $tramite->estudiante_nombre,
                    'ci' => $tramite->estudiante_ci,
                    'registro' => $tramite->registro_universitario,
                    'carrera' => $tramite->carrera,
                    'destino' => $tramite->destino ?? 'NACIONAL',
                    'paisDestino' => $tramite->pais_destino,
                    'institucionDestino' => $tramite->institucion_destino,
                    'fechaEmision' => now()->format('d/m/Y H:i'),
                    'fechaFirma' => Carbon::parse($firmaDespacho->fecha_firma)->format('d/m/Y H:i'),
                    'leyendaFirma' => $this->leyendaFirma($firmaDespacho),
                    'codigoVerificacion' => $certificado->codigo_verificacion,
                    'hashExpediente' => $certificado->hash_sha256,
                    'qrContenido' => $certificado->qr_contenido,
                    'totalFojas' => $tramite->total_fojas,
                    'documentos' => $this->tramites->documentosParaLegajo($tramite->id)->map(fn (object $doc) => [
                        'nombre' => $doc->nombre,
                        'folioInicio' => $doc->folio_inicio,
                        'folioFin' => $doc->folio_fin,
                    ])->values()->all(),
                    'descuento' => $descuento ? [
                        'nombreDiplomado' => $descuento->nombre_diplomado,
                        'unidadPostgrado' => $descuento->unidad_postgrado,
                        'gestion' => null,
                        'financiamiento' => $descuento->financiamiento,
                    ] : null,
                ]);
                $ruta = $this->archivos->guardarContenido("tramites/{$tramite->codigo}", "certificado_{$certificado->numero}.pdf", $documento['pdfFirmado']);
                $this->certificados->asignarArchivo($certificado->id, $ruta);

                if ($tramite->estado !== 'CONCLUIDO') {
                    $this->tramites->cambiarEstado($tramite->id, 'CONCLUIDO');
                } else {
                    $this->notificaciones->notificarUsuario($tramite->solicitante_id, $tramite->id, 'CERTIFICADO_REEMITIDO',
                        "Se reemitió el certificado de su trámite {$tramite->codigo}: N° {$certificado->numero}.");
                }

                return $this->certificados->buscar($certificado->id);
            });
        } catch (Throwable $e) {
            $this->archivos->eliminar($ruta);
            throw $e;
        }
    }

    /**
     * Anulación definitiva: el motivo queda en la bitácora inalterable y se notifica al solicitante.
     */
    public function anular(int $certificadoId, string $motivo): object
    {
        $certificado = $this->obtener($certificadoId);
        if ($certificado->estado === 'ANULADO') {
            throw new ReglaNegocioException("El certificado {$certificado->numero} ya está ANULADO: la anulación es definitiva.");
        }

        $this->transaccion->ejecutar(function () use ($certificado, $motivo): void {
            $this->certificados->anular($certificado->id);
            $this->auditoria->registrarEvento($certificado->tramite_id, 'ANULAR_CERTIFICADO', 'certificado_oficial', $certificado->id, [
                'numero' => $certificado->numero,
                'motivo' => trim($motivo),
            ]);
            $tramite = $this->tramites->buscar($certificado->tramite_id);
            $this->notificaciones->notificarUsuario($tramite->solicitante_id, $tramite->id, 'CERTIFICADO_ANULADO',
                "El certificado {$certificado->numero} de su trámite {$tramite->codigo} fue anulado: ".trim($motivo));
        });

        return $this->certificados->buscar($certificadoId);
    }

    /**
     * Ruta del PDF/A del certificado (impresión en ventanilla).
     *
     * @return array{ruta: string, numero: string}
     */
    public function documento(int $certificadoId): array
    {
        $certificado = $this->obtener($certificadoId);
        $ruta = $this->archivos->rutaEnDisco($certificado->ruta_pdfa)
            ?? throw ReglaNegocioException::noEncontrado('No se encontró el archivo PDF/A del certificado.');

        return ['ruta' => $ruta, 'numero' => $certificado->numero];
    }

    /**
     * Calidad del firmante del Vicerrectorado: titular, o interino con la resolución que lo habilita.
     */
    private function leyendaFirma(object $firma): string
    {
        $leyenda = $firma->delegacion_id
            ? "Firmado digitalmente por {$firma->firmante}, Vicerrector/a Interino/a según Resolución N° {$firma->nro_resolucion}"
            : "Firmado digitalmente por {$firma->firmante}, Vicerrector/a";

        return $leyenda.($firma->es_remota ? ' (firma remota)' : '');
    }

    private function obtener(int $certificadoId): object
    {
        return $this->certificados->buscar($certificadoId) ?? throw ReglaNegocioException::noEncontrado('El certificado no existe.');
    }
}
