<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Repositories\ArchivoRepository;
use App\Repositories\ExpedienteRepository;
use App\Repositories\FirmaRepository;
use App\Repositories\TramiteRepository;

/**
 * Legajo PDF único del trámite (RF-11): carátula + documentos con foliación continua.
 * Se compila con el microservicio la primera vez que se previsualiza o firma cada versión del expediente,
 * y queda registrado como expediente.ruta_pdf con su hash SHA-256 real.
 */
class LegajoService
{
    private const PREFIJO_LEGAJO = 'legajo_v';

    public function __construct(
        private FirmadorService $firmador,
        private TramiteRepository $tramites,
        private ExpedienteRepository $expedientes,
        private FirmaRepository $firmas,
        private ArchivoRepository $archivos,
    ) {}

    /**
     * Expediente vigente con la ruta absoluta de su legajo compilado (lo compila si aún no existe).
     *
     * @return array{expediente: object, ruta: string}
     */
    public function legajoVigente(int $tramiteId): array
    {
        $expediente = $this->expedientes->vigente($tramiteId)
            ?? throw new ReglaNegocioException('El trámite no tiene un expediente compilado vigente.');

        $rutaActual = $this->archivos->rutaEnDisco($expediente->ruta_pdf);
        if ($rutaActual && str_starts_with(basename($rutaActual), self::PREFIJO_LEGAJO)) {
            return ['expediente' => $expediente, 'ruta' => $rutaActual];
        }

        return $this->compilar($tramiteId, $expediente);
    }

    /**
     * PDF más reciente del trámite: sellado por Rectorado > firmado > legajo sin firmar.
     *
     * @return array{ruta: string, etapa: string}
     */
    public function documentoParaVisualizar(int $tramiteId): array
    {
        if ($ruta = $this->archivos->rutaEnDisco($this->firmas->visadoDe($tramiteId)?->ruta_pdf_sellado)) {
            return ['ruta' => $ruta, 'etapa' => 'SELLADO_RECTORADO'];
        }
        if ($ruta = $this->archivos->rutaEnDisco($this->firmas->firmaVigente($tramiteId)?->ruta_pdf_firmado)) {
            return ['ruta' => $ruta, 'etapa' => 'FIRMADO'];
        }

        return ['ruta' => $this->legajoVigente($tramiteId)['ruta'], 'etapa' => 'LEGAJO'];
    }

    /**
     * @return array{expediente: object, ruta: string}
     */
    private function compilar(int $tramiteId, object $expediente): array
    {
        $tramite = $this->tramites->datosCaratula($tramiteId);
        $documentos = $this->tramites->documentosParaLegajo($tramiteId);

        $rutas = $documentos->pluck('archivo_ruta')->push($expediente->ruta_pdf)
            ->map(fn ($ruta) => $this->archivos->rutaEnDisco($ruta))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $legajo = $this->firmador->compilarLegajo([
            'codigo' => $tramite->codigo,
            'tipo' => $tramite->tipo,
            'destino' => $tramite->destino,
            'paisDestino' => $tramite->pais_destino,
            'estudiante' => $tramite->estudiante,
            'ci' => $tramite->ci,
            'registro' => $tramite->registro,
            'carrera' => $tramite->carrera,
            'version' => $expediente->version,
            'documentos' => $documentos->map(fn ($documento) => [
                'nombre' => $documento->nombre,
                'folioInicio' => $documento->folio_inicio,
                'folioFin' => $documento->folio_fin,
                'nroValorado' => $documento->nro_valorado,
                'archivo' => $documento->archivo_ruta,
            ])->values()->all(),
        ], $rutas);

        $rutaPublica = $this->archivos->guardarContenido(
            "expedientes/{$tramite->codigo}",
            self::PREFIJO_LEGAJO.$expediente->version.'.pdf',
            $legajo['pdf']
        );
        $expediente = $this->expedientes->registrarLegajoCompilado($expediente->id, $rutaPublica, hash('sha256', $legajo['pdf']));

        return ['expediente' => $expediente, 'ruta' => $this->archivos->rutaEnDisco($rutaPublica)];
    }
}
