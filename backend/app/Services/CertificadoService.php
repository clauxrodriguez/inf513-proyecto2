<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Repositories\ArchivoRepository;
use App\Repositories\CertificadoRepository;
use App\Repositories\FirmaRepository;
use App\Repositories\TramiteRepository;
use App\Repositories\UnidadDeTrabajo;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * CU6 - Emisión del certificado oficial / constancia con QR (RF-14), anulación definitiva (SF-3)
 * y entrega de la constancia de descuento en ventanilla (SF-1, Sección III).
 */
class CertificadoService
{
    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private CertificadoRepository $certificados,
        private TramiteRepository $tramites,
        private FirmaRepository $firmas,
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
        $firmas = $this->firmas->firmasVigentesDe($listos->pluck('id'));
        $listos->transform(function ($tramite) use ($firmas) {
            $tramite->firma = $firmas->get($tramite->id);
            $tramite->es_reemision = $tramite->estado === 'CONCLUIDO';

            return $tramite;
        });

        return ['listos' => $listos, 'emitidos' => $this->certificados->emitidos()];
    }

    /**
     * Genera el PDF/A con QR (código UUID + hash SHA-256 del expediente firmado), lo registra VIGENTE
     * y concluye el trámite (detiene el SLA; el trigger de estado notifica al estudiante).
     */
    public function emitir(int $tramiteId): object
    {
        $tramite = $this->tramites->buscarDetalle($tramiteId) ?? throw ReglaNegocioException::noEncontrado('El trámite no existe.');
        if (! $this->estaListo($tramite)) {
            throw new ReglaNegocioException("El trámite {$tramite->codigo} no está listo para emitir el certificado (estado {$tramite->estado}).");
        }
        $firma = $this->firmas->firmaVigente($tramiteId)
            ?? throw new ReglaNegocioException('El trámite no tiene firma digital válida para emitir el certificado.');

        // El QR vincula el expediente firmado (o sellado por Rectorado): su hash permite detectar alteraciones
        $expedienteFirmado = $this->legajos->documentoParaVisualizar($tramiteId);
        if ($expedienteFirmado['etapa'] === 'LEGAJO') {
            throw new ReglaNegocioException('No se encontró el expediente firmado digitalmente del trámite.');
        }
        $hashExpediente = hash('sha256', $this->archivos->leer($expedienteFirmado['ruta']));
        $codigoVerificacion = (string) Str::uuid();
        $numero = $this->certificados->reservarNumero($tramiteId);
        $qr = rtrim(config('app.frontend_url'), '/').'/validar-certificado?'
            .http_build_query(['codigo' => $codigoVerificacion, 'hash' => $hashExpediente]);

        $descuento = $this->tramites->descuentosDe([$tramiteId])->get($tramiteId);
        $documento = $this->firmador->generarCertificadoOficial([
            'numero' => $numero,
            'tipo' => $tramite->tipo,
            'codigoTramite' => $tramite->codigo,
            'titular' => $tramite->estudiante_nombre,
            'ci' => $tramite->estudiante_ci,
            'registro' => $tramite->registro_universitario,
            'carrera' => $tramite->carrera,
            'destino' => $tramite->destino,
            'paisDestino' => $tramite->pais_destino,
            'institucionDestino' => $tramite->institucion_destino,
            'fechaEmision' => now()->format('d/m/Y H:i'),
            'fechaFirma' => Carbon::parse($firma->fecha_firma)->format('d/m/Y H:i'),
            'leyendaFirma' => $this->certificados->leyendaFirma($firma->id),
            'codigoVerificacion' => $codigoVerificacion,
            'hashExpediente' => $hashExpediente,
            'qrContenido' => $qr,
            'totalFojas' => $tramite->total_fojas,
            'documentos' => $this->tramites->documentosParaLegajo($tramiteId)->map(fn ($doc) => [
                'nombre' => $doc->nombre,
                'folioInicio' => $doc->folio_inicio,
                'folioFin' => $doc->folio_fin,
            ])->values()->all(),
            'descuento' => $descuento ? [
                'nombreDiplomado' => $descuento->nombre_diplomado,
                'unidadPostgrado' => $descuento->unidad_postgrado,
                'gestion' => $descuento->gestion_diplomado,
                'financiamiento' => $descuento->financiamiento,
            ] : null,
        ]);
        $ruta = $this->archivos->guardarContenido("expedientes/{$tramite->codigo}", "certificado_{$numero}.pdf", $documento['pdfFirmado']);

        try {
            $certificadoId = $this->transaccion->ejecutar(function () use ($tramite, $firma, $numero, $ruta, $hashExpediente, $codigoVerificacion, $qr) {
                $certificadoId = $this->certificados->crear([
                    'tramite_id' => $tramite->id,
                    'firma_id' => $firma->id,
                    'numero' => $numero,
                    'ruta_pdfa' => $ruta,
                    'hash_sha256' => $hashExpediente,
                    'codigo_verificacion' => $codigoVerificacion,
                    'qr_contenido' => $qr,
                ]);
                if ($tramite->estado !== 'CONCLUIDO') {
                    $this->tramites->cambiarEstado($tramite->id, 'CONCLUIDO');
                }

                return $certificadoId;
            });
        } catch (Throwable $e) {
            $this->archivos->eliminar($ruta);
            throw $e;
        }

        return $this->certificados->buscar($certificadoId);
    }

    /**
     * SF-3: anulación definitiva con motivo (el trigger la hace irreversible y notifica al solicitante).
     */
    public function anular(int $certificadoId, string $motivo): object
    {
        $certificado = $this->obtener($certificadoId);
        if ($certificado->estado === 'ANULADO') {
            throw new ReglaNegocioException("El certificado {$certificado->numero} ya está ANULADO: la anulación es definitiva.");
        }
        $this->certificados->anular($certificadoId, trim($motivo));

        return $this->certificados->buscar($certificadoId);
    }

    /**
     * SF-1: registra por única vez el formulario entregado en ventanilla (constancia de descuento, Sección III).
     */
    public function registrarEntregaConstancia(int $certificadoId, string $nroFormulario): void
    {
        $certificado = $this->obtener($certificadoId);
        if ($certificado->tipo !== 'CONSTANCIA_DESCUENTO') {
            throw new ReglaNegocioException('La entrega de la Sección III solo aplica a constancias de descuento.');
        }
        if ($certificado->estado !== 'VIGENTE') {
            throw new ReglaNegocioException('Solo se entrega una constancia VIGENTE.');
        }
        if ($certificado->fecha_entrega) {
            throw new ReglaNegocioException("La constancia ya se entregó el {$certificado->fecha_entrega}: la entrega no se modifica.");
        }
        $this->certificados->registrarEntregaConstancia($certificado->tramite_id, trim($nroFormulario), now()->toDateString());
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

    private function estaListo(object $tramite): bool
    {
        return $this->certificados->listosParaEmision()->contains('id', $tramite->id);
    }

    private function obtener(int $certificadoId): object
    {
        return $this->certificados->buscar($certificadoId) ?? throw ReglaNegocioException::noEncontrado('El certificado no existe.');
    }
}
