<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Repositories\ArchivoRepository;
use App\Repositories\ExpedienteRepository;
use App\Repositories\TramiteRepository;

/**
 * Legajo PDF único del trámite: carátula + documentos firmados en origen, con foliación continua
 * (fn_asignar_folios) y huella SHA-256. Cada compilación registra una nueva versión vigente del expediente.
 */
class LegajoService
{
    public function __construct(
        private FirmadorService $firmador,
        private TramiteRepository $tramites,
        private ExpedienteRepository $expedientes,
        private ArchivoRepository $archivos,
    ) {}

    /**
     * Ensambla el legajo con el microservicio y lo registra como nueva versión vigente del expediente.
     * Debe ejecutarse dentro de una transacción (la foliación y la versión se confirman juntas).
     */
    public function compilar(int $tramiteId, int $usuarioId): object
    {
        $this->tramites->asignarFolios($tramiteId);
        $tramite = $this->tramites->datosCaratula($tramiteId)
            ?? throw ReglaNegocioException::noEncontrado('El trámite no existe.');
        $documentos = $this->tramites->documentosParaLegajo($tramiteId);
        $anterior = $this->expedientes->vigente($tramiteId);

        // Documentos firmados en origen; en un descuento de diplomado, el respaldo adjunto por el estudiante
        $rutas = $documentos->pluck('archivo_ruta')
            ->when($documentos->isEmpty() && $anterior, fn ($coleccion) => $coleccion->push($anterior->ruta_pdf))
            ->map(fn (?string $ruta) => $this->archivos->rutaEnDisco($ruta))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($rutas === []) {
            throw new ReglaNegocioException('El trámite no tiene documentos digitales para compilar el expediente.');
        }

        $version = (int) ($anterior->version ?? 0) + 1;
        $legajo = $this->firmador->compilarLegajo([
            'codigo' => $tramite->codigo,
            'tipo' => $tramite->tipo,
            'destino' => $tramite->destino ?? 'NACIONAL',
            'paisDestino' => $tramite->pais_destino,
            'estudiante' => $tramite->estudiante,
            'ci' => $tramite->ci,
            'registro' => $tramite->registro,
            'carrera' => $tramite->carrera,
            'version' => $version,
            'documentos' => $documentos->map(fn (object $documento) => [
                'nombre' => $documento->nombre,
                'folioInicio' => $documento->folio_inicio,
                'folioFin' => $documento->folio_fin,
                'nroValorado' => null,
                'archivo' => $documento->archivo_ruta,
            ])->values()->all(),
        ], $rutas);

        $ruta = $this->archivos->guardarContenido("tramites/{$tramite->codigo}", "legajo_v{$version}.pdf", $legajo['pdf']);

        return $this->expedientes->crearVersion([
            'tramite_id' => $tramiteId,
            'ruta_pdf' => $ruta,
            'total_fojas' => max(1, $legajo['totalFojas'] ?: (int) $tramite->total_fojas),
            'hash_sha256' => hash('sha256', $legajo['pdf']),
            'compilado_por' => $usuarioId,
        ]);
    }

    /**
     * Expediente vigente con la ruta absoluta de su PDF.
     *
     * @return array{expediente: object, ruta: string}
     */
    public function legajoVigente(int $tramiteId): array
    {
        $expediente = $this->expedientes->vigente($tramiteId)
            ?? throw new ReglaNegocioException('El trámite no tiene un expediente compilado vigente.');
        $ruta = $this->archivos->rutaEnDisco($expediente->ruta_pdf)
            ?? throw new ReglaNegocioException('El PDF del expediente vigente no está disponible en el servidor.');

        return ['expediente' => $expediente, 'ruta' => $ruta];
    }

    /**
     * PDF que se muestra en la bandeja: por ahora el expediente vigente (las firmas de despacho
     * y de Rectorado se integran en la fase 3).
     *
     * @return array{ruta: string, etapa: string}
     */
    public function documentoParaVisualizar(int $tramiteId): array
    {
        return ['ruta' => $this->legajoVigente($tramiteId)['ruta'], 'etapa' => 'LEGAJO'];
    }
}
